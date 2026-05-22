<?php

declare(strict_types=1);

namespace App\Suppliers\GunfireB2b;

use App\Database;
use App\HttpClient;
use App\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;

final class GunfireB2bParser
{
    private Database $db;
    private Logger $logger;
    private array $config;
    private Client $client;
    private CookieJar $cookies;
    private bool $authenticated = false;

    private const BASE_URL = 'https://b2b.gunfire.com';

    public function __construct(Database $db, Logger $logger, array $config)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
        $this->cookies = new CookieJar();
        $this->client = new Client([
            'base_uri'        => self::BASE_URL,
            'cookies'         => $this->cookies,
            'timeout'         => 30,
            'connect_timeout' => 15,
            'http_errors'     => false,
            'verify'          => false,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);
    }

    public function login(): bool
    {
        $email = $this->config['b2b_email'] ?? '';
        $password = $this->config['b2b_password'] ?? '';

        if (empty($email) || empty($password)) {
            $this->logger->error("B2B credentials not configured");
            return false;
        }

        $this->logger->console("[b2b] Логін: {$email}");

        // Get login page for CSRF token
        $response = $this->client->get('/en/Account/Login');
        if ($response->getStatusCode() !== 200) {
            $this->logger->error("Cannot load login page: HTTP " . $response->getStatusCode());
            return false;
        }

        $html = (string)$response->getBody();
        $crawler = new Crawler($html);

        // Extract verification token
        $token = '';
        try {
            $token = $crawler->filter('input[name="__RequestVerificationToken"]')->first()->attr('value') ?? '';
        } catch (\Exception) {}

        // Submit login form
        $response = $this->client->post('/en/Account/Login', [
            'form_params' => [
                'Email'                       => $email,
                'Password'                    => $password,
                '__RequestVerificationToken'   => $token,
            ],
            'allow_redirects' => true,
        ]);

        $finalUrl = $response->getHeaderLine('Location') ?: (string)$response->getBody();

        // Check if logged in
        $homeResponse = $this->client->get('/en/Home/Index');
        $homeHtml = (string)$homeResponse->getBody();

        if (str_contains($homeHtml, 'Logout') || str_contains($homeHtml, 'logout')
            || str_contains($homeHtml, 'Log out') || str_contains($homeHtml, 'Account')
            || $homeResponse->getStatusCode() === 200 && !str_contains($homeHtml, 'Login')) {
            $this->authenticated = true;
            $this->logger->console("[b2b] Авторизація успішна");
            return true;
        }

        $this->logger->error("B2B login failed");
        return false;
    }

    /**
     * Fetch product details from B2B by product ID.
     */
    public function fetchProduct(int $b2bProductId): ?array
    {
        if (!$this->authenticated) return null;

        $url = "/en/Products/Details?id={$b2bProductId}";
        $response = $this->client->get($url);

        if ($response->getStatusCode() !== 200) return null;

        $html = (string)$response->getBody();
        return $this->parseB2bProduct($html, $b2bProductId);
    }

    /**
     * Search B2B products by SKU code.
     */
    public function searchBySku(string $sku): ?array
    {
        if (!$this->authenticated) return null;

        $url = "/en/Products/Index?gn=0&pn=20&f_1=" . urlencode($sku) . "&f_2=0";
        $response = $this->client->get($url);

        if ($response->getStatusCode() !== 200) return null;

        $html = (string)$response->getBody();
        $crawler = new Crawler($html);

        // Find product link in results
        $productId = null;
        try {
            $crawler->filter('a[href*="Products/Details"]')->each(function (Crawler $node) use (&$productId) {
                $href = $node->attr('href') ?? '';
                if (preg_match('/id=(\d+)/', $href, $m)) {
                    $productId = (int)$m[1];
                }
            });
        } catch (\Exception) {}

        if ($productId) {
            return $this->fetchProduct($productId);
        }

        return null;
    }

