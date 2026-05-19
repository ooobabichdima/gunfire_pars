<?php

declare(strict_types=1);

namespace App\Suppliers\Gunfire;

use App\Suppliers\AbstractSupplierParser;
use Symfony\Component\DomCrawler\Crawler;

final class GunfireParser extends AbstractSupplierParser
{
    /**
     * Gunfire.com URL patterns (discovered via search indexing):
     *   Categories: /en/categories/slug-NNNNNNNNNN.html
     *   Products:   /en/products/slug-NNNNNNNNNN.html
     *   Producers:  /en/producers/slug-NNNNNNNNNN.html
     *   Menu:       /en/menu/slug-NNN.html
     *   Pagination: ?counter=N&filter_default=n
     */

    private const KNOWN_CATEGORIES = [
        '/en/categories/airsoft-guns-aeg-models-1161769384.html',
        '/en/categories/airsoft-guns-aeg-models-assault-rifles-1161770206.html',
        '/en/categories/airsoft-guns-aeg-models-machine-pistols-1161770101.html',
        '/en/categories/airsoft-guns-gas-models-1132127965.html',
        '/en/categories/gas-models-airsoft-guns-assault-rifles-1219738492.html',
        '/en/categories/asg-models-airsoft-guns-sniper-rifles-1132418901.html',
        '/en/categories/parts-and-accessories-1148369283.html',
        '/en/categories/parts-and-accessories-flashlights-and-accessories-flashlights-1130442222.html',
        '/en/categories/parts-and-accessories-barrels-innerbarrels-1185715508.html',
        '/en/categories/parts-and-accessories-external-parts-grips-1193053459.html',
        '/en/categories/magazines-parts-and-accessories-drum-and-box-magazines-1210856466.html',
        '/en/categories/tactical-equipment-backpacks-1196863230.html',
    ];

    public function getSupplierCode(): string
    {
        return 'gunfire';
    }

    // ------------------------------------------------------------------
    // scanCategories
    // ------------------------------------------------------------------
    public function scanCategories(): array
    {
        $this->logger->info('Scanning categories from Gunfire...');
        $urls = [];

        // Strategy 1: Parse sitemap XML
        $sitemapUrls = $this->parseSitemapIndex();
        if (!empty($sitemapUrls)) {
            $urls = array_merge($urls, $sitemapUrls);
            $this->logger->info("Sitemap: found " . count($sitemapUrls) . " category URLs");
        }

        // Strategy 2: Crawl the homepage navigation
        if (empty($urls)) {
            $urls = $this->crawlHomepageCategories();
        }

        // Strategy 3: Crawl the sitemap.php page
        if (empty($urls)) {
            $urls = $this->crawlSitemapPage();
        }

        // Strategy 4: Known seed categories (always merge as baseline)
        $seedUrls = $this->getSeedCategories();
        $urls = array_merge($urls, $seedUrls);

        $urls = array_values(array_unique($urls));
        $this->logger->info("Total categories collected: " . count($urls));

        return $urls;
    }

