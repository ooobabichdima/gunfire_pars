<?php

declare(strict_types=1);

namespace App\Suppliers\Ibis;

use App\Suppliers\AbstractSupplierParser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * IBIS.net.ua parser — all product data comes from JSON-LD @graph.
 *
 * Data sources:
 * 1. Product pages: JSON-LD Product schema (name, brand, sku, price, images, description)
 * 2. XLS files from obmen.ibis.net.ua (wholesale prices, stock quantities)
 */
final class IbisParser extends AbstractSupplierParser
{
    public function getSupplierCode(): string
    {
        return 'ibis';
    }

    public function scanCategories(): array
    {
        $this->logger->info('Scanning categories from IBIS...');
        $urls = [];

        $seeds = [
            '/zbroia/', '/zbroia/straykbolna-zbroya/', '/zbroia/pnevmatika/',
            '/zbroia/vohnepalna-zbroia/', '/zbroia/prytsily/', '/zbroia/patrony/',
            '/zbroia/korotkostvolna-zbroya/', '/zbroia/pompovi/',
            '/zbroia/hvyntivky-karabiny/', '/zbroia/rushnytsi/',
        ];

        foreach ($seeds as $seed) {
            $urls[] = $this->getBaseUrl() . $seed;
        }

        // Try to crawl main page for more categories
        $html = $this->http->getHtml($this->getBaseUrl() . '/zbroia/');
        if ($html !== null) {
            $crawler = $this->createCrawler($html);
            try {
                $crawler->filter('a[href*="/zbroia/"]')->each(function (Crawler $node) use (&$urls) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isCategoryUrl($href)) {
                        $urls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {}
        }

        $urls = array_values(array_unique($urls));
        $this->logger->info("Found " . count($urls) . " categories");
        return $urls;
    }

    public function scanListings(string $categoryUrl, int $limit = 0): array
    {
        $urls = [];
        $page = 1;

        while ($page <= 200) {
            $pageUrl = $page > 1 ? (rtrim($categoryUrl, '/') . '/?page=' . $page) : $categoryUrl;
            $html = $this->http->getHtml($pageUrl);
            if ($html === null) break;

            $crawler = $this->createCrawler($html);
            $pageUrls = [];

            try {
                $crawler->filter('a[href*="/details/"]')->each(function (Crawler $node) use (&$pageUrls) {
                    $href = $node->attr('href') ?? '';
                    if ($this->isProductUrl($href)) {
                        $pageUrls[] = $this->absoluteUrl($href);
                    }
                });
            } catch (\Exception) {}

            $pageUrls = array_unique($pageUrls);
            if (empty($pageUrls)) break;

            foreach ($pageUrls as $url) {
                $urls[] = $url;
                if ($limit > 0 && count($urls) >= $limit) break 2;
            }

            // Check next page
            $hasNext = str_contains($html, 'page=' . ($page + 1));
            if (!$hasNext) break;
            $page++;
        }

        return array_values(array_unique($urls));
    }

    public function parseProduct(string $url): ?array
    {
        $this->logger->debug("Parsing IBIS product: {$url}");

        $html = $this->http->getHtml($url);
        if ($html === null) return null;

        // Extract JSON-LD Product from @graph
        $product = $this->extractProductFromJsonLd($html);
        if ($product === null) {
            $this->logger->warning("No JSON-LD Product found: {$url}");
            return null;
        }

        $offers = $product['offers'] ?? [];
        if (isset($offers['@type'])) $offers = [$offers];

        $offer = $offers[0] ?? [];

        $description = $product['description'] ?? '';
        // Strip HTML tags from description
        $description = strip_tags(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);

        // Images from JSON-LD
        $images = $product['image'] ?? [];
        if (is_string($images)) $images = [$images];

        // Additional description from #details-description
        $crawler = $this->createCrawler($html);
        $domDescription = '';
        try {
            $node = $crawler->filter('#details-description');
            if ($node->count() > 0) {
                $domDescription = $this->cleanText($node->text(''));
            }
        } catch (\Exception) {}

        if (mb_strlen($domDescription) > mb_strlen($description)) {
            $description = $domDescription;
        }

        // Tab panel content (tab 0 = "Усе про товар")
        try {
            $crawler->filter('[id^="headlessui-tabs-panel"]')->each(function (Crawler $panel) use (&$description) {
                $text = $this->cleanText($panel->text(''));
                if (mb_strlen($text) > mb_strlen($description) && mb_strlen($text) > 100) {
                    $description = $text;
                }
            });
        } catch (\Exception) {}

        // Extract IBIS code and manufacturer SKU from page title
        // Pattern: "Назва (16722) 23704777 — купити"
        $ibisCode = $product['sku'] ?? '';
        $mfrSku = '';
        $pageTitle = $this->nodeAttr($crawler, 'meta[property="og:title"]', 'content');
        if (preg_match('/\((\w{3,15})\)\s*(\d{7,10})/', $pageTitle, $m)) {
            $mfrSku = $m[1];
            if (empty($ibisCode)) $ibisCode = $m[2];
        }

        // Breadcrumbs from JSON-LD
        $breadcrumbs = $this->extractBreadcrumbsFromJsonLd($html);

        // Specifications from tab panel or page
        $specifications = [];
        // Try regex patterns for Ukrainian specs
        $specPatterns = [
            'Калібр' => '/Калібр[\s:]*([^<\n]{2,60})/u',
            'Вага' => '/Вага(?:\s*\([^)]*\))?[\s:]*([^<\n]{2,40})/u',
            'Довжина' => '/Довжина(?:\s*\([^)]*\))?[\s:]*([^<\n]{2,40})/u',
            'Тип боєприпасу' => '/Тип боєприпасу[\s:]*([^<\n]{2,60})/u',
            'Матеріал' => '/Матеріал[\s:]*([^<\n]{2,60})/u',
            'Ємність магазину' => '/(?:Ємність|Місткість)\s*магазину[\s:]*([^<\n]{2,40})/u',
            'Потужність' => '/Потужність[\s:]*([^<\n]{2,40})/u',
        ];
        foreach ($specPatterns as $label => $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $val = trim(strip_tags($m[1]));
                if (!empty($val) && mb_strlen($val) < 60) {
                    $specifications[$label] = $val;
                }
            }
        }

        $data = [
            'url'              => $url,
            'external_id'      => $ibisCode ?: $this->extractIdFromSlug($url),
            'external_sku'     => $mfrSku ?: $ibisCode,
            'name'             => $product['name'] ?? '',
            'brand'            => is_string($product['brand'] ?? null) ? $product['brand'] : ($product['brand']['name'] ?? ''),
            'ean'              => '',
            'model'            => '',
            'price_purchase'   => isset($offer['price']) ? (float)$offer['price'] : null,
            'price_regular'    => isset($offer['price']) ? (float)$offer['price'] : null,
            'currency'         => $offer['priceCurrency'] ?? 'UAH',
            'availability'     => $this->mapAvailability($offer['availability'] ?? ''),
            'stock_qty_text'   => '',
            'is_bundle'        => $this->isBundle($product['name'] ?? '', $description) ? 1 : 0,
            'is_active'        => 1,
            'last_price_check_at' => date('Y-m-d H:i:s'),
            'raw_data'         => [
                'description'    => mb_substr($description, 0, 30000),
                'specifications' => $specifications,
                'breadcrumbs'    => $breadcrumbs,
                'images'         => array_slice($images, 0, 15),
                'manufacturer_sku' => $mfrSku,
                'ibis_code'      => $ibisCode,
            ],
        ];

        return $data;
    }

    public function checkPrice(string $url): ?array
    {
        $response = $this->http->get($url);
        if ($response === null) return null;

        $statusCode = $response->getStatusCode();
        if ($statusCode === 404 || $statusCode === 410) {
            return ['price_purchase' => null, 'price_regular' => null, 'currency' => 'UAH', 'availability' => null, 'is_active' => false];
        }
        if ($statusCode >= 400) return null;

        $html = (string)$response->getBody();
        $product = $this->extractProductFromJsonLd($html);
        if ($product === null) return null;

        $offer = $product['offers'] ?? [];
        if (isset($offer['@type'])) $offer = [$offer];
        $offer = $offer[0] ?? [];

        return [
            'price_purchase' => isset($offer['price']) ? (float)$offer['price'] : null,
            'price_regular'  => isset($offer['price']) ? (float)$offer['price'] : null,
            'currency'       => $offer['priceCurrency'] ?? 'UAH',
            'availability'   => $this->mapAvailability($offer['availability'] ?? ''),
            'is_active'      => true,
        ];
    }

    /**
     * Import XLS price list (unchanged from original).
     */
    public function importXls(string $filePath): array
    {
        $this->logger->info("Importing XLS: {$filePath}");

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException("Run: composer require phpoffice/phpspreadsheet");
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headerRow = null;
        $columnMap = [];
        foreach ($rows as $rowIndex => $row) {
            $rowText = mb_strtolower(implode(' ', array_filter(array_map('strval', $row))));
            if (str_contains($rowText, 'назва') || str_contains($rowText, 'название')
                || str_contains($rowText, 'найменування')
                || str_contains($rowText, 'ціна') || str_contains($rowText, 'цена')) {
                $headerRow = $rowIndex;
                foreach ($row as $col => $val) {
                    $val = mb_strtolower(trim((string)($val ?? '')));
                    if (str_contains($val, 'найменування') || str_contains($val, 'назва') || str_contains($val, 'название') || str_contains($val, 'name')) {
                        $columnMap['name'] = $col;
                    }
                    if (str_contains($val, 'артикул') || str_contains($val, 'код') || str_contains($val, 'code') || str_contains($val, 'sku')) {
                        if (!isset($columnMap['sku'])) $columnMap['sku'] = $col;
                    }
                    if (str_contains($val, 'ціна') || str_contains($val, 'цена') || str_contains($val, 'price')) {
                        if (!isset($columnMap['price'])) $columnMap['price'] = $col;
                    }
                    if (str_contains($val, 'роздріб') || str_contains($val, 'retail') || str_contains($val, 'рроздр')) {
                        $columnMap['price_retail'] = $col;
                    }
                    if (str_contains($val, 'кільк') || str_contains($val, 'залишок') || str_contains($val, 'qty') || str_contains($val, 'stock') || str_contains($val, 'вільн')) {
                        $columnMap['qty'] = $col;
                    }
                    if (str_contains($val, 'бренд') || str_contains($val, 'brand') || str_contains($val, 'виробник')) {
                        $columnMap['brand'] = $col;
                    }
                    if (str_contains($val, 'ean') || str_contains($val, 'штрих')) {
                        $columnMap['ean'] = $col;
                    }
                    if (str_contains($val, 'категор') || str_contains($val, 'group') || str_contains($val, 'група')) {
                        $columnMap['category'] = $col;
                    }
                }
                break;
            }
        }

        if ($headerRow === null || empty($columnMap['name'])) {
            $this->logger->error("Could not detect header row. Found columns: " . json_encode($columnMap));
            // Dump first 5 rows for debugging
            $i = 0;
            foreach ($rows as $ri => $row) {
                if ($i++ >= 5) break;
                $this->logger->console("  Row {$ri}: " . json_encode(array_filter(array_map('strval', $row)), JSON_UNESCAPED_UNICODE));
            }
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'column_map' => $columnMap];
        }

        $this->logger->info("Header row: {$headerRow}, columns: " . json_encode($columnMap));

        $stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerRow) continue;

