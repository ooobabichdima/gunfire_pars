<?php

declare(strict_types=1);

namespace App\Suppliers\Gunfire;

use App\Suppliers\AbstractSupplierParser;
use Symfony\Component\DomCrawler\Crawler;

final class GunfireParser extends AbstractSupplierParser
{
    public function getSupplierCode(): string
    {
        return 'gunfire';
    }

    // ------------------------------------------------------------------
    // scanCategories — collects all top-level + sub-category URLs
    // ------------------------------------------------------------------
    public function scanCategories(): array
    {
        $this->logger->info('Scanning categories from Gunfire...');

        $html = $this->http->getHtml($this->getBaseUrl() . '/');
        if ($html === null) {
            $this->logger->error('Failed to fetch Gunfire homepage');
            return [];
        }

        $crawler = $this->createCrawler($html);
        $urls = [];

        // Primary: nav menu links
        $selectors = [
            'nav a[href*="/c/"]',
            '.menu a[href*="/c/"]',
            '.category-list a[href]',
            'a.category-link',
            '#menu a[href*="/c/"]',
            '.nav-categories a[href]',
        ];

        foreach ($selectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$urls) {
                    $href = $node->attr('href');
                    if ($href && $this->isCategoryUrl($href)) {
                        $urls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {
                continue;
            }
            if (!empty($urls)) {
                break;
            }
        }

        // Fallback: any link containing /c/ pattern
        if (empty($urls)) {
            $this->logger->debug('Primary category selectors failed, using fallback');
            try {
                $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isCategoryUrl($href)) {
                        $urls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {
                // ignore
            }
        }

        $urls = array_unique($urls);
        $this->logger->info("Found " . count($urls) . " categories");

        return array_values($urls);
    }

    // ------------------------------------------------------------------
    // scanListings — paginate through a category, collect product URLs
    // ------------------------------------------------------------------
    public function scanListings(string $categoryUrl, int $limit = 0): array
    {
        $this->logger->info("Scanning listings: {$categoryUrl}");

        $urls = [];
        $page = 1;
        $maxPages = 200;

        while ($page <= $maxPages) {
            $pageUrl = $this->buildPageUrl($categoryUrl, $page);
            $html = $this->http->getHtml($pageUrl);

            if ($html === null) {
                break;
            }

            $crawler = $this->createCrawler($html);
            $pageUrls = $this->extractProductUrls($crawler);

            if (empty($pageUrls)) {
                $this->logger->debug("No products found on page {$page}, stopping");
                break;
            }

            foreach ($pageUrls as $url) {
                $urls[] = $url;
                if ($limit > 0 && count($urls) >= $limit) {
                    break 2;
                }
            }

            $this->logger->debug("Page {$page}: found " . count($pageUrls) . " products (total: " . count($urls) . ")");

            // Check for next page
            if (!$this->hasNextPage($crawler, $page)) {
                break;
            }

            $page++;
        }

        $urls = array_unique($urls);
        $this->logger->info("Total product URLs: " . count($urls));

        return $urls;
    }

    // ------------------------------------------------------------------
    // parseProduct — full product page parse
    // ------------------------------------------------------------------
    public function parseProduct(string $url): ?array
    {
        $this->logger->debug("Parsing product: {$url}");

        $html = $this->http->getHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = $this->createCrawler($html);
        $data = [];

        // External ID from URL
        $data['external_id'] = $this->extractGunfireId($url);
        $data['url'] = $url;

        // Name
        $data['name'] = $this->parseName($crawler);
        if (empty($data['name'])) {
            $this->logger->warning("Could not extract product name: {$url}");
            return null;
        }

        // Brand
        $data['brand'] = $this->parseBrand($crawler);

        // SKU
        $data['external_sku'] = $this->parseSku($crawler);

        // EAN
        $data['ean'] = $this->parseEan($crawler);

        // Model
        $data['model'] = $this->parseModel($crawler, $data['name'], $data['brand']);

        // Prices
        $prices = $this->parsePrices($crawler);
        $data['price_purchase'] = $prices['current'];
        $data['price_regular'] = $prices['regular'];
        $data['currency'] = $this->config['currency'] ?? 'PLN';

        // Availability
        $data['availability'] = $this->parseAvailability($crawler);
        $data['stock_qty_text'] = $this->parseStockText($crawler);

        // Bundle detection
        $description = $this->parseDescription($crawler);
        $data['is_bundle'] = $this->isBundle($data['name'], $description) ? 1 : 0;

        // Breadcrumbs
        $breadcrumbs = $this->parseBreadcrumbs($crawler);

        // Images
        $images = $this->parseImages($crawler);

        // Series/category from breadcrumbs
        $series = $this->parseSeries($crawler);

        // Raw data for future reference
        $data['raw_data'] = [
            'description'  => mb_substr($description, 0, 5000),
            'breadcrumbs'  => $breadcrumbs,
            'images'       => $images,
            'series'       => $series,
        ];

        $data['is_active'] = 1;
        $data['last_price_check_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    // ------------------------------------------------------------------
    // checkPrice — lightweight price-only check
    // ------------------------------------------------------------------
    public function checkPrice(string $url): ?array
    {
        $html = $this->http->getHtml($url);
        if ($html === null) {
            $statusCode = $this->http->getStatusCode($url);
            if ($statusCode === 404) {
                return [
                    'price_purchase' => null,
                    'price_regular'  => null,
                    'currency'       => $this->config['currency'] ?? 'PLN',
                    'availability'   => null,
                    'is_active'      => false,
                ];
            }
            return null;
        }

        $crawler = $this->createCrawler($html);
        $prices = $this->parsePrices($crawler);

        return [
            'price_purchase' => $prices['current'],
            'price_regular'  => $prices['regular'],
            'currency'       => $this->config['currency'] ?? 'PLN',
            'availability'   => $this->parseAvailability($crawler),
            'is_active'      => true,
        ];
    }

    // ==================================================================
    //  Private parsing helpers
    // ==================================================================

    private function extractGunfireId(string $url): ?string
    {
        // Pattern: some-product-name-1234567890.html
        if (preg_match('/-(\d{5,15})\.html/', $url, $m)) {
            return $m[1];
        }
        // Fallback: any numeric ID at end of URL
        if (preg_match('/(\d{5,15})(?:\.html)?(?:\?.*)?$/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function parseName(Crawler $crawler): string
    {
        $selectors = [
            'h1.product-name',
            'h1.product__name',
            'h1[itemprop="name"]',
            '.product-detail h1',
            '.product-info h1',
            'h1',
        ];

        foreach ($selectors as $sel) {
            $name = $this->nodeText($crawler, $sel);
            if (!empty($name) && mb_strlen($name) > 2) {
                return $name;
            }
        }

        // Fallback: og:title meta
        $ogTitle = $this->nodeAttr($crawler, 'meta[property="og:title"]', 'content');
        if (!empty($ogTitle)) {
            return $this->cleanText($ogTitle);
        }

        return '';
    }

    private function parseBrand(Crawler $crawler): string
    {
        $selectors = [
            '[itemprop="brand"] [itemprop="name"]',
            '[itemprop="brand"]',
            '.product-brand',
            '.product__brand',
            '.brand-name',
            'a[href*="/brand/"]',
            'a[href*="/producer/"]',
            'a[href*="/manufacturer/"]',
        ];

        foreach ($selectors as $sel) {
            $brand = $this->nodeText($crawler, $sel);
            if (!empty($brand) && mb_strlen($brand) > 1 && mb_strlen($brand) < 100) {
                return $brand;
            }
        }

        // Fallback: check structured data
        $brand = $this->nodeAttr($crawler, 'meta[itemprop="brand"]', 'content');
        if (!empty($brand)) {
            return $this->cleanText($brand);
        }

        return '';
    }

    private function parseSku(Crawler $crawler): string
    {
        $selectors = [
            '[itemprop="sku"]',
            '.product-sku',
            '.product__sku',
            '.sku-value',
        ];

        foreach ($selectors as $sel) {
            $sku = $this->nodeText($crawler, $sel);
            if (empty($sku)) {
                $sku = $this->nodeAttr($crawler, $sel, 'content');
            }
            if (!empty($sku)) {
                return $sku;
            }
        }

        return '';
    }

    private function parseEan(Crawler $crawler): string
    {
        $selectors = [
            '[itemprop="gtin13"]',
            '[itemprop="gtin"]',
            '[itemprop="ean"]',
        ];

        foreach ($selectors as $sel) {
            $ean = $this->nodeText($crawler, $sel);
            if (empty($ean)) {
                $ean = $this->nodeAttr($crawler, $sel, 'content');
            }
            if (!empty($ean) && preg_match('/^\d{8,14}$/', $ean)) {
                return $ean;
            }
        }

        // Fallback: search in page text for EAN pattern
        try {
            $bodyText = $crawler->filter('body')->text('');
            if (preg_match('/\bEAN[\s:]*(\d{13})\b/i', $bodyText, $m)) {
                return $m[1];
            }
        } catch (\Exception) {
            // ignore
        }

        return '';
    }

    private function parseModel(Crawler $crawler, string $name, string $brand): string
    {
        // Try structured data
        $selectors = [
            '[itemprop="model"]',
            '.product-model',
        ];

        foreach ($selectors as $sel) {
            $model = $this->nodeText($crawler, $sel);
            if (empty($model)) {
                $model = $this->nodeAttr($crawler, $sel, 'content');
            }
            if (!empty($model)) {
                return $model;
            }
        }

        // Try extracting model from name by removing brand
        if (!empty($brand) && !empty($name)) {
            $nameWithoutBrand = trim(str_ireplace($brand, '', $name));
            // Take first segment that looks like a model number
            if (preg_match('/^([A-Z0-9][\w\-\.\/]+)/i', $nameWithoutBrand, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    private function parsePrices(Crawler $crawler): array
    {
        $result = ['current' => null, 'regular' => null];

        // Strategy 1: itemprop price
        $priceText = $this->nodeAttr($crawler, '[itemprop="price"]', 'content');
        if (!empty($priceText)) {
            $result['current'] = $this->parsePrice($priceText);
        }

        // Strategy 2: common price selectors
        if ($result['current'] === null) {
            $currentSelectors = [
                '.product-price .current',
                '.product__price--current',
                '.price-current',
                '.price--sale',
                '.special-price .price',
                '.product-price',
                '.price',
                '[data-price]',
            ];

            foreach ($currentSelectors as $sel) {
                $text = $this->nodeText($crawler, $sel);
                if (!empty($text)) {
                    $price = $this->parsePrice($text);
                    if ($price !== null && $price > 0) {
                        $result['current'] = $price;
                        break;
                    }
                }
            }
        }

        // Strategy 3: data attribute
        if ($result['current'] === null) {
            $dataPrice = $this->nodeAttr($crawler, '[data-price]', 'data-price');
            if (!empty($dataPrice)) {
                $result['current'] = $this->parsePrice($dataPrice);
            }
        }

        // Regular / old price
        $regularSelectors = [
            '.product-price .old',
            '.product__price--old',
            '.price-old',
            '.price--regular',
            '.old-price .price',
            '.regular-price',
            'del .price',
            's .price',
        ];

        foreach ($regularSelectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text)) {
                $price = $this->parsePrice($text);
                if ($price !== null && $price > 0) {
                    $result['regular'] = $price;
                    break;
                }
            }
        }

        // If no regular price, use current as regular
        if ($result['regular'] === null && $result['current'] !== null) {
            $result['regular'] = $result['current'];
        }

        return $result;
    }

    private function parseAvailability(Crawler $crawler): string
    {
        $selectors = [
            '[itemprop="availability"]',
            '.product-availability',
            '.availability',
            '.stock-status',
            '.in-stock',
            '.out-of-stock',
        ];

        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (empty($text)) {
                $text = $this->nodeAttr($crawler, $sel, 'content');
                if (!empty($text)) {
                    // Schema.org values like "https://schema.org/InStock"
                    if (str_contains($text, 'InStock')) {
                        return 'in_stock';
                    }
                    if (str_contains($text, 'OutOfStock')) {
                        return 'out_of_stock';
                    }
                    if (str_contains($text, 'PreOrder')) {
                        return 'preorder';
                    }
                }
            }
            if (!empty($text)) {
                return $this->normalizeAvailability($text);
            }
        }

        return 'unknown';
    }

    private function parseStockText(Crawler $crawler): string
    {
        $selectors = [
            '.stock-quantity',
            '.product-stock',
            '.qty-available',
        ];

        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text)) {
                return $text;
            }
        }

        return '';
    }

    private function parseDescription(Crawler $crawler): string
    {
        $selectors = [
            '[itemprop="description"]',
            '.product-description',
            '.product__description',
            '.description',
            '#product-description',
            '.tab-content .description',
        ];

        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text) && mb_strlen($text) > 20) {
                return $text;
            }
        }

        return '';
    }

    private function parseBreadcrumbs(Crawler $crawler): array
    {
        $breadcrumbs = [];

        $selectors = [
            '.breadcrumb a',
            '.breadcrumbs a',
            '[itemprop="itemListElement"] [itemprop="name"]',
            'nav.breadcrumb a',
            '.breadcrumb-item a',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$breadcrumbs) {
                    $name = $this->cleanText($node->text(''));
                    $href = $node->attr('href') ?? '';
                    if (!empty($name)) {
                        $breadcrumbs[] = [
                            'name' => $name,
                            'url'  => $href ? $this->absoluteUrl($href) : '',
                        ];
                    }
                });
            } catch (\Exception) {
                continue;
            }
            if (!empty($breadcrumbs)) {
                break;
            }
        }

