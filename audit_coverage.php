<?php

declare(strict_types=1);

/**
 * audit_coverage.php — Audit how complete the parsed data is.
 *
 * Step 1: Fetch /en/sitemap.php, extract all category and product URLs
 * Step 2: Compare with what's in the database
 * Step 3: Show report: what's covered, what's missing
 *
 * Usage:
 *   php audit_coverage.php [--save-sitemap] [--check-db]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\HttpClient;
use App\Logger;
use Symfony\Component\DomCrawler\Crawler;

$opts = getopt('', ['save-sitemap', 'check-db', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php audit_coverage.php [--save-sitemap] [--check-db]\n";
    echo "  --save-sitemap   Save sitemap URLs to logs/sitemap_urls.txt\n";
    echo "  --check-db       Compare sitemap against database\n";
    echo "  (no flags)       Full audit: fetch sitemap + compare with DB\n";
    exit(0);
}

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'audit');
$http = new HttpClient($config['http'], $logger);

$logger->console("=== Gunfire Coverage Audit ===\n");

// -----------------------------------------------------------------------
// Step 1: Fetch and parse sitemap.php
// -----------------------------------------------------------------------
$logger->console("[1/3] Fetching sitemap from https://gunfire.com/en/sitemap.php ...");

$html = $http->getHtml('https://gunfire.com/en/sitemap.php');
if ($html === null) {
    $logger->console("ERROR: Could not fetch sitemap.php (403/timeout).");
    $logger->console("Try saving the page source manually:");
    $logger->console("  1. Open https://gunfire.com/en/sitemap.php in browser");
    $logger->console("  2. Ctrl+U → Select All → Copy");
    $logger->console("  3. Save to logs/sitemap.html");
    $logger->console("  4. Re-run: php audit_coverage.php");

    // Try loading from local file
    $localFile = __DIR__ . '/logs/sitemap.html';
    if (file_exists($localFile)) {
        $logger->console("\nFound local file: {$localFile}");
        $html = file_get_contents($localFile);
    } else {
        exit(1);
    }
}

$logger->console("Sitemap HTML loaded: " . number_format(strlen($html)) . " bytes\n");

$crawler = new Crawler($html);

// Extract all links
$allLinks = [];
$categoryUrls = [];
$productUrls = [];
$producerUrls = [];
$otherUrls = [];

try {
    $crawler->filter('a[href]')->each(function (Crawler $node) use (&$allLinks, &$categoryUrls, &$productUrls, &$producerUrls, &$otherUrls) {
        $href = $node->attr('href') ?? '';
        if (empty($href) || $href === '#') {
            return;
        }

        // Make absolute
        if (!str_starts_with($href, 'http')) {
            $href = 'https://gunfire.com' . (str_starts_with($href, '/') ? '' : '/') . $href;
        }

        $allLinks[] = $href;

        if (preg_match('#/(?:en/)?categories/[\w\-]+-\d{7,15}\.html#', $href)) {
            $categoryUrls[] = $href;
        } elseif (preg_match('#/(?:en/)?products/[\w\-]+-\d{7,15}\.html#', $href)) {
            $productUrls[] = $href;
        } elseif (preg_match('#/(?:en/)?producers/[\w\-]+-\d{7,15}\.html#', $href)) {
            $producerUrls[] = $href;
        } else {
            $otherUrls[] = $href;
        }
    });
} catch (\Exception $e) {
    $logger->console("Error parsing sitemap HTML: {$e->getMessage()}");
    exit(1);
}

$categoryUrls = array_values(array_unique($categoryUrls));
$productUrls = array_values(array_unique($productUrls));
$producerUrls = array_values(array_unique($producerUrls));

$logger->console("[2/3] Sitemap analysis:");
$logger->console("  Total links found:    " . number_format(count($allLinks)));
$logger->console("  Category URLs:        " . number_format(count($categoryUrls)));
$logger->console("  Product URLs:         " . number_format(count($productUrls)));
$logger->console("  Producer URLs:        " . number_format(count($producerUrls)));
$logger->console("  Other URLs:           " . number_format(count($otherUrls)));

// Extract product IDs from sitemap
$sitemapProductIds = [];
foreach ($productUrls as $url) {
    if (preg_match('/-(\d{7,15})\.html/', $url, $m)) {
        $sitemapProductIds[$m[1]] = $url;
    }
}

$logger->console("  Unique product IDs:   " . number_format(count($sitemapProductIds)));

// Save to file
if (isset($opts['save-sitemap']) || true) {
    $outFile = __DIR__ . '/logs/sitemap_categories.txt';
    file_put_contents($outFile, implode("\n", $categoryUrls) . "\n");
    $logger->console("\n  Saved categories → {$outFile}");

    $outFile = __DIR__ . '/logs/sitemap_products.txt';
    file_put_contents($outFile, implode("\n", $productUrls) . "\n");
    $logger->console("  Saved products   → {$outFile}");
}

// -----------------------------------------------------------------------
// Step 3: Compare with database
// -----------------------------------------------------------------------
$logger->console("\n[3/3] Comparing with database...\n");

try {
    $db = Database::getInstance($config['db']);
} catch (\Exception $e) {
    $logger->console("Could not connect to DB: {$e->getMessage()}");
    $logger->console("Sitemap-only audit complete.");
    exit(0);
}

// Get all external_ids from supplier_offers
$dbOffers = $db->fetchAll(
    "SELECT external_id, url, is_active, name FROM supplier_offers WHERE supplier_id = 1"
);

$dbIds = [];
foreach ($dbOffers as $offer) {
    if (!empty($offer['external_id'])) {
        $dbIds[$offer['external_id']] = $offer;
    }
}

$logger->console("Database offers (gunfire):  " . number_format(count($dbIds)));
$logger->console("Sitemap product IDs:        " . number_format(count($sitemapProductIds)));

// Find missing (in sitemap but not in DB)
$missingIds = array_diff_keys_custom($sitemapProductIds, $dbIds);
// Find extra (in DB but not in sitemap — may be removed products)
$extraIds = array_diff_keys_custom($dbIds, $sitemapProductIds);
// Find matched
$matchedIds = array_intersect_key($sitemapProductIds, $dbIds);

$logger->console("\n--- COVERAGE REPORT ---");
$logger->console("  Matched (in both):      " . number_format(count($matchedIds)));
$logger->console("  Missing (sitemap only): " . number_format(count($missingIds)));
$logger->console("  Extra (DB only):         " . number_format(count($extraIds)));

$coveragePercent = count($sitemapProductIds) > 0
    ? round(count($matchedIds) / count($sitemapProductIds) * 100, 1)
    : 0;

$logger->console("\n  COVERAGE: {$coveragePercent}% of sitemap products are in DB");

if (count($missingIds) > 0) {
    $logger->console("\n  First 20 missing products:");
    $i = 0;
    foreach ($missingIds as $id => $url) {
        if ($i++ >= 20) break;
        $logger->console("    - [{$id}] {$url}");
    }

    // Save full missing list
    $missingFile = __DIR__ . '/logs/missing_products.txt';
    $lines = [];
    foreach ($missingIds as $id => $url) {
        $lines[] = $url;
    }
    file_put_contents($missingFile, implode("\n", $lines) . "\n");
    $logger->console("\n  Full missing list → {$missingFile}");
}

if (count($extraIds) > 0) {
    $logger->console("\n  Products in DB but NOT in sitemap (possibly removed): " . number_format(count($extraIds)));
    $activeExtra = 0;
    foreach ($extraIds as $id => $offer) {
        if (is_array($offer) && ($offer['is_active'] ?? 0)) {
            $activeExtra++;
        }
    }
    $logger->console("    Of those, still marked active: {$activeExtra}");
}

// Queue status
$queueStats = $db->fetchAll(
    "SELECT type, status, COUNT(*) as cnt FROM parse_queue GROUP BY type, status ORDER BY type, status"
);

$logger->console("\n--- QUEUE STATUS ---");
foreach ($queueStats as $row) {
    $logger->console("  {$row['type']} / {$row['status']}: {$row['cnt']}");
}

// Category coverage
$logger->console("\n--- CATEGORY COVERAGE ---");
$logger->console("  Categories in sitemap: " . count($categoryUrls));

// Check which sitemap categories were scanned
$scannedCategories = $db->fetchAll(
    "SELECT url, status FROM parse_queue WHERE type = 'category' AND supplier_id = 1"
);
$scannedCategoryUrls = array_column($scannedCategories, 'status', 'url');

$categoriesScanned = 0;
$categoriesNotScanned = [];
foreach ($categoryUrls as $catUrl) {
    if (isset($scannedCategoryUrls[$catUrl]) && $scannedCategoryUrls[$catUrl] === 'done') {
        $categoriesScanned++;
    } else {
        $categoriesNotScanned[] = $catUrl;
    }
}

$logger->console("  Categories scanned:    {$categoriesScanned}");
$logger->console("  Categories NOT scanned: " . count($categoriesNotScanned));

if (!empty($categoriesNotScanned) && count($categoriesNotScanned) <= 30) {
    foreach ($categoriesNotScanned as $url) {
        $logger->console("    - {$url}");
    }
}

if (!empty($categoriesNotScanned)) {
    $missingCatFile = __DIR__ . '/logs/missing_categories.txt';
    file_put_contents($missingCatFile, implode("\n", $categoriesNotScanned) . "\n");
    $logger->console("  Full list → {$missingCatFile}");
}

$logger->console("\n=== AUDIT COMPLETE ===");

// -----------------------------------------------------------------------
function array_diff_keys_custom(array $a, array $b): array
{
    $diff = [];
    foreach ($a as $key => $value) {
        if (!array_key_exists($key, $b)) {
            $diff[$key] = $value;
        }
    }
    return $diff;
}