            $name = trim((string)($row[$columnMap['name']] ?? ''));
            if (empty($name) || mb_strlen($name) < 3) { $stats['skipped']++; continue; }

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

                $this->saveOffer([
                    'supplier_id'        => $this->supplierId,
                    'external_id'        => $externalId,
                    'external_sku'       => $sku,
                    'name'               => $name,
                    'brand'              => $brand,
                    'ean'                => $ean ?: null,
                    'price_purchase'     => $price,
                    'price_regular'      => $priceRetail ?? $price,
                    'currency'           => 'UAH',
                    'availability'       => $availability,
                    'stock_qty_text'     => $qty,
                    'is_active'          => ($price !== null && $price > 0) ? 1 : 0,
                    'last_seen_at'       => $now,
                    'last_price_check_at' => $now,
                    'updated_at'         => $now,
                    'raw_data'           => [
                        'xls_file' => basename($filePath),
                        'xls_row'  => $rowIndex,
                        'category' => isset($columnMap['category']) ? trim((string)($row[$columnMap['category']] ?? '')) : '',
                    ],
                ]);
                $stats['imported']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error("XLS row {$rowIndex}: {$e->getMessage()}");
            }
        }

        $this->logger->info("XLS import complete", $stats);
        return $stats;
    }

    public function downloadXlsFiles(string $targetDir): array
    {
        $baseUrl = $this->config['xls_base_url'] ?? '';
        $login = $this->config['xls_auth_login'] ?? '';
        $password = $this->config['xls_auth_password'] ?? '';

        if (empty($baseUrl)) throw new \RuntimeException("xls_base_url not configured");
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

        $files = $this->config['xls_files'] ?? [
            'Вільні залишки Київ.xls',
            'Вільні залишки Борислав.xls',
            'Вільні залишки збройові аксесуари.xls',
        ];

        $downloaded = [];
        foreach ($files as $file) {
            $url = rtrim($baseUrl, '/') . '/' . rawurlencode($file);
            $localPath = $targetDir . '/' . $file;

            $this->logger->console("  Downloading: {$file}");
            $options = !empty($login) ? ['auth' => [$login, $password]] : [];
            $response = $this->http->get($url, $options);

            if ($response === null || $response->getStatusCode() !== 200) {
                $this->logger->error("Failed to download: {$file}");
                continue;
            }

            file_put_contents($localPath, (string)$response->getBody());
            $this->logger->console("  OK: " . number_format(filesize($localPath)) . " bytes");
            $downloaded[] = $localPath;
        }

        return $downloaded;
    }

    // ==================================================================

    private function extractProductFromJsonLd(string $html): ?array
    {
        if (!preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $jsonStr) {
            $data = json_decode($jsonStr, true);
            if (!is_array($data)) continue;

            // Direct Product
            if (($data['@type'] ?? '') === 'Product') return $data;

            // Inside @graph
            if (isset($data['@graph'])) {
                foreach ($data['@graph'] as $node) {
                    if (is_array($node) && ($node['@type'] ?? '') === 'Product') {
                        return $node;
                    }
                }
            }
        }

        return null;
    }

    private function extractBreadcrumbsFromJsonLd(string $html): array
    {
        if (!preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $jsonStr) {
            $data = json_decode($jsonStr, true);
            if (!is_array($data)) continue;

            $list = null;
            if (($data['@type'] ?? '') === 'BreadcrumbList') $list = $data;

            if (isset($data['@graph'])) {
                foreach ($data['@graph'] as $node) {
                    if (is_array($node) && ($node['@type'] ?? '') === 'BreadcrumbList') {
                        $list = $node;
                        break;
                    }
                }
            }

            if ($list && isset($list['itemListElement'])) {
                $breadcrumbs = [];
                foreach ($list['itemListElement'] as $item) {
                    $name = $item['name'] ?? '';
                    if (!empty($name) && mb_strtolower($name) !== 'ібіс') {
                        $breadcrumbs[] = ['name' => $name, 'url' => $item['item'] ?? ''];
                    }
                }
                return $breadcrumbs;
            }
        }

        return [];
    }

    private function mapAvailability(string $schemaUrl): string
    {
        if (str_contains($schemaUrl, 'InStock')) return 'in_stock';
        if (str_contains($schemaUrl, 'OutOfStock')) return 'out_of_stock';
        if (str_contains($schemaUrl, 'PreOrder')) return 'preorder';
        return 'unknown';
    }

    private function extractIdFromSlug(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $slug = basename(rtrim($path, '/'));
        return !empty($slug) ? 'ibis_' . $slug : 'ibis_' . md5($url);
    }

    private function isCategoryUrl(string $href): bool
    {
        return (bool)preg_match('#/zbroia/[a-z0-9\-]+/?$#i', $href)
            && !str_contains($href, '/details/');
    }

    private function isProductUrl(string $href): bool
    {
        return (bool)preg_match('#/(?:zbroia|products)/details/[a-z0-9\-]+/?#i', $href);
    }
}
