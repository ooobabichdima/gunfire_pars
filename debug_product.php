<?php

declare(strict_types=1);

/**
 * debug_product.php — Debug tool to inspect product page HTML structure.
 *
 * Usage:
 *   php debug_product.php --url=https://gunfire.com/en/products/...html
 *   php debug_product.php --file=logs/sample_product.html
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\HttpClient;
use App\Logger;
use Symfony\Component\DomCrawler\Crawler;

$opts = getopt('', ['url:', 'file:', 'help']);

if (isset($opts['help']) || (empty($opts['url']) && empty($opts['file']))) {
    echo "Usage:\n";
    echo "  php debug_product.php --url=https://gunfire.com/en/products/...html\n";
    echo "  php debug_product.php --file=logs/sample_product.html\n";
    exit(0);
}

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'debug');

if (!empty($opts['file'])) {
    $html = file_get_contents($opts['file']);
    echo "Loaded from file: " . strlen($html) . " bytes\n\n";
} else {
    $http = new HttpClient($config['http'], $logger);
    $html = $http->getHtml($opts['url']);
    if ($html === null) {
        echo "ERROR: Could not fetch URL\n";
        exit(1);
    }
    // Save for reuse
    file_put_contents(__DIR__ . '/logs/debug_product.html', $html);
    echo "Fetched: " . strlen($html) . " bytes (saved to logs/debug_product.html)\n\n";
}

$crawler = new Crawler($html);

// -----------------------------------------------------------------------
echo "=== 1. TAB STRUCTURE ===\n";
$tabSelectors = [
    '.tabs a', '.tab-nav a', '.nav-tabs a', '[role="tab"]',
    '.product-tabs a', '.product-tab a', 'ul.tabs a', 'ul.tabs li a',
    '.tab-header', '.tab-title', '[data-tab]', '[data-toggle="tab"]',
];
foreach ($tabSelectors as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}] → {$nodes->count()} tabs:\n";
            $nodes->each(function (Crawler $n) {
                $text = trim($n->text(''));
                $href = $n->attr('href') ?? $n->attr('data-tab') ?? '';
                $id = $n->attr('id') ?? '';
                echo "    - \"{$text}\" href={$href} id={$id}\n";
            });
        }
    } catch (\Exception) {}
}

// -----------------------------------------------------------------------
echo "\n=== 2. DESCRIPTION SECTIONS ===\n";
$descSelectors = [
    '[itemprop="description"]',
    '.product-description', '.product__description',
    '#description', '#product-description',
    '.description', '.desc', '.product-desc',
    '.tab-content', '.tabs-content', '.tab-pane',
    '.product-info-detailed', '.product-detail-description',
    '.product-details', '.product-info',
    '.product-text', '.long-description',
    '[data-tab-content]', '.resetcss',
];
foreach ($descSelectors as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}] → {$nodes->count()} nodes:\n";
            $nodes->each(function (Crawler $n, int $i) {
                $text = trim($n->text(''));
                $cls = $n->attr('class') ?? '';
                $id = $n->attr('id') ?? '';
                $tag = $n->nodeName();
                $len = mb_strlen($text);
                echo "    [{$i}] <{$tag} class=\"{$cls}\" id=\"{$id}\"> {$len} chars\n";
                if ($len > 0 && $len < 500) {
                    echo "        \"" . mb_substr($text, 0, 200) . "\"\n";
                } elseif ($len >= 500) {
                    echo "        \"" . mb_substr($text, 0, 200) . "...\"\n";
                }
            });
        }
    } catch (\Exception) {}
}

// -----------------------------------------------------------------------
echo "\n=== 3. SPECIFICATIONS / PARAMETERS TABLE ===\n";
$specSelectors = [
    'table', '.specifications', '.params', '.parameters',
    '.product-attributes', '.product-params', '.product-specifications',
    '.spec-table', '.attributes-table', '.features',
    '.product-features', '.technical-data', '.tech-specs',
    'dl', '.attr-list', '.attribute-list',
    '[itemprop="additionalProperty"]',
];
foreach ($specSelectors as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}] → {$nodes->count()} nodes:\n";
            $nodes->each(function (Crawler $n, int $i) {
                $cls = $n->attr('class') ?? '';
                $id = $n->attr('id') ?? '';
                $tag = $n->nodeName();
                echo "    [{$i}] <{$tag} class=\"{$cls}\" id=\"{$id}\">\n";

                // If table - show rows
                if ($tag === 'table') {
                    try {
                        $n->filter('tr')->each(function (Crawler $row, int $ri) {
                            if ($ri > 10) return;
                            $cells = [];
                            $row->filter('td, th')->each(function (Crawler $cell) use (&$cells) {
                                $cells[] = trim($cell->text(''));
                            });
                            echo "      row[{$ri}]: " . implode(' | ', $cells) . "\n";
                        });
                    } catch (\Exception) {}
                }

                // If dl - show dt/dd pairs
                if ($tag === 'dl') {
                    try {
                        $n->filter('dt')->each(function (Crawler $dt, int $di) use ($n) {
                            if ($di > 10) return;
                            $label = trim($dt->text(''));
                            $value = '';
                            try {
                                $dds = $n->filter('dd');
                                if ($dds->count() > $di) {
                                    $value = trim($dds->eq($di)->text(''));
                                }
                            } catch (\Exception) {}
                            echo "      {$label}: {$value}\n";
                        });
                    } catch (\Exception) {}
                }

                // Generic - show first 300 chars
                $text = trim($n->text(''));
                if ($tag !== 'table' && $tag !== 'dl' && mb_strlen($text) > 0) {
                    echo "      text: \"" . mb_substr($text, 0, 300) . "\"\n";
                }
            });
        }
    } catch (\Exception) {}
}

// -----------------------------------------------------------------------
echo "\n=== 4. JSON-LD STRUCTURED DATA ===\n";
if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
    foreach ($matches[1] as $i => $jsonStr) {
        $data = json_decode($jsonStr, true);
        if (!$data) continue;
        echo "  JSON-LD block [{$i}]:\n";
        echo "    @type: " . ($data['@type'] ?? 'unknown') . "\n";
        if (isset($data['name'])) echo "    name: {$data['name']}\n";
        if (isset($data['brand'])) echo "    brand: " . (is_array($data['brand']) ? json_encode($data['brand']) : $data['brand']) . "\n";
        if (isset($data['sku'])) echo "    sku: {$data['sku']}\n";
        if (isset($data['gtin13'])) echo "    gtin13: {$data['gtin13']}\n";
        if (isset($data['description'])) echo "    description: " . mb_substr($data['description'], 0, 300) . "\n";
        if (isset($data['offers'])) echo "    offers: " . json_encode($data['offers'], JSON_PRETTY_PRINT) . "\n";
        if (isset($data['additionalProperty'])) {
            echo "    additionalProperty:\n";
            foreach ($data['additionalProperty'] as $prop) {
                echo "      - {$prop['name']}: {$prop['value']}\n";
            }
        }
    }
} else {
    echo "  No JSON-LD found\n";
}

// -----------------------------------------------------------------------
echo "\n=== 5. ALL DIV/SECTION IDs AND CLASSES (unique) ===\n";
$seen = [];
try {
    $crawler->filter('div[class], section[class], div[id], section[id]')->each(function (Crawler $n) use (&$seen) {
        $cls = $n->attr('class') ?? '';
        $id = $n->attr('id') ?? '';
        $tag = $n->nodeName();
        $key = "{$tag}.{$cls}#{$id}";
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            // Only show product-relevant looking ones
            $relevant = preg_match('/(product|desc|spec|param|attr|feat|detail|tab|char|info|prop)/i', $cls . $id);
            if ($relevant) {
                echo "  <{$tag} class=\"{$cls}\" id=\"{$id}\">\n";
            }
        }
    });
} catch (\Exception) {}

echo "\n=== DONE ===\n";
