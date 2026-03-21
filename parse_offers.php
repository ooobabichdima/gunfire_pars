<?php

declare(strict_types=1);

/**
 * parse_offers.php — Process product URLs from queue, parse full product data, save as supplier_offers.
 *
 * Usage:
 *   php parse_offers.php --supplier=gunfire [--limit=50] [--offset=0]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\HttpClient;
use App\Lock;
use App\Logger;
use App\Queue\QueueManager;
use App\Suppliers\Gunfire\GunfireParser;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['supplier:', 'limit:', 'offset:', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php parse_offers.php --supplier=gunfire [--limit=50] [--offset=0]\n";
    echo "  --supplier   Supplier code (required)\n";
    echo "  --limit      Batch size per run (default: 50)\n";
    echo "  --offset     Not used in queue mode\n";
    exit(0);
}

$supplierCode = $opts['supplier'] ?? '';
if (empty($supplierCode)) {
    fwrite(STDERR, "Error: --supplier is required\n");
    exit(1);
}

$batchSize = (int)($opts['limit'] ?? 50);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'parse');

$lock = new Lock($config['parser']['lock_dir'], "parse_{$supplierCode}");
if (!$lock->acquire()) {
    $logger->warning("Another parse process is running for {$supplierCode}");
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);
$http = new HttpClient($config['http'], $logger);
$queue = new QueueManager($db, $logger);

// Reset stuck items
$queue->resetStuck(30);

// ---------------------------------------------------------------------------
// Resolve parser
// ---------------------------------------------------------------------------
$parser = match ($supplierCode) {
    'gunfire' => new GunfireParser($db, $http, $logger, $config['suppliers']['gunfire'] ?? []),
    default   => null,
};

if ($parser === null) {
    $logger->error("Unknown supplier: {$supplierCode}");
    fwrite(STDERR, "Unknown supplier: {$supplierCode}\n");
    exit(1);
}

$logger->console("Starting parse for supplier: {$supplierCode} (batch: {$batchSize})");

// ---------------------------------------------------------------------------
// Process queue
// ---------------------------------------------------------------------------
$items = $queue->fetchBatch($parser->getSupplierId(), 'product', $batchSize);

if (empty($items)) {
    $logger->console("No items in queue to process.");
    $lock->release();
    exit(0);
}

$logger->console("Processing " . count($items) . " items...");

$stats = ['parsed' => 0, 'saved' => 0, 'errors' => 0];

foreach ($items as $i => $item) {
    $logger->console(sprintf("[%d/%d] %s", $i + 1, count($items), $item['url']));

    try {
        $productData = $parser->parseProduct($item['url']);

        if ($productData === null) {
            $queue->markError((int)$item['id'], 'Parse returned null — page may have changed');
            $stats['errors']++;
            continue;
        }

        $parser->saveOffer($productData);
        $queue->markDone((int)$item['id']);

        $stats['parsed']++;
        $stats['saved']++;

        $logger->debug("Saved: {$productData['name']} (ext_id: {$productData['external_id']})");

    } catch (\Throwable $e) {
        $queue->markError((int)$item['id'], $e->getMessage());
        $stats['errors']++;
        $logger->error("Error parsing {$item['url']}: {$e->getMessage()}");
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$logger->console("\nParse complete:");
$logger->console("  Parsed: {$stats['parsed']}");
$logger->console("  Saved:  {$stats['saved']}");
$logger->console("  Errors: {$stats['errors']}");

$queueStats = $queue->getStats($parser->getSupplierId());
$logger->console("\nQueue status:");
foreach ($queueStats as $row) {
    $logger->console("  {$row['status']}: {$row['cnt']}");
}

$lock->release();
