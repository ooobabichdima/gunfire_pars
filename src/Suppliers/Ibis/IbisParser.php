<?php

declare(strict_types=1);

namespace App\Suppliers\Ibis;

use App\Suppliers\AbstractSupplierParser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * IBIS.net.ua parser — B2B supplier.
 *
 * Data sources:
 * 1. XLS price lists from obmen.ibis.net.ua (prices, stock, SKU)
 * 2. Product pages from ibis.net.ua (photos, descriptions, specs)
 *
 * URL patterns:
 *   Categories: /zbroia/, /zbroia/straykbolna-zbroya/
 *   Products:   /zbroia/details/{slug}/ or /products/details/{slug}/
 *   Brands:     /brand/{slug}/
 */
final class IbisParser extends AbstractSupplierParser
{
    public function getSupplierCode(): string
    {
        return 'ibis';
    }

    // ------------------------------------------------------------------
    // scanCategories — returns category URLs from the site
    // ------------------------------------------------------------------
    public function scanCategories(): array
    {
        $this->logger->info('Scanning categories from IBIS...');
        $urls = [];

        $startPages = [
            $this->getBaseUrl() . '/zbroia/',
            $this->getBaseUrl() . '/ua/products/',
        ];

        foreach ($startPages as $pageUrl) {
            $html = $this->http->getHtml($pageUrl);
            if ($html === null) {
                continue;
            }

            $crawler = $this->createCrawler($html);
            try {
                $crawler->filter('a[href]')->each(function (Crawler $node) use (&$urls) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isCategoryUrl($href)) {
                        $urls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {}
        }

        // Seed categories
        $seeds = [
            '/zbroia/', '/zbroia/straykbolna-zbroya/', '/zbroia/pnevmatika/',
            '/zbroia/vohnepalna-zbroia/', '/zbroia/prytsily/', '/zbroia/patrony/',
            '/zbroia/korotkostvolna-zbroya/', '/zbroia/pompovi/',
        ];
        foreach ($seeds as $seed) {
            $urls[] = $this->getBaseUrl() . $seed;
        }

        $urls = array_values(array_unique($urls));
        $this->logger->info("Found " . count($urls) . " categories");
        return $urls;
    }

    // ------------------------------------------------------------------
    // scanListings — paginate a category, collect product URLs
    // ------------------------------------------------------------------
    public function scanListings(string $categoryUrl, int $limit = 0): array
    {
        $urls = [];
        $page = 1;

        while ($page <= 200) {
            $pageUrl = $page > 1 ? (rtrim($categoryUrl, '/') . '/?page=' . $page) : $categoryUrl;
            $html = $this->http->getHtml($pageUrl);
            if ($html === null) {
                break;
            }

            $crawler = $this->createCrawler($html);
            $pageUrls = [];

            try {
                $crawler->filter('a[href]')->each(function (Crawler $node) use (&$pageUrls) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isProductUrl($href)) {
                        $pageUrls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {}

            if (empty($pageUrls)) {
                break;
            }

            foreach ($pageUrls as $url) {
                $urls[] = $url;
                if ($limit > 0 && count($urls) >= $limit) {
                    break 2;
                }
            }

            $this->logger->debug("IBIS page {$page}: +" . count($pageUrls) . " products");

            // Check for next page
            $hasNext = false;
            try {
                $crawler->filter('a[href]')->each(function (Crawler $node) use (&$hasNext, $page) {
                    $href = $node->attr('href') ?? '';
                    if (str_contains($href, 'page=' . ($page + 1))) {
                        $hasNext = true;
                    }
                });
            } catch (\Exception) {}

            if (!$hasNext) {
                break;
            }

            $page++;
        }

        return array_values(array_unique($urls));
    }

    // ------------------------------------------------------------------
    // parseProduct — parse a single product page (photos, description, specs)
    // ------------------------------------------------------------------
    public function parseProduct(string $url): ?array
    {
        $this->logger->debug("Parsing IBIS product: {$url}");

        $html = $this->http->getHtml($url);
        if ($html === null) {
            return null;
        }

        $crawler = $this->createCrawler($html);
        $data = [];

        $data['url'] = $url;
        $data['external_id'] = $this->extractIbisId($url, $html);

        $data['name'] = $this->parseName($crawler, $html);
        if (empty($data['name'])) {
            $this->logger->warning("Could not extract product name: {$url}");
            return null;
        }

        $data['brand'] = $this->parseBrand($crawler, $html);
        $data['external_sku'] = $this->parseSku($crawler, $html, $data['name']);
        $data['ean'] = $this->parseEan($crawler, $html);
        $data['model'] = $this->parseModel($crawler, $data['name'], $data['brand']);

        $prices = $this->parsePrices($crawler, $html);
        $data['price_purchase'] = $prices['current'];
        $data['price_regular'] = $prices['regular'];
        $data['currency'] = $this->config['currency'] ?? 'UAH';

        $data['availability'] = $this->parseAvailability($crawler, $html);
        $data['stock_qty_text'] = '';

        $description = $this->parseDescription($crawler, $html);
        $specifications = $this->parseSpecifications($crawler, $html);
        $data['is_bundle'] = $this->isBundle($data['name'], $description) ? 1 : 0;

        $breadcrumbs = $this->parseBreadcrumbs($crawler);
        $images = $this->parseImages($crawler, $html);

        $data['raw_data'] = [
            'description'    => mb_substr($description, 0, 10000),
            'specifications' => $specifications,
            'breadcrumbs'    => $breadcrumbs,
            'images'         => $images,
        ];

        $data['is_active'] = 1;
        $data['last_price_check_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    // ------------------------------------------------------------------
    // checkPrice — lightweight price check
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
                'currency'       => $this->config['currency'] ?? 'UAH',
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
            'currency'       => $this->config['currency'] ?? 'UAH',
            'availability'   => $this->parseAvailability($crawler, $html),
            'is_active'      => true,
        ];
    }

    // ------------------------------------------------------------------
    // XLS import — import prices/stock from downloaded XLS files
    // ------------------------------------------------------------------
    public function importXls(string $filePath): array
    {
        $this->logger->info("Importing XLS: {$filePath}");

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException("PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet");
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // Auto-detect header row
        $headerRow = null;
        $columnMap = [];
        foreach ($rows as $rowIndex => $row) {
            $rowText = mb_strtolower(implode(' ', array_filter($row)));
            if (str_contains($rowText, 'назва') || str_contains($rowText, 'название')
                || str_contains($rowText, 'ціна') || str_contains($rowText, 'цена')
                || str_contains($rowText, 'артикул') || str_contains($rowText, 'код')) {
                $headerRow = $rowIndex;
                foreach ($row as $col => $val) {
                    $val = mb_strtolower(trim((string)$val));
                    if (str_contains($val, 'назва') || str_contains($val, 'название') || str_contains($val, 'name')) {
                        $columnMap['name'] = $col;
                    }
                    if (str_contains($val, 'артикул') || str_contains($val, 'код') || str_contains($val, 'code') || str_contains($val, 'sku')) {
                        $columnMap['sku'] = $col;
                    }
                    if (str_contains($val, 'ціна') || str_contains($val, 'цена') || str_contains($val, 'price')) {
                        if (!isset($columnMap['price'])) {
                            $columnMap['price'] = $col;
                        }
                    }
                    if (str_contains($val, 'рроздр') || str_contains($val, 'роздріб') || str_contains($val, 'retail')) {
                        $columnMap['price_retail'] = $col;
                    }
                    if (str_contains($val, 'кільк') || str_contains($val, 'залишок') || str_contains($val, 'qty') || str_contains($val, 'stock')) {
                        $columnMap['qty'] = $col;
                    }
                    if (str_contains($val, 'бренд') || str_contains($val, 'brand') || str_contains($val, 'виробник')) {
                        $columnMap['brand'] = $col;
                    }
                    if (str_contains($val, 'ean') || str_contains($val, 'штрих')) {
                        $columnMap['ean'] = $col;
                    }
                    if (str_contains($val, 'категор') || str_contains($val, 'group')) {
                        $columnMap['category'] = $col;
                    }
                }
                break;
            }
        }

        if ($headerRow === null || empty($columnMap['name'])) {
            $this->logger->error("Could not detect header row in XLS");
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'column_map' => $columnMap];
        }

        $this->logger->info("Detected columns: " . json_encode($columnMap));

        $stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerRow) {
                continue;
            }

            $name = trim((string)($row[$columnMap['name']] ?? ''));
            if (empty($name) || mb_strlen($name) < 3) {
                $stats['skipped']++;
                continue;
            }

            try {
                $sku = isset($columnMap['sku']) ? trim((string)($row[$columnMap['sku']] ?? '')) : '';
                $price = isset($columnMap['price']) ? $this->parsePrice((string)($row[$columnMap['price']] ?? '')) : null;
                $priceRetail = isset($columnMap['price_retail']) ? $this->parsePrice((string)($row[$columnMap['price_retail']] ?? '')) : null;
                $qty = isset($columnMap['qty']) ? trim((string)($row[$columnMap['qty']] ?? '')) : '';
                $brand = isset($columnMap['brand']) ? trim((string)($row[$columnMap['brand']] ?? '')) : '';
                $ean = isset($columnMap['ean']) ? trim((string)($row[$columnMap['ean']] ?? '')) : '';

                $externalId = !empty($sku) ? $sku : 'ibis_row_' . $rowIndex;
                $availability = 'unknown';
                if (!empty($qty)) {
                    $qtyNum = (int)preg_replace('/\D/', '', $qty);
                    $availability = $qtyNum > 0 ? 'in_stock' : 'out_of_stock';
                }

                $offerData = [
                    'supplier_id'        => $this->supplierId,
                    'external_id'        => $externalId,
                    'external_sku'       => $sku,
                    'name'               => $name,
                    'brand'              => $brand,
                    'ean'                => $ean ?: null,
                    'price_purchase'     => $price,
                    'price_regular'      => $priceRetail ?? $price,
                    'currency'           => $this->config['currency'] ?? 'UAH',
                    'availability'       => $availability,
                    'stock_qty_text'     => $qty,
                    'is_active'          => ($price !== null && $price > 0) ? 1 : 0,
                    'last_seen_at'       => $now,
                    'last_price_check_at' => $now,
                    'updated_at'         => $now,
                    'raw_data'           => [
                        'xls_file'   => basename($filePath),
                        'xls_row'    => $rowIndex,
                        'category'   => isset($columnMap['category']) ? trim((string)($row[$columnMap['category']] ?? '')) : '',
                        'full_row'   => array_filter($row),
                    ],
                ];

                $this->saveOffer($offerData);
                $stats['imported']++;

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error("XLS row {$rowIndex} error: {$e->getMessage()}");
            }
        }

        $this->logger->info("XLS import complete", $stats);
        return $stats;
    }

    /**
     * Download XLS files from obmen.ibis.net.ua.
     */
    public function downloadXlsFiles(string $targetDir): array
    {
        $baseUrl = $this->config['xls_base_url'] ?? '';
        $login = $this->config['xls_auth_login'] ?? '';
        $password = $this->config['xls_auth_password'] ?? '';

        if (empty($baseUrl)) {
            throw new \RuntimeException("xls_base_url not configured for IBIS");
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $files = $this->config['xls_files'] ?? [
            'Вільні залишки Київ.xls',
            'Вільні залишки Борислав.xls',
            'Вільні залишки збройові аксесуари.xls',
        ];

        $downloaded = [];
        foreach ($files as $file) {
            $url = rtrim($baseUrl, '/') . '/' . rawurlencode($file);
            $localPath = $targetDir . '/' . $file;

            $this->logger->info("Downloading: {$url}");

            $options = [];
            if (!empty($login)) {
                $options['auth'] = [$login, $password];
            }

            $response = $this->http->get($url, $options);
            if ($response === null || $response->getStatusCode() !== 200) {
                $this->logger->error("Failed to download: {$file}");
                continue;
            }

            file_put_contents($localPath, (string)$response->getBody());
            $size = filesize($localPath);
            $this->logger->info("Downloaded {$file}: {$size} bytes");
            $downloaded[] = $localPath;
        }

        return $downloaded;
    }

    // ==================================================================
    //  Private helpers
    // ==================================================================

    private function extractIbisId(string $url, string $html): ?string
    {
        // IBIS uses 8-digit codes in page title: "... 23704089 — купити"
        if (preg_match('/\b(\d{7,10})\b\s*[—\-–]/', $html, $m)) {
            return $m[1];
        }
        // From URL slug
        $slug = basename(rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/'));
        if (!empty($slug)) {
            return 'ibis_' . $slug;
        }
        return null;
    }

    private function parseName(Crawler $crawler, string $html): string
    {
        $selectors = ['h1[itemprop="name"]', 'h1.product-name', 'h1.product-title', 'h1'];

        foreach ($selectors as $sel) {
            $name = $this->nodeText($crawler, $sel);
            if (!empty($name) && mb_strlen($name) > 2 && mb_strlen($name) < 300) {
                $name = preg_replace('/\s*\d{7,10}\s*[—\-–]\s*купити.*$/iu', '', $name) ?? $name;
                return trim($name);
            }
        }

        $ogTitle = $this->nodeAttr($crawler, 'meta[property="og:title"]', 'content');
        if (!empty($ogTitle)) {
            $ogTitle = preg_replace('/\s*\d{7,10}\s*[—\-–]\s*купити.*$/iu', '', $ogTitle) ?? $ogTitle;
            $ogTitle = preg_replace('/\s*[|—\-–]\s*ІБІС.*$/iu', '', $ogTitle) ?? $ogTitle;
            return $this->cleanText($ogTitle);
        }

        return '';
    }

    private function parseBrand(Crawler $crawler, string $html): string
    {
        $selectors = [
            '[itemprop="brand"] [itemprop="name"]',
            '[itemprop="brand"]',
            'a[href*="/brand/"]',
            '.product-brand', '.brand-name',
        ];

        foreach ($selectors as $sel) {
            $brand = $this->nodeText($crawler, $sel);
            if (!empty($brand) && mb_strlen($brand) > 1 && mb_strlen($brand) < 80) {
                return $brand;
            }
        }

        $brand = $this->extractFromJsonLd($html, 'brand');
        if (!empty($brand)) {
            return $brand;
        }

        return '';
    }

    private function parseSku(Crawler $crawler, string $html, string $name): string
    {
        $selectors = ['[itemprop="sku"]', '.product-sku', '[data-sku]'];

        foreach ($selectors as $sel) {
            $sku = $this->nodeText($crawler, $sel);
            if (empty($sku)) $sku = $this->nodeAttr($crawler, $sel, 'content');
            if (!empty($sku)) return trim($sku);
        }

        // From title: "ASG CZ Shadow 2 (19307) 23704089"
        if (preg_match('/\((\w{3,15})\)/', $name, $m)) {
            return $m[1];
        }

        $sku = $this->extractFromJsonLd($html, 'sku');
        if (!empty($sku)) return $sku;

        return '';
    }

    private function parseEan(Crawler $crawler, string $html): string
    {
        $selectors = ['[itemprop="gtin13"]', '[itemprop="gtin"]'];
        foreach ($selectors as $sel) {
            $ean = $this->nodeText($crawler, $sel);
            if (empty($ean)) $ean = $this->nodeAttr($crawler, $sel, 'content');
            if (!empty($ean) && preg_match('/^\d{8,14}$/', trim($ean))) return trim($ean);
        }

        if (preg_match('/\bEAN[\s:]*(\d{13})\b/i', $html, $m)) return $m[1];

        return '';
    }

    private function parseModel(Crawler $crawler, string $name, string $brand): string
    {
        $model = $this->nodeText($crawler, '[itemprop="model"]');
        if (!empty($model)) return $model;

        if (!empty($brand) && !empty($name)) {
            $work = trim(str_ireplace($brand, '', $name));
            if (preg_match('/\b([A-Z]{1,5}[\-\.]?\d{1,5}[A-Z]?\d{0,3})\b/i', $work, $m)) {
                return strtoupper($m[1]);
            }
        }

        return '';
    }

    private function parsePrices(Crawler $crawler, string $html): array
    {
        $result = ['current' => null, 'regular' => null];

        $jsonPrice = $this->extractFromJsonLd($html, 'price');
        if (!empty($jsonPrice)) {
            $result['current'] = $this->parsePrice($jsonPrice);
        }

        if ($result['current'] === null) {
            $priceText = $this->nodeAttr($crawler, '[itemprop="price"]', 'content');
            if (!empty($priceText)) {
                $result['current'] = $this->parsePrice($priceText);
            }
        }

        if ($result['current'] === null) {
            $selectors = ['.product-price', '.price', '.current-price', '.price-current'];
            foreach ($selectors as $sel) {
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

        if ($result['current'] === null) {
            if (preg_match('/(\d[\d\s,.]*\d)\s*(?:грн|UAH|₴)/i', $html, $m)) {
                $result['current'] = $this->parsePrice($m[1]);
            }
        }

        if ($result['regular'] === null) {
            $result['regular'] = $result['current'];
        }

        return $result;
    }

    private function parseAvailability(Crawler $crawler, string $html): string
    {
        $jsonAvail = $this->extractFromJsonLd($html, 'availability');
        if (!empty($jsonAvail)) {
            if (str_contains($jsonAvail, 'InStock')) return 'in_stock';
            if (str_contains($jsonAvail, 'OutOfStock')) return 'out_of_stock';
        }

        $selectors = ['[itemprop="availability"]', '.availability', '.stock-status'];
        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text)) {
                $lower = mb_strtolower($text);
                if (str_contains($lower, 'є в наявності') || str_contains($lower, 'в наличии') || str_contains($lower, 'in stock')) return 'in_stock';
                if (str_contains($lower, 'немає') || str_contains($lower, 'нет') || str_contains($lower, 'out of stock')) return 'out_of_stock';
                return $lower;
            }
        }

        return 'unknown';
    }

    private function parseDescription(Crawler $crawler, string $html): string
    {
        $selectors = [
            '[itemprop="description"]', '.product-description', '.description',
            '#description', '.product-text', '.tab-content',
        ];

        $best = '';
        foreach ($selectors as $sel) {
            $text = $this->nodeText($crawler, $sel);
            if (!empty($text) && mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        $ogDesc = $this->nodeAttr($crawler, 'meta[property="og:description"]', 'content');
        if (!empty($ogDesc) && mb_strlen($ogDesc) > mb_strlen($best)) {
            $best = $this->cleanText($ogDesc);
        }

        return $best;
    }

    private function parseSpecifications(Crawler $crawler, string $html): array
    {
        $specs = [];

        try {
            $crawler->filter('table tr')->each(function (Crawler $row) use (&$specs) {
                $cells = [];
                $row->filter('td, th')->each(function (Crawler $cell) use (&$cells) {
                    $cells[] = $this->cleanText($cell->text(''));
                });
                if (count($cells) >= 2 && !empty($cells[0])) {
                    $specs[$cells[0]] = $cells[1];
                }
            });
        } catch (\Exception) {}

        if (empty($specs)) {
            try {
                $crawler->filter('dl')->each(function (Crawler $dl) use (&$specs) {
                    $dts = $dl->filter('dt');
                    $dds = $dl->filter('dd');
                    for ($i = 0; $i < min($dts->count(), $dds->count()); $i++) {
                        $key = $this->cleanText($dts->eq($i)->text(''));
                        $val = $this->cleanText($dds->eq($i)->text(''));
                        if (!empty($key)) $specs[$key] = $val;
                    }
                });
            } catch (\Exception) {}
        }

        return $specs;
    }

    private function parseBreadcrumbs(Crawler $crawler): array
    {
        $breadcrumbs = [];
        $selectors = [
            '[itemprop="itemListElement"] [itemprop="name"]',
            '.breadcrumb a', '.breadcrumbs a', 'ol.breadcrumb a',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$breadcrumbs) {
                    $name = $this->cleanText($node->text(''));
                    $href = $node->attr('href') ?? '';
                    if (!empty($name) && mb_strtolower($name) !== 'головна') {
                        $breadcrumbs[] = ['name' => $name, 'url' => $href ? $this->absoluteUrl($href) : ''];
                    }
                });
            } catch (\Exception) { continue; }
            if (!empty($breadcrumbs)) break;
        }

        return $breadcrumbs;
    }

    private function parseImages(Crawler $crawler, string $html): array
    {
        $images = [];

        $selectors = [
            '.product-gallery img', '.product-images img', '.gallery img',
            '.product-photo img', '.swiper img', '[itemprop="image"]',
        ];

        foreach ($selectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$images) {
                    foreach (['src', 'data-src', 'data-lazy', 'data-original', 'data-zoom'] as $attr) {
                        $val = $node->attr($attr) ?? '';
                        if (!empty($val) && !str_starts_with($val, 'data:') && !str_contains($val, 'placeholder')) {
                            $images[] = $this->absoluteUrl($val);
                        }
                    }
                });
            } catch (\Exception) { continue; }
        }

        // Gallery links
        try {
            $crawler->filter('a[data-fancybox], a.lightbox, a[data-lightbox], a[rel="gallery"]')->each(function (Crawler $node) use (&$images) {
                $href = $node->attr('href') ?? '';
                if (!empty($href) && preg_match('/\.(jpg|jpeg|png|webp)/i', $href)) {
                    $images[] = $this->absoluteUrl($href);
                }
            });
        } catch (\Exception) {}

        // Raw HTML: ibis image CDN
        if (preg_match_all('#https?://[^"\'\s]+ibis[^"\'\s]+\.(?:jpg|jpeg|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $url) {
                if (!str_contains($url, 'logo') && !str_contains($url, 'icon')) {
                    $images[] = $url;
                }
            }
        }

        // og:image
        $ogImage = $this->nodeAttr($crawler, 'meta[property="og:image"]', 'content');
        if (!empty($ogImage)) {
            $images[] = $ogImage;
        }

        return array_values(array_unique($images));
    }

    private function extractFromJsonLd(string $html, string $field): string
    {
        if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonStr) {
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) continue;

                if (isset($data[$field])) {
                    if (is_string($data[$field])) return trim($data[$field]);
                    if (is_numeric($data[$field])) return (string)$data[$field];
                    if (is_array($data[$field]) && isset($data[$field]['name'])) return trim($data[$field]['name']);
                }

                if (isset($data['offers'])) {
                    $offers = isset($data['offers'][0]) ? $data['offers'] : [$data['offers']];
                    foreach ($offers as $offer) {
                        if (isset($offer[$field])) {
                            return is_string($offer[$field]) ? trim($offer[$field]) : (string)$offer[$field];
                        }
                    }
                }
            }
        }
        return '';
    }

    private function isCategoryUrl(string $href): bool
    {
        return (bool)preg_match('#/zbroia/[a-z0-9\-]+/?$#i', $href)
            || (bool)preg_match('#/(?:ua/)?products/[a-z0-9\-]+/?$#i', $href);
    }

    private function isProductUrl(string $href): bool
    {
        return (bool)preg_match('#/(?:zbroia|products)/details/[a-z0-9\-]+/?#i', $href);
    }
}
