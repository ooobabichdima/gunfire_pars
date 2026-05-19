<?php

declare(strict_types=1);

namespace App\Suppliers;

use App\Database;
use App\HttpClient;
use App\Logger;
use Symfony\Component\DomCrawler\Crawler;

abstract class AbstractSupplierParser implements SupplierParserInterface
{
    protected Database $db;
    protected HttpClient $http;
    protected Logger $logger;
    protected array $config;
    protected int $supplierId;

    public function __construct(Database $db, HttpClient $http, Logger $logger, array $config = [])
    {
        $this->db = $db;
        $this->http = $http;
        $this->logger = $logger;
        $this->config = $config;

        $this->supplierId = $this->resolveSupplierId();
    }

    protected function resolveSupplierId(): int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM suppliers WHERE code = ? AND is_active = 1',
            [$this->getSupplierCode()]
        );

        if ($row === null) {
            throw new \RuntimeException("Supplier '{$this->getSupplierCode()}' not found or inactive in DB");
        }

        return (int)$row['id'];
    }

    public function getSupplierId(): int
    {
        return $this->supplierId;
    }

    protected function createCrawler(string $html): Crawler
    {
        return new Crawler($html);
    }

    protected function getBaseUrl(): string
    {
        return rtrim($this->config['base_url'] ?? '', '/');
    }

    protected function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return $this->getBaseUrl() . '/' . ltrim($path, '/');
    }

    protected function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    protected function parsePrice(string $text): ?float
    {
        $text = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $text);
        if (preg_match('/(\d+\.?\d*)/', $text, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    protected function extractIdFromUrl(string $url, string $pattern): ?string
    {
        if (preg_match($pattern, $url, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function isBundle(string $name, string $description = ''): bool
    {
        $combined = mb_strtolower($name . ' ' . $description);
        $keywords = ['starter pack', ' set ', ' kit ', 'bundle', 'zestaw', 'combo', 'pack of'];
        foreach ($keywords as $keyword) {
            if (str_contains($combined, $keyword)) {
                return true;
            }
        }
        // Check if name ends with " set" or " kit"
        if (preg_match('/\b(set|kit)$/i', trim($name))) {
            return true;
        }
        return false;
    }

    protected function nodeText(Crawler $crawler, string $selector): string
    {
        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                return $this->cleanText($node->text(''));
            }
        } catch (\Exception) {
            // Selector not found
        }
        return '';
    }

    protected function nodeAttr(Crawler $crawler, string $selector, string $attr): string
    {
        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                return trim($node->attr($attr) ?? '');
            }
        } catch (\Exception) {
            // Selector not found
        }
        return '';
    }

    protected function nodeHtml(Crawler $crawler, string $selector): string
    {
        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                return $node->html('');
            }
        } catch (\Exception) {
            // Selector not found
        }
        return '';
    }

    /**
     * Upsert a supplier offer into the database.
     */
    public function saveOffer(array $data): int
    {
        $data['supplier_id'] = $this->supplierId;
        $data['last_seen_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        if (isset($data['raw_data']) && is_array($data['raw_data'])) {
            $data['raw_data_json'] = json_encode($data['raw_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            unset($data['raw_data']);
        }

        $updateColumns = [
            'name', 'brand', 'model', 'ean',
            'price_purchase', 'price_regular', 'currency',
            'availability', 'stock_qty_text',
            'delivery_cost', 'min_order_qty', 'order_multiple', 'lead_time_days',
            'is_bundle', 'is_active', 'raw_data_json',
            'last_seen_at', 'updated_at',
        ];

        // Filter only existing columns
        $allowedColumns = [
            'supplier_id', 'catalog_product_id', 'external_id', 'external_sku',
            'url', 'name', 'brand', 'model', 'ean',
            'price_purchase', 'price_regular', 'currency',
            'availability', 'stock_qty_text',
            'delivery_cost', 'min_order_qty', 'order_multiple', 'lead_time_days',
            'is_bundle', 'is_active', 'raw_data_json',
            'last_seen_at', 'last_price_check_at',
            'created_at', 'updated_at',
        ];

        $filtered = array_intersect_key($data, array_flip($allowedColumns));
        $updateCols = array_intersect($updateColumns, array_keys($filtered));

        return $this->db->upsert('supplier_offers', $filtered, $updateCols);
    }
}