    // ------------------------------------------------------------------
    // scanListings — paginate through a category, collect product URLs
    // ------------------------------------------------------------------
    public function scanListings(string $categoryUrl, int $limit = 0): array
    {
        $this->logger->info("Scanning listings: {$categoryUrl}");

        $urls = [];
        $counter = 0;
        $maxPages = 200;

        while ($counter < $maxPages) {
            $pageUrl = $this->buildPageUrl($categoryUrl, $counter);
            $html = $this->http->getHtml($pageUrl);

            if ($html === null) {
                $this->logger->debug("Failed to fetch page (counter={$counter})");
                break;
            }

            $crawler = $this->createCrawler($html);
            $pageUrls = $this->extractProductUrls($crawler);

            if (empty($pageUrls)) {
                $this->logger->debug("No products on page counter={$counter}, stopping");
                break;
            }

            $beforeCount = count($urls);
            foreach ($pageUrls as $url) {
                $urls[] = $url;
                if ($limit > 0 && count($urls) >= $limit) {
                    break 2;
                }
            }

            $newCount = count($urls) - $beforeCount;
            $this->logger->debug("Page counter={$counter}: +{$newCount} products (total: " . count($urls) . ")");

            if (!$this->hasNextPage($crawler, $counter)) {
                break;
            }

            $counter++;
        }

        $urls = array_values(array_unique($urls));
        $this->logger->info("Total product URLs from listing: " . count($urls));

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

        $data['external_id'] = $this->extractGunfireId($url);
        $data['url'] = $url;

        $data['name'] = $this->parseName($crawler, $html);
        if (empty($data['name'])) {
            $this->logger->warning("Could not extract product name: {$url}");
            return null;
        }

        $data['brand'] = $this->parseBrand($crawler, $html);
        $data['external_sku'] = $this->parseSku($crawler, $html);
        $data['ean'] = $this->parseEan($crawler, $html);
        $data['model'] = $this->parseModel($crawler, $data['name'], $data['brand']);

        $prices = $this->parsePrices($crawler, $html);
        $data['price_purchase'] = $prices['current'];
        $data['price_regular'] = $prices['regular'];
        $data['currency'] = $this->config['currency'] ?? 'PLN';

        $data['availability'] = $this->parseAvailability($crawler, $html);
        $data['stock_qty_text'] = $this->parseStockText($crawler);

        $description = $this->parseDescription($crawler);
        $data['is_bundle'] = $this->isBundle($data['name'], $description) ? 1 : 0;

        $breadcrumbs = $this->parseBreadcrumbs($crawler);
        $images = $this->parseImages($crawler);
        $series = $this->parseSeries($crawler, $breadcrumbs);

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
        $response = $this->http->get($url);

        if ($response === null) {
            return null;
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404 || $statusCode === 410) {
            return [
                'price_purchase' => null,
                'price_regular'  => null,
                'currency'       => $this->config['currency'] ?? 'PLN',
                'availability'   => null,
                'is_active'      => false,
            ];
        }

        if ($statusCode >= 400) {
            return null;
        }

        $html = (string)$response->getBody();
        $crawler = $this->createCrawler($html);
        $prices = $this->parsePrices($crawler, $html);

        return [
            'price_purchase' => $prices['current'],
            'price_regular'  => $prices['regular'],
            'currency'       => $this->config['currency'] ?? 'PLN',
            'availability'   => $this->parseAvailability($crawler, $html),
            'is_active'      => true,
        ];
    }

    // ==================================================================
    //  Category scanning strategies
    // ==================================================================

    private function parseSitemapIndex(): array
    {
        $urls = [];
        $sitemapLocations = [
            $this->getBaseUrl() . '/sitemap.xml',
            $this->getBaseUrl() . '/en/sitemap.xml',
            $this->getBaseUrl() . '/sitemap_index.xml',
        ];

        foreach ($sitemapLocations as $sitemapUrl) {
            $xml = $this->http->getHtml($sitemapUrl);
            if ($xml === null) {
                continue;
            }

            // Parse sitemap index for sub-sitemaps
            if (preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches)) {
                foreach ($matches[1] as $loc) {
                    $loc = html_entity_decode($loc);
                    if ($this->isCategoryUrl($loc)) {
                        $urls[] = $loc;
                    } elseif (str_contains($loc, 'sitemap') && str_contains($loc, '.xml')) {
                        $subUrls = $this->parseSitemapFile($loc);
                        $urls = array_merge($urls, $subUrls);
                    }
                }
            }

            if (!empty($urls)) {
                break;
            }
        }

