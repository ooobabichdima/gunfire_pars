<?php

declare(strict_types=1);

/**
 * match_products.php — Match unlinked supplier_offers to catalog_products.
 *
 * Usage:
 *   php match_products.php [--limit=500] [--offset=0] [--supplier=gunfire]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Catalog\ProductMatcher;
use App\Catalog\ProductNormalizer;
use App\Database;
use App\Lock;
use App\Logger;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['limit:', 'offset:', 'supplier:', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php match_products.php [--limit=500] [--offset=0] [--supplier=gunfire]\n";
    echo "  --limit      Number of offers to process (default: 500)\n";
    echo "  --offset     Offset for pagination (default: 0)\n";
    echo "  --supplier   Filter by supplier code (optional)\n";
    exit(0);
}

$limit = (int)($opts['limit'] ?? 500);
$offset = (int)($opts['offset'] ?? 0);
$supplierCode = $opts['supplier'] ?? '';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'match');

$lock = new Lock($config['parser']['lock_dir'], 'match_products');
if (!$lock->acquire()) {
    $logger->warning("Another match process is running");
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);
$normalizer = new ProductNormalizer();
$matcher = new ProductMatcher($db, $logger, $normalizer);

$logger->console("Starting product matching (limit: {$limit}, offset: {$offset})");

// ---------------------------------------------------------------------------
// If supplier is specified, add a WHERE filter
// ---------------------------------------------------------------------------
if (!empty($supplierCode)) {
    $supplier = $db->fetchOne('SELECT id FROM suppliers WHERE code = ?', [$supplierCode]);
    if ($supplier === null) {
        $logger->error("Unknown supplier: {$supplierCode}");
        fwrite(STDERR, "Unknown supplier: {$supplierCode}\n");
        exit(1);
    }

    $offers = $db->fetchAll(
        'SELECT id, name, brand, model, ean, external_sku
         FROM supplier_offers
         WHERE catalog_product_id IS NULL AND is_active = 1 AND supplier_id = ?
         ORDER BY id ASC
         LIMIT ? OFFSET ?',
        [(int)$supplier['id'], $limit, $offset]
    );

    $logger->console("Found " . count($offers) . " unlinked offers for {$supplierCode}");

    $matched = 0;
    $created = 0;
    foreach ($offers as $i => $offer) {
        $existingCount = (int)$db->fetchColumn('SELECT COUNT(*) FROM catalog_products');

        $catalogId = $matcher->matchOrCreate($offer);

        $newCount = (int)$db->fetchColumn('SELECT COUNT(*) FROM catalog_products');
        if ($newCount > $existingCount) {
            $created++;
        } else {
            $matched++;
        }

        $db->update(
            'supplier_offers',
            ['catalog_product_id' => $catalogId, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$offer['id']]
        );

        if (($i + 1) % 100 === 0) {
            $logger->console("  Processed {$i}/{$i + 1}...");
        }
    }

    $logger->console("\nMatching complete:");
    $logger->console("  Matched to existing: {$matched}");
    $logger->console("  New products created: {$created}");
} else {
    $stats = $matcher->matchUnlinked($limit, $offset);
    $logger->console("\nMatching complete:");
    $logger->console("  Total processed: {$stats['total']}");
    $logger->console("  Matched/created: {$stats['matched']}");
}

$lock->release();
