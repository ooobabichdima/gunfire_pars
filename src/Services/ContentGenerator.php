<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;

final class ContentGenerator
{
    private Database $db;
    private Logger $logger;
    private string $apiKey;
    private string $apiModel;

    public function __construct(Database $db, Logger $logger, string $apiKey = '', string $apiModel = 'claude-sonnet-4-20250514')
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->apiModel = $apiModel;
    }

    public function generateForProduct(int $catalogProductId, string $templateCode): ?array
    {
        $product = $this->db->fetchOne(
            "SELECT cp.*,
                (SELECT GROUP_CONCAT(c.name SEPARATOR ' > ') FROM product_categories pc
                 JOIN categories c ON c.id = pc.category_id WHERE pc.product_id = cp.id) as category_path
             FROM catalog_products cp WHERE cp.id = ?",
            [$catalogProductId]
        );

        if (!$product) return null;

        // Get best offer data (description, specs, images)
        $offer = $this->db->fetchOne(
            "SELECT so.* FROM supplier_offers so
             WHERE so.catalog_product_id = ? AND so.is_active = 1
             ORDER BY so.price_purchase ASC LIMIT 1",
            [$catalogProductId]
        );

        $raw = json_decode($offer['raw_data_json'] ?? '{}', true);

        $template = $this->db->fetchOne(
            "SELECT * FROM content_templates WHERE code = ? AND is_active = 1",
            [$templateCode]
        );

        if (!$template) return null;

        $vars = [
            '{name}'           => $product['name'] ?? '',
            '{brand}'          => $product['brand'] ?? '',
            '{model}'          => $product['model'] ?? '',
            '{sku}'            => $product['sku'] ?? '',
            '{ean}'            => $product['ean'] ?? '',
            '{category}'       => $product['category_path'] ?? '',
            '{description}'    => mb_substr($raw['description'] ?? '', 0, 2000),
            '{specifications}' => $this->formatSpecs($raw['specifications'] ?? []),
            '{price}'          => $offer['price_purchase'] ?? '',
            '{currency}'       => $offer['currency'] ?? '',
            '{availability}'   => $offer['availability'] ?? '',
        ];

        $prompt = str_replace(array_keys($vars), array_values($vars), $template['prompt']);

        $result = $this->callAI($prompt);
        if ($result === null) return null;

        return [
            'template_code' => $templateCode,
            'template_type' => $template['type'],
            'prompt'         => $prompt,
            'result'         => $result,
            'product_id'     => $catalogProductId,
        ];
    }

    public function applyResult(int $productId, string $type, string $result): void
    {
        $now = date('Y-m-d H:i:s');

        switch ($type) {
            case 'name':
                $storeName = trim(str_replace(['"', "\n"], '', $result));
                $this->db->update('catalog_products', [
                    'store_name' => mb_substr($storeName, 0, 500),
                    'content_generated_at' => $now,
                ], 'id = ?', [$productId]);
                break;

            case 'seo':
                $json = $this->extractJson($result);
                if ($json) {
                    $this->db->update('catalog_products', [
                        'meta_title'       => mb_substr($json['meta_title'] ?? '', 0, 255),
                        'meta_description' => mb_substr($json['meta_description'] ?? '', 0, 500),
                        'meta_keywords'    => mb_substr($json['meta_keywords'] ?? '', 0, 500),
                        'content_generated_at' => $now,
                    ], 'id = ?', [$productId]);
                }
                break;

            case 'card':
                $this->db->update('catalog_products', [
                    'store_description' => $result,
                    'content_generated_at' => $now,
                ], 'id = ?', [$productId]);
                break;
        }
    }

    public function generateBulk(string $templateCode, int $limit = 10, int $offset = 0): array
    {
        $template = $this->db->fetchOne(
            "SELECT * FROM content_templates WHERE code = ? AND is_active = 1",
            [$templateCode]
        );

        if (!$template) return ['error' => 'Template not found'];

        $field = match ($template['type']) {
            'name' => 'store_name',
            'seo'  => 'meta_title',
            'card' => 'store_description',
            default => 'content_generated_at',
        };

        $products = $this->db->fetchAll(
            "SELECT id FROM catalog_products WHERE {$field} IS NULL ORDER BY id ASC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $stats = ['total' => count($products), 'success' => 0, 'errors' => 0];

        foreach ($products as $i => $p) {
            $this->logger->console(sprintf("[%d/%d] Product #%d", $i + 1, count($products), $p['id']));

            $result = $this->generateForProduct((int)$p['id'], $templateCode);
            if ($result) {
                $this->applyResult((int)$p['id'], $template['type'], $result['result']);
                $stats['success']++;
                $this->logger->console("  OK");
            } else {
                $stats['errors']++;
                $this->logger->console("  FAIL");
            }

            usleep(500000);
        }

        return $stats;
    }

    private function callAI(string $prompt): ?string
    {
        if (empty($this->apiKey)) {
            return $this->fallbackGenerate($prompt);
        }

        try {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model'      => $this->apiModel,
                    'max_tokens' => 2000,
                    'messages'   => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]),
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                $this->logger->error("AI API error: HTTP {$httpCode}");
                return null;
            }

            $data = json_decode($response, true);
            return $data['content'][0]['text'] ?? null;

        } catch (\Throwable $e) {
            $this->logger->error("AI API exception: {$e->getMessage()}");
            return null;
        }
    }

    private function fallbackGenerate(string $prompt): ?string
    {
        // Extract product data from prompt to generate basic content without AI
        $name = '';
        $brand = '';
        $model = '';
        if (preg_match('/(?:Назва|назва|name):\s*(.+)/ui', $prompt, $m)) $name = trim($m[1]);
        if (preg_match('/(?:Бренд|бренд|brand):\s*(.+)/ui', $prompt, $m)) $brand = trim($m[1]);
        if (preg_match('/(?:Модель|модель|model):\s*(.+)/ui', $prompt, $m)) $model = trim($m[1]);

        if (empty($name)) return null;

        if (str_contains($prompt, 'meta_title')) {
            return json_encode([
                'meta_title'       => mb_substr("{$name} — купити в Україні | Страйкбол", 0, 60),
                'meta_description' => mb_substr("Купити {$name} за найкращою ціною. {$brand} {$model}. Доставка по Україні. Гарантія якості.", 0, 160),
                'meta_keywords'    => mb_strtolower(implode(', ', array_filter([$brand, $model, 'страйкбол', 'купити', 'airsoft']))),
            ], JSON_UNESCAPED_UNICODE);
        }

        if (str_contains($prompt, 'картка') || str_contains($prompt, 'card')) {
            return "<p>{$name} від {$brand} — якісний вибір для страйкболу.</p>";
        }

        // Name generation
        $parts = array_filter([$brand, $model ?: $name]);
        return implode(' ', $parts);
    }

    private function formatSpecs(array $specs): string
    {
        if (empty($specs)) return '';
        $lines = [];
        foreach ($specs as $key => $val) {
            $lines[] = "{$key}: {$val}";
        }
        return implode("\n", $lines);
    }

    private function extractJson(string $text): ?array
    {
        if (preg_match('/\{[^{}]*"meta_title"[^{}]*\}/s', $text, $m)) {
            $json = json_decode($m[0], true);
            if (is_array($json)) return $json;
        }
        $json = json_decode($text, true);
        return is_array($json) ? $json : null;
    }
}
