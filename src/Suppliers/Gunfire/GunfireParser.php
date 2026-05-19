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

        $description = $this->parseDescription($crawler, $html);
        $specifications = $this->parseSpecifications($crawler, $html);
        $data['is_bundle'] = $this->isBundle($data['name'], $description) ? 1 : 0;

        $breadcrumbs = $this->parseBreadcrumbs($crawler);
        $images = $this->parseImages($crawler, $html);
        $series = $this->parseSeries($crawler, $breadcrumbs);

        $data['raw_data'] = [
            'description'    => mb_substr($description, 0, 10000),
            'specifications' => $specifications,
            'breadcrumbs'    => $breadcrumbs,
            'images'         => $images,
            'series'         => $series,
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
        // PRIMARY: "Add to basket" button is the ground truth on Gunfire
        // No button = out of stock, regardless of what JSON-LD says
        $hasAddToCart = false;

        $cartSelectors = [
            'button[name="add"]',
            'input[name="add"]',
            'button.add-to-cart',
            '.add-to-cart button',
            '[data-action="add-to-cart"]',
            'button.add-to-basket',
            'form[action*="cart"] button[type="submit"]',
            'form[action*="basket"] button[type="submit"]',
        ];

        foreach ($cartSelectors as $sel) {
            try {
                if ($crawler->filter($sel)->count() > 0) {
                    $hasAddToCart = true;
                    break;
                }
            } catch (\Exception) {}
        }

        // Fallback: search raw HTML for add-to-cart patterns
        if (!$hasAddToCart) {
            $cartPatterns = [
                'add to basket',
                'add to cart',
                'dodaj do koszyka',
                'do koszyka',
                'name="add"',
                'add-to-cart',
                'addToCart',
                'add_to_cart',
            ];
            $htmlLower = mb_strtolower($html);
            foreach ($cartPatterns as $pattern) {
                if (str_contains($htmlLower, $pattern)) {
                    $hasAddToCart = true;
                    break;
                }
            }
        }

        if ($hasAddToCart) {
            return 'in_stock';
        }

        // Check for explicit out-of-stock markers
        $outOfStockPatterns = [
            'out of stock', 'sold out', 'unavailable', 'not available',
            'niedostępny', 'brak w magazynie', 'wyczerpany',
            'notify me', 'powiadom mnie', 'check availability',
        ];
        $htmlLower = mb_strtolower($html);
        foreach ($outOfStockPatterns as $pattern) {
            if (str_contains($htmlLower, $pattern)) {
                return 'out_of_stock';
            }
        }

        // Secondary: JSON-LD (may be inaccurate, but better than unknown)
        $jsonAvail = $this->extractFromJsonLd($html, 'availability');
        if (!empty($jsonAvail)) {
            if (str_contains($jsonAvail, 'OutOfStock')) {
                return 'out_of_stock';
            }
            if (str_contains($jsonAvail, 'PreOrder')) {
                return 'preorder';
            }
            // Don't trust InStock from JSON-LD — if button was missing, it's not in stock
        }

        // DOM selectors
        $selectors = [
            '[itemprop="availability"]',
            '.product-availability',
            '.availability',
            '.stock-status',
        ];

        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text)) {
                return $this->normalizeAvailability($text);
            }
            $content = $this->nodeAttr($crawler, $sel, 'content');
            if (!empty($content)) {
                if (str_contains($content, 'OutOfStock')) {
                    return 'out_of_stock';
                }
            }
        }

        // No cart button + no clear markers = likely out of stock
        return 'out_of_stock';
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

    private function parseDescription(Crawler $crawler, string $html): string
    {
        $parts = [];

        // Collect ALL description-like sections (don't stop at first)
        $selectors = [
            '.product-description',
            '.product__description',
            '#product-description',
            '#description',
            '.description',
            '.product-desc',
            '.long-description',
            '.product-info-detailed',
            '.product-detail-description',
            '.tab-content .description',
            '.tabs-content .description',
            '.tab-pane .description',
            '.resetcss',
            '.product-text',
            '[data-tab="description"]',
            '[data-tab-content="description"]',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$parts) {
                    $text = $this->cleanText($node->text(''));
                    if (!empty($text) && mb_strlen($text) > 30) {
                        $parts[] = $text;
                    }
                });
            } catch (\Exception) {
                continue;
            }
        }

        // itemprop="description" — often a short summary, add if we have nothing better
        $itemPropDesc = $this->nodeText($crawler, '[itemprop="description"]');

        // JSON-LD description
        $jsonDesc = $this->extractFromJsonLd($html, 'description');

        // og:description
        $ogDesc = $this->nodeAttr($crawler, 'meta[property="og:description"]', 'content');

        // meta description
        $metaDesc = $this->nodeAttr($crawler, 'meta[name="description"]', 'content');

        // Pick the longest available description
        $candidates = $parts;
        if (!empty($itemPropDesc) && mb_strlen($itemPropDesc) > 30) {
            $candidates[] = $itemPropDesc;
        }
        if (!empty($jsonDesc) && mb_strlen($jsonDesc) > 30) {
            $candidates[] = $this->cleanText($jsonDesc);
        }
        if (!empty($ogDesc) && mb_strlen($ogDesc) > 30) {
            $candidates[] = $this->cleanText($ogDesc);
        }
        if (!empty($metaDesc) && mb_strlen($metaDesc) > 30) {
            $candidates[] = $this->cleanText($metaDesc);
        }

        if (empty($candidates)) {
            return '';
        }

        // Return longest description found
        usort($candidates, fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $candidates[0];
    }

    /**
     * Parse specifications / parameters table.
     * Returns associative array: ['Weight' => '2735 g', 'Color' => 'Black', ...]
     */
    private function parseSpecifications(Crawler $crawler, string $html): array
    {
        $specs = [];

        // Strategy 1: <table> with spec rows (th/td or td/td pairs)
        $tableSelectors = [
            '.product-attributes table',
            '.product-params table',
            '.product-specifications table',
            '.specifications table',
            '.spec-table',
            '.params table',
            '.parameters table',
            '.technical-data table',
            '.tech-specs table',
            '.features table',
            '.product-features table',
            '.attribute-table',
            'table.attributes',
            'table.params',
            'table.specifications',
            'table',
        ];

        foreach ($tableSelectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $table) use (&$specs) {
                    $table->filter('tr')->each(function (Crawler $row) use (&$specs) {
                        $cells = [];
                        $row->filter('td, th')->each(function (Crawler $cell) use (&$cells) {
                            $cells[] = $this->cleanText($cell->text(''));
                        });
                        if (count($cells) >= 2 && !empty($cells[0])) {
                            $key = $cells[0];
                            $value = $cells[1];
                            // Skip header rows
                            if (mb_strtolower($key) !== 'parameter' && mb_strtolower($key) !== 'value') {
                                $specs[$key] = $value;
                            }
                        }
                    });
                });
            } catch (\Exception) {
                continue;
            }
            if (!empty($specs)) {
                break;
            }
        }

        // Strategy 2: <dl> definition lists
        if (empty($specs)) {
            $dlSelectors = [
                '.product-attributes dl',
                '.product-params dl',
                '.specifications dl',
                '.params dl',
                'dl.attributes',
                'dl.params',
                'dl',
            ];

            foreach ($dlSelectors as $sel) {
                try {
                    $crawler->filter($sel)->each(function (Crawler $dl) use (&$specs) {
                        $dts = $dl->filter('dt');
                        $dds = $dl->filter('dd');
                        $count = min($dts->count(), $dds->count());
                        for ($i = 0; $i < $count; $i++) {
                            $key = $this->cleanText($dts->eq($i)->text(''));
                            $value = $this->cleanText($dds->eq($i)->text(''));
                            if (!empty($key)) {
                                $specs[$key] = $value;
                            }
                        }
                    });
                } catch (\Exception) {
                    continue;
                }
                if (!empty($specs)) {
                    break;
                }
            }
        }

        // Strategy 3: div-based key/value pairs
        if (empty($specs)) {
            $divSelectors = [
                '.product-attributes .attribute',
                '.product-params .param',
                '.specifications .spec-row',
                '.attribute-list .attribute-item',
                '.params-list .param-item',
            ];

            foreach ($divSelectors as $sel) {
                try {
                    $crawler->filter($sel)->each(function (Crawler $node) use (&$specs) {
                        $text = $this->cleanText($node->text(''));
                        // Try to split "Label: Value" or "Label Value"
                        if (preg_match('/^(.+?):\s*(.+)$/', $text, $m)) {
                            $specs[trim($m[1])] = trim($m[2]);
                        }
                    });
                } catch (\Exception) {
                    continue;
                }
                if (!empty($specs)) {
                    break;
                }
            }
        }

        // Strategy 4: JSON-LD additionalProperty
        if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $jsonMatches)) {
            foreach ($jsonMatches[1] as $jsonStr) {
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                $props = $data['additionalProperty'] ?? [];
                if (isset($data['@graph'])) {
                    foreach ($data['@graph'] as $node) {
                        if (is_array($node) && isset($node['additionalProperty'])) {
                            $props = array_merge($props, $node['additionalProperty']);
                        }
                    }
                }

                foreach ($props as $prop) {
                    if (isset($prop['name'], $prop['value'])) {
                        $specs[$prop['name']] = (string)$prop['value'];
                    }
                }
            }
        }

        // Strategy 5: Parse from the short description text
        // e.g. "Brand: Specna Arms color: Black weight [g]: 2735 made from: Metal, Steel"
        if (empty($specs)) {
            $shortDesc = $this->nodeText($crawler, '[itemprop="description"]');
            if (!empty($shortDesc)) {
                // Split on known parameter patterns
                if (preg_match_all('/\b(Brand|color|weight\s*\[?\w*\]?|made from|Product code|Length|FPS|Magazine capacity|Caliber|Power source|Battery|Hop-up)\s*:\s*([^:]+?)(?=\s+\w+\s*:|$)/i', $shortDesc, $m, PREG_SET_ORDER)) {
                    foreach ($m as $match) {
                        $specs[trim($match[1])] = trim($match[2]);
                    }
                }
            }
        }

        return $specs;
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

    private function parseImages(Crawler $crawler, string $html): array
    {
        $images = [];

        // Strategy 1: All img tags with product image attributes (don't break — collect from ALL selectors)
        $imgSelectors = [
            '.product-gallery img',
            '.product-images img',
            '.product__gallery img',
            '.gallery img',
            '.swiper img',
            '.slider img',
            '.carousel img',
            '.product-photo img',
            '.product-image img',
            '.photos img',
            '.lightbox img',
            '.fancybox img',
            '[itemprop="image"]',
        ];

        foreach ($imgSelectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$images) {
                    foreach (['src', 'data-src', 'data-lazy', 'data-original', 'data-zoom', 'data-large', 'data-full', 'data-image'] as $attr) {
                        $val = $node->attr($attr) ?? '';
                        if ($this->isValidImageUrl($val)) {
                            $images[] = $this->absoluteUrl($val);
                        }
                    }
                    $srcset = $node->attr('srcset') ?? '';
                    if (!empty($srcset)) {
                        foreach (explode(',', $srcset) as $entry) {
                            $url = trim(explode(' ', trim($entry))[0]);
                            if ($this->isValidImageUrl($url)) {
                                $images[] = $this->absoluteUrl($url);
                            }
                        }
                    }
                });
            } catch (\Exception) {
                continue;
            }
        }

        // Strategy 2: <a> tags wrapping gallery thumbnails (href = full-size image)
        $linkSelectors = [
            '.product-gallery a[href]',
            '.gallery a[href]',
            '.photos a[href]',
            'a.lightbox[href]',
            'a.fancybox[href]',
            'a[data-fancybox] ',
            'a[data-lightbox]',
            'a[rel="gallery"]',
        ];

        foreach ($linkSelectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$images) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isValidImageUrl($href)) {
                        $images[] = $this->absoluteUrl($href);
                    }
                    $dataHref = $node->attr('data-href') ?? $node->attr('data-src') ?? $node->attr('data-image') ?? '';
                    if ($this->isValidImageUrl($dataHref)) {
                        $images[] = $this->absoluteUrl($dataHref);
                    }
                });
            } catch (\Exception) {
                continue;
            }
        }

        // Strategy 3: Raw HTML — find all gunfire CDN image URLs
        // Pattern: /hpeciai/HASH/eng_pl_NAME_N.webp (or .jpg/.png)
        if (preg_match_all('#https?://[^"\'\s]+/hpeciai/[^"\'\s]+\.(?:webp|jpg|jpeg|png)#i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                if ($this->isValidImageUrl($url)) {
                    $images[] = $url;
                }
            }
        }

        // Strategy 4: Any image URL matching gunfire product pattern
        if (preg_match_all('#https?://(?:gunfire\.com|[^"\'\s]*gunfire[^"\'\s]*)/[^"\'\s]+(?:_\d+)\.(?:webp|jpg|jpeg|png)#i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                if ($this->isValidImageUrl($url)) {
                    $images[] = $url;
                }
            }
        }

        // Strategy 5: JSON-LD images
        if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $jsonMatches)) {
            foreach ($jsonMatches[1] as $jsonStr) {
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }
                // "image" can be string or array
                $jsonImages = $data['image'] ?? [];
                if (is_string($jsonImages)) {
                    $jsonImages = [$jsonImages];
                }
                foreach ($jsonImages as $img) {
                    if (is_string($img) && $this->isValidImageUrl($img)) {
                        $images[] = $img;
                    }
                }
            }
        }

        // Strategy 6: JS variables / data attributes containing image arrays
        // e.g., data-images='["url1","url2"]' or var images = ["url1","url2"]
        if (preg_match_all('/data-images\s*=\s*["\'](\[.*?\])["\']/', $html, $matches)) {
            foreach ($matches[1] as $jsonArr) {
                $arr = json_decode(html_entity_decode($jsonArr), true);
                if (is_array($arr)) {
                    foreach ($arr as $url) {
                        if (is_string($url) && $this->isValidImageUrl($url)) {
                            $images[] = $this->absoluteUrl($url);
                        }
                    }
                }
            }
        }

        // Strategy 7: og:image (always grab as baseline)
        $ogImage = $this->nodeAttr($crawler, 'meta[property="og:image"]', 'content');
        if (!empty($ogImage) && $this->isValidImageUrl($ogImage)) {
            $images[] = $ogImage;
        }

        // Strategy 8: Enumerate _N variants from found images
        // If we have _1.webp, try _2, _3, ... _15
        $images = array_values(array_unique($images));
        $enumerated = $this->enumerateImageVariants($images);
        $images = array_merge($images, $enumerated);

        // Deduplicate, filter thumbnails, sort
        $images = $this->filterAndSortImages($images);

        return $images;
    }

    private function isValidImageUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        if (str_starts_with($url, 'data:')) {
            return false;
        }
        if (str_contains($url, 'placeholder') || str_contains($url, '1x1') || str_contains($url, 'blank.')) {
            return false;
        }
        if (str_contains($url, 'logo') || str_contains($url, 'icon') || str_contains($url, 'favicon')) {
            return false;
        }
        if (str_contains($url, 'banner') || str_contains($url, 'promo') || str_contains($url, 'advert')) {
            return false;
        }
        return (bool)preg_match('/\.(?:webp|jpg|jpeg|png|gif)(\?.*)?$/i', $url);
    }

    /**
     * Given images like name_1.webp, try name_2.webp ... name_15.webp
     * These won't be HTTP-checked here — just generated as candidates.
     */
    private function enumerateImageVariants(array $images): array
    {
        $variants = [];
        $basesChecked = [];

        foreach ($images as $url) {
            // Match pattern: something_1.ext or something_01.ext
            if (preg_match('/^(.+_)(\d+)(\.(?:webp|jpg|jpeg|png))(\?.*)?$/i', $url, $m)) {
                $base = $m[1];
                $ext = $m[3];
                $query = $m[4] ?? '';

                if (isset($basesChecked[$base])) {
                    continue;
                }
                $basesChecked[$base] = true;

                for ($i = 1; $i <= 15; $i++) {
                    $variant = $base . $i . $ext . $query;
                    $variants[] = $variant;
                }
            }
        }

        return $variants;
    }

    private function filterAndSortImages(array $images): array
    {
        $images = array_unique($images);

        // Prefer full-size over thumbnails
        $fullSize = [];
        $thumbs = [];

        foreach ($images as $url) {
            if (preg_match('/thumb|small|mini|tiny|\d+x\d+/i', $url)) {
                $thumbs[] = $url;
            } else {
                $fullSize[] = $url;
            }
        }

        // If we have full-size images, skip thumbnails
        $result = !empty($fullSize) ? $fullSize : $thumbs;

        // Sort by _N index for consistent ordering
        usort($result, function (string $a, string $b) {
            $numA = 0;
            $numB = 0;
            if (preg_match('/_(\d+)\./', $a, $m)) {
                $numA = (int)$m[1];
            }
            if (preg_match('/_(\d+)\./', $b, $m)) {
                $numB = (int)$m[1];
            }
            return $numA <=> $numB;
        });

        return array_values($result);
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