        return $urls;
    }

    private function parseSitemapFile(string $url): array
    {
        $urls = [];
        $xml = $this->http->getHtml($url);
        if ($xml === null) {
            return $urls;
        }

        if (preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches)) {
            foreach ($matches[1] as $loc) {
                $loc = html_entity_decode($loc);
                if ($this->isCategoryUrl($loc)) {
                    $urls[] = $loc;
                }
            }
        }

        return $urls;
    }

    private function crawlHomepageCategories(): array
    {
        $urls = [];

        $pagesToTry = [
            $this->getBaseUrl() . '/en/',
            $this->getBaseUrl() . '/',
        ];

        foreach ($pagesToTry as $pageUrl) {
            $html = $this->http->getHtml($pageUrl);
            if ($html === null) {
                continue;
            }

            $crawler = $this->createCrawler($html);

            // Gunfire uses /en/categories/ and /en/menu/ links in navigation
            $selectors = [
                'a[href*="/en/categories/"]',
                'a[href*="/categories/"]',
                'a[href*="/en/menu/"]',
                'nav a[href*=".html"]',
                '.menu a[href*=".html"]',
                '#menu a[href]',
                '.nav a[href]',
                'a[href]',
            ];

            foreach ($selectors as $sel) {
                try {
                    $crawler->filter($sel)->each(function (Crawler $node) use (&$urls) {
                        $href = $node->attr('href') ?? '';
                        if ($this->isCategoryUrl($href)) {
                            $urls[] = $this->absoluteUrl($href);
                        }
                    });
                } catch (\Exception) {
                    continue;
                }
            }

            if (!empty($urls)) {
                $this->logger->info("Homepage crawl: found " . count($urls) . " categories");
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    private function crawlSitemapPage(): array
    {
        $urls = [];
        $html = $this->http->getHtml($this->getBaseUrl() . '/en/sitemap.php');
        if ($html === null) {
            return $urls;
        }

        $crawler = $this->createCrawler($html);

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

        if (!empty($urls)) {
            $this->logger->info("Sitemap page: found " . count($urls) . " categories");
        }

        return array_values(array_unique($urls));
    }

    private function getSeedCategories(): array
    {
        $base = $this->getBaseUrl();
        return array_map(fn(string $path) => $base . $path, self::KNOWN_CATEGORIES);
    }

    // ==================================================================
    //  Product parsing helpers (with raw HTML fallbacks)
    // ==================================================================

    private function extractGunfireId(string $url): ?string
    {
        // Pattern: /en/products/some-slug-1152198592.html
        if (preg_match('/-(\d{7,15})\.html/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d{7,15})(?:\.html)?/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function parseName(Crawler $crawler, string $html): string
    {
        // DOM selectors
        $selectors = [
            'h1[itemprop="name"]',
            'h1.product-name',
            'h1.product__name',
            '.product-detail h1',
            '.product-info h1',
            'h1',
        ];

        foreach ($selectors as $sel) {
            $name = $this->nodeText($crawler, $sel);
            if (!empty($name) && mb_strlen($name) > 2 && mb_strlen($name) < 300) {
                return $name;
            }
        }

        // Fallback: og:title
        $ogTitle = $this->nodeAttr($crawler, 'meta[property="og:title"]', 'content');
        if (!empty($ogTitle)) {
            $cleaned = preg_replace('/\s*[|–-]\s*(Gunfire|gunfire).*$/i', '', $ogTitle);
            return $this->cleanText($cleaned ?? $ogTitle);
        }

        // Fallback: <title> tag
        $title = $this->nodeText($crawler, 'title');
        if (!empty($title)) {
            $cleaned = preg_replace('/\s*[|–-]\s*(Gunfire|gunfire).*$/i', '', $title);
            return $this->cleanText($cleaned ?? $title);
        }

        // Raw HTML regex fallback
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            return $this->cleanText(strip_tags($m[1]));
        }

        return '';
    }

    private function parseBrand(Crawler $crawler, string $html): string
    {
        // DOM selectors — Gunfire links to /en/producers/brand-NNNN.html
        $selectors = [
            '[itemprop="brand"] [itemprop="name"]',
            '[itemprop="brand"]',
            'a[href*="/producers/"]',
            'a[href*="/en/producers/"]',
            '.product-brand',
            '.product__brand',
            '.brand-name',
            'a[href*="/brand/"]',
        ];

        foreach ($selectors as $sel) {
            $brand = $this->nodeText($crawler, $sel);
            if (!empty($brand) && mb_strlen($brand) > 1 && mb_strlen($brand) < 100) {
                // Skip if it looks like a full URL or navigation text
                if (!str_contains($brand, '/') && !str_contains($brand, 'http')) {
                    return $brand;
                }
            }
        }

        // Fallback: meta itemprop="brand"
        $brand = $this->nodeAttr($crawler, 'meta[itemprop="brand"]', 'content');
        if (!empty($brand)) {
            return $this->cleanText($brand);
        }

        // Fallback: JSON-LD structured data
        $brand = $this->extractFromJsonLd($html, 'brand');
        if (!empty($brand)) {
            return $brand;
        }

        return '';
    }

    private function parseSku(Crawler $crawler, string $html): string
    {
        $selectors = [
            '[itemprop="sku"]',
            '.product-sku',
            '.product__sku',
            '.sku-value',
            '[data-sku]',
        ];

        foreach ($selectors as $sel) {
            $sku = $this->nodeText($crawler, $sel);
            if (empty($sku)) {
                $sku = $this->nodeAttr($crawler, $sel, 'content');
            }
            if (empty($sku)) {
                $sku = $this->nodeAttr($crawler, $sel, 'data-sku');
            }
            if (!empty($sku)) {
                return trim($sku);
            }
        }

        // JSON-LD fallback
        $sku = $this->extractFromJsonLd($html, 'sku');
        if (!empty($sku)) {
            return $sku;
        }

        return '';
    }

    private function parseEan(Crawler $crawler, string $html): string
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
            if (!empty($ean) && preg_match('/^\d{8,14}$/', trim($ean))) {
                return trim($ean);
            }
        }

        // JSON-LD
        $gtin = $this->extractFromJsonLd($html, 'gtin13');
        if (empty($gtin)) {
            $gtin = $this->extractFromJsonLd($html, 'gtin');
        }
        if (!empty($gtin) && preg_match('/^\d{8,14}$/', $gtin)) {
            return $gtin;
        }

        // Raw HTML regex for EAN
        if (preg_match('/\bEAN[\s:]*(\d{13})\b/i', $html, $m)) {
            return $m[1];
        }

        return '';
    }

    private function parseModel(Crawler $crawler, string $name, string $brand): string
    {
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

        // Extract model from name
        if (!empty($brand) && !empty($name)) {
            $nameWithoutBrand = trim(str_ireplace($brand, '', $name));
            if (preg_match('/\b([A-Z]{1,5}[\-\.]?\d{1,5}[A-Z]?\d{0,3}(?:[\-\.]\w{1,5})?)\b/i', $nameWithoutBrand, $m)) {
                return strtoupper($m[1]);
            }
        }

        // Try from full name
        if (preg_match('/\b([A-Z]{1,4}\-?[A-Z]?\d{1,5}[A-Z]?\d{0,3})\b/', $name, $m)) {
            return $m[1];
        }

        return '';
    }

    private function parsePrices(Crawler $crawler, string $html): array
    {
        $result = ['current' => null, 'regular' => null];

        // Strategy 1: JSON-LD price
        $jsonPrice = $this->extractFromJsonLd($html, 'price');
        if (!empty($jsonPrice)) {
            $result['current'] = $this->parsePrice($jsonPrice);
        }

        // Strategy 2: itemprop="price" content attr
        if ($result['current'] === null) {
            $priceText = $this->nodeAttr($crawler, '[itemprop="price"]', 'content');
            if (!empty($priceText)) {
                $result['current'] = $this->parsePrice($priceText);
            }
        }

        // Strategy 3: DOM selectors
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

        // Strategy 4: data-price attribute
        if ($result['current'] === null) {
            $dataPrice = $this->nodeAttr($crawler, '[data-price]', 'data-price');
            if (!empty($dataPrice)) {
                $result['current'] = $this->parsePrice($dataPrice);
            }
        }

        // Strategy 5: raw HTML regex for price patterns (PLN / zł)
        if ($result['current'] === null) {
            if (preg_match('/(\d[\d\s,.]*\d)\s*(?:PLN|zł|€|EUR)/i', $html, $m)) {
                $result['current'] = $this->parsePrice($m[1]);
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
            'del',
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

        if ($result['regular'] === null && $result['current'] !== null) {
            $result['regular'] = $result['current'];
        }

        return $result;
    }

    private function parseAvailability(Crawler $crawler, string $html): string
    {
        // JSON-LD availability
        $jsonAvail = $this->extractFromJsonLd($html, 'availability');
        if (!empty($jsonAvail)) {
            if (str_contains($jsonAvail, 'InStock')) {
                return 'in_stock';
            }
            if (str_contains($jsonAvail, 'OutOfStock')) {
                return 'out_of_stock';
            }
            if (str_contains($jsonAvail, 'PreOrder')) {
                return 'preorder';
            }
        }

        // DOM selectors
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
                $content = $this->nodeAttr($crawler, $sel, 'content');
                if (!empty($content)) {
                    if (str_contains($content, 'InStock')) {
                        return 'in_stock';
                    }
                    if (str_contains($content, 'OutOfStock')) {
                        return 'out_of_stock';
                    }
                }
            }
            if (!empty($text)) {
                return $this->normalizeAvailability($text);
            }
        }

        // Check for add-to-cart button as proxy for availability
        $addToCart = $crawler->filter('button[type="submit"], .add-to-cart, [data-action="add-to-cart"], input[name="add"]');
        if ($addToCart->count() > 0) {
            return 'in_stock';
        }

        return 'unknown';
    }

    private function parseStockText(Crawler $crawler): string
    {
        $selectors = [
            '.stock-quantity',
            '.product-stock',
            '.qty-available',
            '.availability-text',
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
            '#description',
            '.tab-content .description',
            '.tabs-content .description',
        ];

        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text) && mb_strlen($text) > 20) {
                return $text;
            }
        }

        // Fallback: og:description
        $ogDesc = $this->nodeAttr($crawler, 'meta[property="og:description"]', 'content');
        if (!empty($ogDesc) && mb_strlen($ogDesc) > 20) {
            return $this->cleanText($ogDesc);
        }

        return '';
    }

    private function parseBreadcrumbs(Crawler $crawler): array
    {
        $breadcrumbs = [];

        $selectors = [
            '[itemprop="itemListElement"] [itemprop="name"]',
            '.breadcrumb a',
            '.breadcrumbs a',
            'nav.breadcrumb a',
            '.breadcrumb-item a',
            'ol.breadcrumb a',
            'ul.breadcrumb a',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$breadcrumbs) {
                    $name = $this->cleanText($node->text(''));
                    $href = $node->attr('href') ?? '';
                    if (!empty($name) && mb_strtolower($name) !== 'home' && mb_strtolower($name) !== 'gunfire') {
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
            '.gallery img',
            '[itemprop="image"]',
            '.product-photo img',
            '.product-image img',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$images) {
                    $src = $node->attr('src')
                        ?? $node->attr('data-src')
                        ?? $node->attr('data-lazy')
                        ?? $node->attr('data-original')
                        ?? '';
                    if (!empty($src) && !str_contains($src, 'placeholder') && !str_contains($src, '1x1') && !str_contains($src, 'data:image')) {
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

        // og:image fallback
        if (empty($images)) {
            $ogImage = $this->nodeAttr($crawler, 'meta[property="og:image"]', 'content');
            if (!empty($ogImage)) {
                $images[] = $ogImage;
            }
        }

        return array_values(array_unique($images));
    }

    private function parseSeries(Crawler $crawler, array $breadcrumbs): string
    {
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

        // Infer series from breadcrumbs (second-to-last = subcategory)
        if (count($breadcrumbs) >= 2) {
            return $breadcrumbs[count($breadcrumbs) - 1]['name'] ?? '';
        }

        return '';
    }

    // ==================================================================
    //  JSON-LD structured data extraction
    // ==================================================================

    private function extractFromJsonLd(string $html, string $field): string
    {
        if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonStr) {
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                $value = $this->findInJsonLd($data, $field);
                if (!empty($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    private function findInJsonLd(array $data, string $field): string
    {
        // Direct field
        if (isset($data[$field])) {
            if (is_string($data[$field])) {
                return trim($data[$field]);
            }
            if (is_numeric($data[$field])) {
                return (string)$data[$field];
            }
            if (is_array($data[$field]) && isset($data[$field]['name'])) {
                return trim($data[$field]['name']);
            }
        }

        // Check inside "offers"
        if (isset($data['offers']) && is_array($data['offers'])) {
            $offers = isset($data['offers'][0]) ? $data['offers'] : [$data['offers']];
            foreach ($offers as $offer) {
                if (isset($offer[$field])) {
                    if (is_string($offer[$field])) {
                        return trim($offer[$field]);
                    }
                    if (is_numeric($offer[$field])) {
                        return (string)$offer[$field];
                    }
                }
            }
        }

        // Recursively check @graph
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $val = $this->findInJsonLd($node, $field);
                    if (!empty($val)) {
                        return $val;
                    }
                }
            }
        }

        return '';
    }

    // ==================================================================
    //  URL / pagination helpers
    // ==================================================================

    private function isCategoryUrl(string $href): bool
    {
        // Gunfire pattern: /en/categories/slug-NNNNNNNN.html
        return (bool)preg_match('#/(?:en/)?categories/[\w\-]+-\d{7,15}\.html#i', $href);
    }

    private function isProductUrl(string $href): bool
    {
        // Gunfire pattern: /en/products/slug-NNNNNNNN.html
        return (bool)preg_match('#/(?:en/)?products/[\w\-]+-\d{7,15}\.html#i', $href);
    }

    private function buildPageUrl(string $baseUrl, int $counter): string
    {
        if ($counter <= 0) {
            return $baseUrl;
        }

        // Gunfire uses ?counter=N&filter_default=n
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . 'counter=' . $counter . '&filter_default=n';
    }

    private function extractProductUrls(Crawler $crawler): array
    {
        $urls = [];

        // Gunfire-specific: links to /en/products/...html
        $selectors = [
            'a[href*="/en/products/"]',
            'a[href*="/products/"]',
            '.product-list a[href*=".html"]',
            '.product-item a[href*=".html"]',
            '.products-list a[href*=".html"]',
            '.product-card a[href*=".html"]',
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

    private function hasNextPage(Crawler $crawler, int $currentCounter): bool
    {
        // Check for next page link with counter > current
        $nextSelectors = [
            '.pagination .next a[href]',
            '.pagination a[rel="next"]',
            'a.next-page[href]',
            '.pager .next a[href]',
            'a[aria-label="Next"][href]',
            'a[title="Next"][href]',
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

        // Check for any pagination link with counter > current
        try {
            $found = false;
            $crawler->filter('a[href*="counter="]')->each(function (Crawler $node) use ($currentCounter, &$found) {
                $href = $node->attr('href') ?? '';
                if (preg_match('/counter=(\d+)/', $href, $m)) {
                    if ((int)$m[1] > $currentCounter) {
                        $found = true;
                    }
                }
            });
            if ($found) {
                return true;
            }
        } catch (\Exception) {
            // ignore
        }

        // Check numeric pagination links
        try {
            $lastPage = 0;
            $crawler->filter('.pagination a, .pager a, .paginator a')->each(function (Crawler $node) use (&$lastPage) {
                $text = trim($node->text(''));
                if (is_numeric($text)) {
                    $lastPage = max($lastPage, (int)$text);
                }
            });
            if ($lastPage > ($currentCounter + 1)) {
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

        $inStock = ['in stock', 'available', 'w magazynie', 'dostępny', 'in_stock', 'instock', 'on stock', 'last items'];
        $outOfStock = ['out of stock', 'unavailable', 'niedostępny', 'brak', 'out_of_stock', 'sold out', 'not available'];
        $preorder = ['pre-order', 'preorder', 'zamówienie', 'pre order', 'coming soon'];

        foreach ($inStock as $kw) {
            if (str_contains($text, $kw)) {
                return 'in_stock';
            }
        }
        foreach ($outOfStock as $kw) {
            if (str_contains($text, $kw)) {
                return 'out_of_stock';
            }
        }
        foreach ($preorder as $kw) {
            if (str_contains($text, $kw)) {
                return 'preorder';
            }
        }

        return $text;
    }
}
