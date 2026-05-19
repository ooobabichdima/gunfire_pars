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
use App\ProxyManager;
use App\Logger;
use App\Queue\QueueManager;
use App\Suppliers\SupplierParserFactory;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['supplier:', 'limit:', 'offset:', 'no-proxy', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php parse_offers.php --supplier=gunfire [--limit=50] [--no-proxy]\n";
    echo "  --supplier   Supplier code (required)\n";
    echo "  --limit      Batch size per run (default: 50)\n";
    echo "  --no-proxy   Disable proxy rotation\n";
    exit(0);
}

$useProxy = !isset($opts['no-proxy']);

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
$proxyManager = null;
if ($useProxy) {
    $proxyManager = new ProxyManager($logger, $config['log']['dir']);
    $proxyManager->load();
    $logger->console("Proxies loaded: " . $proxyManager->getWorkingCount() . " working");
}
$http = new HttpClient($config['http'], $logger, $proxyManager);
$queue = new QueueManager($db, $logger);

// Reset stuck items
$queue->resetStuck(30);

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

$startTime = microtime(true);
$logger->console("=== Парсинг {$supplierCode} (пакет: {$batchSize}) ===");

// ---------------------------------------------------------------------------
// Process queue
// ---------------------------------------------------------------------------
$items = $queue->fetchBatch($parser->getSupplierId(), 'product', $batchSize);

if (empty($items)) {
    $logger->console("Черга порожня — немає елементів для обробки.");
    $lock->release();
    exit(0);
}

$logger->console("В черзі: " . count($items) . " товарів\n");

$stats = ['parsed' => 0, 'saved' => 0, 'errors' => 0];

foreach ($items as $i => $item) {
    $itemStart = microtime(true);
    $num = $i + 1;
    $total = count($items);
    $pct = round($num / $total * 100);

    $logger->console(sprintf("[%d/%d %d%%] Парсинг: %s", $num, $total, $pct, mb_substr($item['url'], 0, 80)));

    try {
        $productData = $parser->parseProduct($item['url']);

        if ($productData === null) {
            $queue->markError((int)$item['id'], 'Parse returned null — page may have changed');
            $stats['errors']++;
            $logger->console("  ✗ Не вдалося розпарсити (null)");
            continue;
        }

        $parser->saveOffer($productData);
        $queue->markDone((int)$item['id']);

        $stats['parsed']++;
        $stats['saved']++;

        $elapsed = round(microtime(true) - $itemStart, 1);
        $price = $productData['price_purchase'] !== null ? number_format((float)$productData['price_purchase'], 2) : '—';
        $name = mb_substr($productData['name'], 0, 55);
        $proxyInfo = '';
        if ($proxyManager !== null) {
            $proxyInfo = " | proxy: " . $proxyManager->getWorkingCount() . " live";
        }
        $logger->console("  ✓ {$name} | {$price} {$productData['currency']} | {$elapsed}s{$proxyInfo}");

    } catch (\Throwable $e) {
        $queue->markError((int)$item['id'], $e->getMessage());
        $stats['errors']++;
        $logger->console("  ✗ Помилка: " . mb_substr($e->getMessage(), 0, 80));
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$totalTime = round(microtime(true) - $startTime, 1);
$avgTime = $stats['parsed'] > 0 ? round($totalTime / $stats['parsed'], 1) : 0;

$logger->console("\n=== Результат ===");
$logger->console("  Розпарсено: {$stats['parsed']}");
$logger->console("  Збережено:  {$stats['saved']}");
$logger->console("  Помилок:    {$stats['errors']}");
$logger->console("  Час:        {$totalTime}с (середнє: {$avgTime}с/товар)");

$queueStats = $queue->getStats($parser->getSupplierId());
$logger->console("\nQueue status:");
foreach ($queueStats as $row) {
    $logger->console("  {$row['status']}: {$row['cnt']}");
}

$lock->release();
