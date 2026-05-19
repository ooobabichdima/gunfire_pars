<?php

declare(strict_types=1);

/**
 * scan_suppliers.php — Scan supplier categories and product listings, enqueue URLs.
 *
 * Usage:
 *   php scan_suppliers.php --supplier=gunfire [--limit=500] [--mode=categories|listings|all]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\HttpClient;
use App\Lock;
use App\ProxyManager;
use App\Logger;
use App\Queue\QueueManager;
use App\Suppliers\SupplierParserFactory;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['supplier:', 'limit:', 'mode:', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php scan_suppliers.php --supplier=gunfire [--limit=500] [--mode=categories|listings|all]\n";
    echo "  --supplier   Supplier code (required)\n";
    echo "  --limit      Max product URLs to enqueue per category (0 = unlimited)\n";
    echo "  --mode       categories | listings | all (default: all)\n";
    exit(0);
}

$supplierCode = $opts['supplier'] ?? '';
if (empty($supplierCode)) {
    fwrite(STDERR, "Error: --supplier is required\n");
    exit(1);
}

$limit = (int)($opts['limit'] ?? 0);
$mode = $opts['mode'] ?? 'all';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'scan');

$lock = new Lock($config['parser']['lock_dir'], "scan_{$supplierCode}");
if (!$lock->acquire()) {
    $logger->warning("Another scan process is running for {$supplierCode}");
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);
$proxyManager = new ProxyManager($logger, $config['log']['dir']);
$proxyManager->load();
foreach ($config['http']['custom_proxies'] ?? [] as $cp) { $proxyManager->addProxy($cp); }
$supplierRow = $db->fetchOne("SELECT config_json FROM suppliers WHERE code = ?", [$supplierCode]);
foreach (json_decode($supplierRow['config_json'] ?? '{}', true)['proxies'] ?? [] as $cp) { $proxyManager->addProxy($cp); }
$http = new HttpClient($config['http'], $logger, $proxyManager);
$queue = new QueueManager($db, $logger);

// ---------------------------------------------------------------------------
// Resolve parser
// ---------------------------------------------------------------------------
try {
    $parser = SupplierParserFactory::create($supplierCode, $db, $http, $logger);
} catch (\Throwable $e) {
    $logger->error($e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$logger->console("Starting scan for supplier: {$supplierCode} (mode: {$mode})");

// ---------------------------------------------------------------------------
// Scan categories
// ---------------------------------------------------------------------------
$categoryUrls = [];

if ($mode === 'categories' || $mode === 'all') {
    $logger->console("Scanning categories...");
    $categoryUrls = $parser->scanCategories();
    $logger->console("Found " . count($categoryUrls) . " categories");

    $queue->enqueueBatch($parser->getSupplierId(), $categoryUrls, 'category', 1);
}

// ---------------------------------------------------------------------------
// Scan listings (product URLs from each category)
// ---------------------------------------------------------------------------
if ($mode === 'listings' || $mode === 'all') {
    if (empty($categoryUrls)) {
        // Load from queue
        $items = $queue->fetchBatch($parser->getSupplierId(), 'category', 500);
        $categoryUrls = array_column($items, 'url');
        $logger->console("Loaded " . count($categoryUrls) . " categories from queue");
    }

    $totalProducts = 0;
    foreach ($categoryUrls as $i => $catUrl) {
        $logger->console(sprintf("[%d/%d] Scanning: %s", $i + 1, count($categoryUrls), $catUrl));

        try {
            $productUrls = $parser->scanListings($catUrl, $limit);
            $queue->enqueueBatch($parser->getSupplierId(), $productUrls, 'product', 5);
            $totalProducts += count($productUrls);
            $logger->console("  → " . count($productUrls) . " products found");
        } catch (\Throwable $e) {
            $logger->error("Error scanning {$catUrl}: {$e->getMessage()}");
        }
    }

    $logger->console("Total product URLs enqueued: {$totalProducts}");
}

// ---------------------------------------------------------------------------
// Queue stats
// ---------------------------------------------------------------------------
$stats = $queue->getStats($parser->getSupplierId());
$logger->console("\nQueue stats:");
foreach ($stats as $row) {
    $logger->console("  {$row['status']}: {$row['cnt']}");
}

$lock->release();
$logger->console("Scan complete.");
