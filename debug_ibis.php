<?php

declare(strict_types=1);

/**
 * debug_ibis.php — Deep analysis of saved IBIS product page
 */

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

$file = __DIR__ . '/logs/debug_product.html';
if (!file_exists($file)) {
    echo "File not found: {$file}\n";
    exit(1);
}

$html = file_get_contents($file);
echo "Loaded: " . number_format(strlen($html)) . " bytes\n\n";

$crawler = new Crawler($html);

// 1. Product name
echo "=== PRODUCT NAME ===\n";
foreach (['h1', '.product-name', '.product-title', '[data-testid="product-name"]'] as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}]: \"" . trim($nodes->first()->text('')) . "\"\n";
        }
    } catch (\Exception) {}
}

// 2. Price
echo "\n=== PRICE ===\n";
// Search for price patterns in HTML
if (preg_match_all('/(\d[\d\s,.]+)\s*(грн|₴|UAH|zł|PLN|EUR|€)/i', $html, $m, PREG_SET_ORDER)) {
    foreach (array_slice($m, 0, 10) as $match) {
        echo "  Found: \"{$match[0]}\"\n";
    }
}
foreach (['[itemprop="price"]', '.product-price', '.price', '.current-price'] as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}]: \"" . trim($nodes->first()->text('')) . "\" content=" . ($nodes->first()->attr('content') ?? '') . "\n";
        }
    } catch (\Exception) {}
}

// 3. Brand
echo "\n=== BRAND ===\n";
foreach (['[itemprop="brand"]', '.product-brand', '.brand', 'a[href*="/brand/"]'] as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}]: \"" . trim($nodes->first()->text('')) . "\"\n";
        }
    } catch (\Exception) {}
}

// 4. Images
echo "\n=== IMAGES ===\n";
$imgCount = 0;
try {
    $crawler->filter('img[src]')->each(function (Crawler $node) use (&$imgCount) {
        $src = $node->attr('src') ?? '';
        if (!empty($src) && !str_contains($src, 'data:') && !str_contains($src, 'svg')
            && !str_contains($src, 'logo') && !str_contains($src, 'icon')
            && strlen($src) > 20) {
            $imgCount++;
            if ($imgCount <= 15) {
                $alt = $node->attr('alt') ?? '';
                echo "  [{$imgCount}] src=\"" . mb_substr($src, 0, 100) . "\" alt=\"{$alt}\"\n";
            }
        }
    });
} catch (\Exception) {}
echo "  Total product images: {$imgCount}\n";

// 5. Details-description area
echo "\n=== #details-description ===\n";
try {
    $desc = $crawler->filter('#details-description');
    if ($desc->count() > 0) {
        $text = trim($desc->text(''));
        echo "  Length: " . mb_strlen($text) . " chars\n";
        echo "  Preview: \"" . mb_substr($text, 0, 500) . "\"\n";

        // Look at child structure
        echo "\n  Children:\n";
        $desc->children()->each(function (Crawler $child, int $i) {
            if ($i > 20) return;
            $tag = $child->nodeName();
            $cls = $child->attr('class') ?? '';
            $id = $child->attr('id') ?? '';
            $textLen = mb_strlen(trim($child->text('')));
            echo "    [{$i}] <{$tag} class=\"{$cls}\" id=\"{$id}\"> {$textLen} chars\n";
        });
    } else {
        echo "  Not found\n";
    }
} catch (\Exception $e) {
    echo "  Error: {$e->getMessage()}\n";
}

// 6. HeadlessUI tab panels
echo "\n=== TAB PANELS ===\n";
try {
    $crawler->filter('[id^="headlessui-tabs-panel"]')->each(function (Crawler $panel, int $i) {
        $id = $panel->attr('id') ?? '';
        $text = trim($panel->text(''));
        $len = mb_strlen($text);
        echo "  [{$i}] id=\"{$id}\" → {$len} chars\n";
        if ($len > 0 && $len < 1000) {
            echo "    \"" . mb_substr($text, 0, 300) . "\"\n";
        } elseif ($len >= 1000) {
            echo "    \"" . mb_substr($text, 0, 300) . "...\"\n";
        }
    });
} catch (\Exception) {}

// 7. Specs — look for table/dl/div with key-value pairs
echo "\n=== SPECIFICATIONS (search) ===\n";
$specKeywords = ['Калібр', 'калібр', 'Вага', 'Довжина', 'FPS', 'Потужність', 'Тип', 'Магазин', 'Матеріал', 'Колір'];
foreach ($specKeywords as $kw) {
    if (preg_match('/' . preg_quote($kw, '/') . '[\s:]*([^<]{2,80})/u', $html, $m)) {
        echo "  {$kw}: " . trim($m[1]) . "\n";
    }
}

// 8. SKU / Product code
echo "\n=== SKU / CODE ===\n";
if (preg_match('/(\d{7,10})\s*[—–-]\s*купити/u', $html, $m)) {
    echo "  IBIS code from title: {$m[1]}\n";
}
if (preg_match('/(?:Артикул|Код|SKU|Article)[\s:]*([A-Z0-9][\w\-]{3,30})/ui', $html, $m)) {
    echo "  SKU: {$m[1]}\n";
}
foreach (['[itemprop="sku"]', '[itemprop="mpn"]', '.product-sku', '.sku'] as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 0) {
            echo "  [{$sel}]: \"" . trim($nodes->first()->text('')) . "\"\n";
        }
    } catch (\Exception) {}
}

// 9. Breadcrumbs
echo "\n=== BREADCRUMBS ===\n";
foreach (['[itemprop="itemListElement"]', '.breadcrumb a', 'nav a', 'ol a'] as $sel) {
    try {
        $nodes = $crawler->filter($sel);
        if ($nodes->count() > 1) {
            echo "  [{$sel}] → {$nodes->count()} items:\n";
            $nodes->each(function (Crawler $n, int $i) {
                if ($i > 8) return;
                echo "    - \"" . trim($n->text('')) . "\" → " . ($n->attr('href') ?? '') . "\n";
            });
            break;
        }
    } catch (\Exception) {}
}

// 10. JSON data in page (Vue/React state)
echo "\n=== INLINE JSON / STATE ===\n";
if (preg_match('/__NUXT_DATA__\s*=\s*(\[.{0,500})/s', $html, $m)) {
    echo "  Found __NUXT_DATA__ (Nuxt SSR)\n";
}
if (preg_match('/window\.__INITIAL_STATE__\s*=\s*(\{.{0,500})/s', $html, $m)) {
    echo "  Found __INITIAL_STATE__\n";
}
if (preg_match('/window\.__data__\s*=\s*(\{.{0,500})/s', $html, $m)) {
    echo "  Found __data__\n";
}
// Look for product data in script tags
if (preg_match_all('/<script[^>]*>(.*?product.*?price.*?)<\/script>/is', $html, $m)) {
    foreach (array_slice($m[1], 0, 3) as $i => $script) {
        echo "  Script with product+price [{$i}]: " . mb_substr($script, 0, 200) . "...\n";
    }
}
// JSON-LD
if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
    foreach ($m[1] as $i => $json) {
        $data = json_decode($json, true);
        echo "  JSON-LD [{$i}]: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
}

echo "\n=== DONE ===\n";