        return $breadcrumbs;
    }

    private function parseImages(Crawler $crawler): array
    {
        $images = [];

        $selectors = [
            '.product-gallery img',
            '.product-images img',
            '.product__gallery img',
            '[itemprop="image"]',
            '.gallery img',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$images) {
                    $src = $node->attr('src') ?? $node->attr('data-src') ?? $node->attr('data-lazy') ?? '';
                    if (!empty($src) && !str_contains($src, 'placeholder') && !str_contains($src, '1x1')) {
                        $images[] = $this->absoluteUrl($src);
                    }
                });
            } catch (\Exception) {
                continue;
            }
            if (!empty($images)) {
                break;
            }
        }

        // Fallback: og:image
        if (empty($images)) {
            $ogImage = $this->nodeAttr($crawler, 'meta[property="og:image"]', 'content');
            if (!empty($ogImage)) {
                $images[] = $ogImage;
            }
        }

        return array_values(array_unique($images));
    }

    private function parseSeries(Crawler $crawler): string
    {
        // Try to find series/line info
        $selectors = [
            '.product-series',
            '.product-line',
            '[data-series]',
        ];

        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text)) {
                return $text;
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    //  URL / pagination helpers
    // ------------------------------------------------------------------

    private function isCategoryUrl(string $href): bool
    {
        return (bool)preg_match('#/c/[a-z0-9\-]+#i', $href)
            || (bool)preg_match('#/category/[a-z0-9\-]+#i', $href);
    }

    private function isProductUrl(string $href): bool
    {
        return (bool)preg_match('/-\d{5,15}\.html/', $href)
            || (bool)preg_match('#/p/[a-z0-9\-]+#i', $href)
            || (bool)preg_match('#/product/[a-z0-9\-]+#i', $href);
    }

    private function buildPageUrl(string $baseUrl, int $page): string
    {
        if ($page <= 1) {
            return $baseUrl;
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . 'page=' . $page;
    }

    private function extractProductUrls(Crawler $crawler): array
    {
        $urls = [];

        // Primary selectors for product links
        $selectors = [
            '.product-list a.product-link',
            '.product-item a[href]',
            '.products-list a.product-name',
            '.product-card a[href]',
            '.product a[href*=".html"]',
            'a[href*=".html"]',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$urls) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isProductUrl($href)) {
                        $urls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {
                continue;
            }
            if (!empty($urls)) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    private function hasNextPage(Crawler $crawler, int $currentPage): bool
    {
        // Check for next page link
        $nextSelectors = [
            '.pagination .next a',
            '.pagination a[rel="next"]',
            'a.next-page',
            '.pager .next a',
            'a[aria-label="Next"]',
        ];

        foreach ($nextSelectors as $sel) {
            try {
                if ($crawler->filter($sel)->count() > 0) {
                    return true;
                }
            } catch (\Exception) {
                continue;
            }
        }

        // Check for page number > current
        try {
            $lastPage = 0;
            $crawler->filter('.pagination a, .pager a')->each(function (Crawler $node) use (&$lastPage) {
                $text = trim($node->text(''));
                if (is_numeric($text)) {
                    $lastPage = max($lastPage, (int)$text);
                }
            });
            if ($lastPage > $currentPage) {
                return true;
            }
        } catch (\Exception) {
            // ignore
        }

        return false;
    }

    private function normalizeAvailability(string $text): string
    {
        $text = mb_strtolower(trim($text));

        $inStockKeywords = ['in stock', 'available', 'w magazynie', 'dostępny', 'in_stock', 'instock'];
        $outOfStockKeywords = ['out of stock', 'unavailable', 'niedostępny', 'brak', 'out_of_stock', 'sold out'];
        $preorderKeywords = ['pre-order', 'preorder', 'zamówienie'];

        foreach ($inStockKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'in_stock';
            }
        }
        foreach ($outOfStockKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'out_of_stock';
            }
        }
        foreach ($preorderKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'preorder';
            }
        }

        return $text;
    }
}
