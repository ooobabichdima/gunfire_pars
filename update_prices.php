<?php

declare(strict_types=1);

/**
 * update_prices.php — Lightweight price/availability update for active offers.
 * Only updates: price_purchase, price_regular, availability, is_active, last_price_check_at, last_seen_at.
 * Does NOT touch: description, images, categories.
 *
 * Usage:
 *   php update_prices.php --supplier=gunfire [--limit=100] [--offset=0] [--product-id=123] [--active=1]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\HttpClient;
use App\Lock;
use App\Logger;
use App\ProxyManager;
use App\Services\OfferUpdater;
use App\Services\PriceComparator;
use App\Suppliers\SupplierParserFactory;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['supplier:', 'limit:', 'offset:', 'product-id:', 'active:', 'mode:', 'no-proxy', 'proxy-only', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php update_prices.php --supplier=gunfire [--limit=100] [--offset=0] [--product-id=123] [--active=1] [--mode=refresh|compare]\n";
    echo "  --supplier     Supplier code (required for refresh mode)\n";
    echo "  --limit        Max offers to process (default: 100)\n";
    echo "  --offset       Offset (default: 0)\n";
    echo "  --product-id   Update a single catalog product's offers\n";
    echo "  --active       Filter by active status (default: 1)\n";
    echo "  --mode         refresh | compare (default: refresh)\n";
    exit(0);
}

$supplierCode = $opts['supplier'] ?? '';
$limit = (int)($opts['limit'] ?? 100);
$offset = (int)($opts['offset'] ?? 0);
$useProxy = !isset($opts['no-proxy']);
$proxyOnly = isset($opts['proxy-only']);
$productId = isset($opts['product-id']) ? (int)$opts['product-id'] : null;
$mode = $opts['mode'] ?? 'refresh';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'prices');

$lock = new Lock($config['parser']['lock_dir'], "prices_{$supplierCode}_{$mode}");
if (!$lock->acquire()) {
    $logger->warning("Another price process is running");
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);

// ---------------------------------------------------------------------------
// Mode: compare — show price comparison report
// ---------------------------------------------------------------------------
if ($mode === 'compare') {
    $comparator = new PriceComparator($db, $logger);

    if ($productId !== null) {
        $result = $comparator->compareForProduct($productId);
        printComparison($result, $logger);
    } else {
        $results = $comparator->compareAll($limit, $offset);
        if (empty($results)) {
            $logger->console("No products with multiple supplier offers found.");
        }
        foreach ($results as $result) {
            printComparison($result, $logger);
            $logger->console(str_repeat('─', 80));
        }

        $logger->console("\n=== Best Suppliers Report ===");
        $report = $comparator->bestSuppliersReport($limit, $offset);
        foreach ($report as $row) {
            $logger->console(sprintf(
                "  #%d %-40s → %-15s %8.2f %s (%d offers)",
                $row['catalog_product_id'],
                mb_substr($row['product_name'], 0, 40),
                $row['best_supplier'],
                $row['effective_price'],
                'PLN',
                $row['offer_count']
            ));
        }
    }

    $lock->release();
    exit(0);
}

// ---------------------------------------------------------------------------
// Mode: refresh — update prices from supplier
// ---------------------------------------------------------------------------
if (empty($supplierCode)) {
    fwrite(STDERR, "Error: --supplier is required for refresh mode\n");
    exit(1);
}

$proxyManager = new ProxyManager($logger, $config['log']['dir']);

$customProxies = $config['http']['custom_proxies'] ?? [];
if (!empty($supplierCode)) {
    $supplierRow = $db->fetchOne("SELECT config_json FROM suppliers WHERE code = ?", [$supplierCode]);
    $customProxies = array_merge($customProxies, json_decode($supplierRow['config_json'] ?? '{}', true)['proxies'] ?? []);
}
foreach ($customProxies as $cp) { $proxyManager->addProxy($cp); }

if ($useProxy) {
    $proxyManager->load();
}
$proxyManager->setEnabled(true);

$http = new HttpClient($config['http'], $logger, $proxyManager);
if ($proxyOnly) {
    $http->setProxyOnly(true);
}

try {
    $parser = SupplierParserFactory::create($supplierCode, $db, $http, $logger);
} catch (\Throwable $e) {
    $logger->error($e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$updater = new OfferUpdater($db, $logger);

$logger->console("Updating prices for supplier: {$supplierCode} (limit: {$limit}, offset: {$offset})");

if ($productId !== null) {
    // Get all offers for this catalog product from this supplier
    $offers = $db->fetchAll(
        'SELECT id FROM supplier_offers WHERE catalog_product_id = ? AND supplier_id = ? AND is_active = 1',
        [$productId, $parser->getSupplierId()]
    );

    foreach ($offers as $offer) {
        $updater->updateOfferById($parser, (int)$offer['id']);
    }

    $logger->console("Updated " . count($offers) . " offers for product #{$productId}");
} else {
    $stats = $updater->updatePrices($parser, $limit, $offset);

    $logger->console("\nPrice update summary:");
    $logger->console("  Total checked:  {$stats['total']}");
    $logger->console("  Price changed:  {$stats['updated']}");
    $logger->console("  Unchanged:      {$stats['unchanged']}");
    $logger->console("  Deactivated:    {$stats['deactivated']}");
    $logger->console("  Errors:         {$stats['errors']}");
}

$lock->release();

// ---------------------------------------------------------------------------
// Helper: print comparison
// ---------------------------------------------------------------------------
function printComparison(array $result, Logger $logger): void
{
    if (empty($result['offers'])) {
        return;
    }

    $logger->console(sprintf(
        "\n[Product #%d] %s (%s %s)",
        $result['catalog_product_id'],
        $result['product_name'],
        $result['brand'] ?? '',
        $result['model'] ?? ''
    ));

    foreach ($result['offers'] as $i => $offer) {
        $badge = $i === 0 ? ' ★ BEST' : '';
        $logger->console(sprintf(
            "  %s: %8.2f %s (purchase: %.2f + delivery: %.2f + markup: %.2f) | %s | %s%s",
            str_pad($offer['supplier_name'], 20),
            $offer['effective_price'],
            $offer['currency'],
            $offer['price_purchase'],
            $offer['delivery_cost_applied'],
            $offer['markup_amount'],
            $offer['availability'],
            $offer['url'] ?? '',
            $badge
        ));
    }
}