    /**
     * Bulk update gunfire offers with B2B prices.
     * Links via IAI code = external_id in supplier_offers.
     */
    public function updateGunfireOffers(int $limit = 50, int $offset = 0): array
    {
        if (!$this->authenticated) {
            return ['error' => 'Not authenticated'];
        }

        // Get gunfire offers that need B2B price update
        $offers = $this->db->fetchAll(
            "SELECT id, external_id, external_sku, name, price_purchase
             FROM supplier_offers
             WHERE supplier_id = 1 AND is_active = 1 AND external_id IS NOT NULL
             ORDER BY last_price_check_at ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $stats = ['total' => count($offers), 'updated' => 0, 'not_found' => 0, 'errors' => 0];

        foreach ($offers as $i => $offer) {
            $this->logger->console(sprintf("[%d/%d] %s (IAI: %s)",
                $i + 1, count($offers), mb_substr($offer['name'], 0, 50), $offer['external_id']));

            try {
                // Search by SKU first, then by IAI
                $sku = $offer['external_sku'] ?? '';
                $b2bData = null;

                if (!empty($sku)) {
                    $b2bData = $this->searchBySku($sku);
                }

                if ($b2bData === null && !empty($offer['external_id'])) {
                    // Try browsing pages to find by IAI
                    $b2bData = $this->findByIai($offer['external_id']);
                }

                if ($b2bData === null) {
                    $stats['not_found']++;
                    $this->logger->debug("  Not found in B2B");
                    usleep(1500000);
                    continue;
                }

                // Update offer with B2B data
                $updateData = [
                    'price_purchase'      => $b2bData['net_price'],
                    'price_regular'       => $b2bData['suggested_price'] ?? $b2bData['gross_price'],
                    'ean'                 => $b2bData['ean'] ?? null,
                    'stock_qty_text'      => $b2bData['stock'] ?? '',
                    'last_price_check_at' => date('Y-m-d H:i:s'),
                    'updated_at'          => date('Y-m-d H:i:s'),
                ];

                // Determine availability from stock
                if (!empty($b2bData['stock'])) {
                    $stockNum = (float)preg_replace('/[^\d.]/', '', $b2bData['stock']);
                    $updateData['availability'] = $stockNum > 0 ? 'in_stock' : 'out_of_stock';
                }

                $this->db->update('supplier_offers', $updateData, 'id = ?', [$offer['id']]);

                // Record price change
                if ((float)($offer['price_purchase'] ?? 0) !== (float)$b2bData['net_price']) {
                    $this->db->insert('supplier_offer_price_history', [
                        'supplier_offer_id' => $offer['id'],
                        'price_purchase'    => $b2bData['net_price'],
                        'price_regular'     => $b2bData['suggested_price'] ?? $b2bData['gross_price'],
                        'currency'          => 'PLN',
                        'availability'      => $updateData['availability'] ?? 'unknown',
                        'is_active'         => 1,
                        'checked_at'        => date('Y-m-d H:i:s'),
                    ]);
                }

                $stats['updated']++;
                $this->logger->console("  ✓ Net: {$b2bData['net_price']} PLN | Stock: {$b2bData['stock']}");

                usleep(2000000); // 2 sec between requests

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error("  Error: {$e->getMessage()}");
            }
        }

        return $stats;
    }

    /**
     * List all B2B products (paginated).
     */
    public function listProducts(int $page = 0, int $pageSize = 20): array
    {
        if (!$this->authenticated) return [];

        $url = "/en/Products/Index?gn={$page}&pn={$pageSize}&f_2=0";
        $response = $this->client->get($url);

        if ($response->getStatusCode() !== 200) return [];

        $html = (string)$response->getBody();
        $crawler = new Crawler($html);
        $products = [];

        try {
            // Parse product rows from the listing
            $crawler->filter('tr[data-id], .product-row, [data-product-id]')->each(function (Crawler $row) use (&$products) {
                $id = $row->attr('data-id') ?? $row->attr('data-product-id') ?? '';
                if (!empty($id)) {
                    $products[] = ['b2b_id' => (int)$id];
                }
            });
        } catch (\Exception) {}

        // Fallback: find product detail links
        if (empty($products)) {
            try {
                $crawler->filter('a[href*="Products/Details"]')->each(function (Crawler $node) use (&$products) {
                    $href = $node->attr('href') ?? '';
                    if (preg_match('/id=(\d+)/', $href, $m)) {
                        $products[] = ['b2b_id' => (int)$m[1]];
                    }
                });
            } catch (\Exception) {}
        }

        return array_values(array_unique($products, SORT_REGULAR));
    }

    private function findByIai(string $iai): ?array
    {
        $url = "/en/Products/Index?gn=0&pn=5&f_1=" . urlencode($iai) . "&f_2=0";
        $response = $this->client->get($url);

        if ($response->getStatusCode() !== 200) return null;

        $html = (string)$response->getBody();

        // Find product detail link
        if (preg_match('/Products\/Details\?id=(\d+)/', $html, $m)) {
            usleep(1000000);
            return $this->fetchProduct((int)$m[1]);
        }

        return null;
    }

    private function parseB2bProduct(string $html, int $b2bId): ?array
    {
        $data = ['b2b_id' => $b2bId];
        $crawler = new Crawler($html);

        // Name from h1/h2 or title
        try {
            $h1 = trim($crawler->filter('h1, h2')->first()->text(''));
            if (!empty($h1)) {
                // Extract [SKU] from name: "[SWL-03-018552] Snow Wolf M98..."
                if (preg_match('/^\[([^\]]+)\]\s*(.+)$/s', $h1, $m)) {
                    $data['sku'] = trim($m[1]);
                    $data['name'] = trim($m[2]);
                } else {
                    $data['name'] = $h1;
                }
            }
        } catch (\Exception) {}

        // Fallback name from title tag
        if (empty($data['name'])) {
            try {
                $title = trim($crawler->filter('title')->first()->text(''));
                $data['name'] = preg_replace('/\s*[-|].*$/', '', $title) ?? $title;
            } catch (\Exception) {}
        }

        // Parse all key-value pairs from the page
        // Format: "Label ... Value PLN" or "Label ... Value"
        $pairs = [];
        try {
            $crawler->filter('tr, .row, dl, .detail-row')->each(function (Crawler $row) use (&$pairs) {
                $text = trim($row->text(''));
                if (preg_match('/^([\w\s\[\]]+?)\s+([\d,.\s]+\s*PLN|[\d,.\s]+\s*pcs\.?|\d{8,14}|\d{7,15}|[\w-]+)$/m', $text, $m)) {
                    $pairs[trim($m[1])] = trim($m[2]);
                }
            });
        } catch (\Exception) {}

        // Net price — try multiple patterns
        if (preg_match('/(?:^|\s)Net\b[\s\S]{0,100}?([\d][[\d\s,.]*[\d])\s*PLN/i', $html, $m)) {
            $data['net_price'] = $this->parsePriceSafe($m[1]);
        }
        // Before discount Net (take the discounted one if exists)
        if (preg_match('/(?:^|\s)Net\s*\n?\s*([\d][\d\s,.]*[\d])\s*PLN/mi', $html, $m)) {
            $price = $this->parsePriceSafe($m[1]);
            if ($price > 0) $data['net_price'] = $price;
        }

        // Gross price
        if (preg_match('/(?:^|\s)Gross\b[\s\S]{0,100}?([\d][\d\s,.]*[\d])\s*PLN/i', $html, $m)) {
            $data['gross_price'] = $this->parsePriceSafe($m[1]);
        }

        // Suggested price
        if (preg_match('/Suggested\s+([\d][\d\s,.]*[\d])\s*PLN/i', $html, $m)) {
            $data['suggested_price'] = $this->parsePriceSafe($m[1]);
        }

        // Stock
        if (preg_match('/Stock\s+([\d,.]+)\s*pcs/i', $html, $m)) {
            $data['stock'] = trim($m[1]) . ' pcs';
        }

        // EAN
        if (preg_match('/EAN\s+(\d{8,14})/i', $html, $m)) {
            $data['ean'] = $m[1];
        }

        // IAI code
        if (preg_match('/IAI\s+(\d{7,15})/i', $html, $m)) {
            $data['iai'] = $m[1];
        }

        // Producer
        if (preg_match('/Producer\s+(\w[\w\s]{1,30})/i', $html, $m)) {
            $data['producer'] = trim($m[1]);
        }

        if (!isset($data['net_price'])) {
            return null;
        }

        return $data;
    }

    /**
     * Parse price handling both "1,313.00" and "1 313.00" formats.
     */
    private function parsePriceSafe(string $text): float
    {
        $text = trim($text);
        // Remove spaces used as thousands separator
        $text = preg_replace('/(\d)\s+(\d)/', '$1$2', $text);
        // If format is "1,313.00" (comma = thousands) — remove comma
        if (preg_match('/^\d{1,3},\d{3}\./', $text)) {
            $text = str_replace(',', '', $text);
        }
        // If format is "1.313,00" (dot = thousands, comma = decimal) — European
        elseif (preg_match('/^\d{1,3}\.\d{3},/', $text)) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        }
        // Simple comma as decimal
        elseif (str_contains($text, ',') && !str_contains($text, '.')) {
            $text = str_replace(',', '.', $text);
        }

        return (float)$text;
    }
}
