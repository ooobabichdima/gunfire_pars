<?php

declare(strict_types=1);

/**
 * build_relations.php — Build category tree and product-category relations from parsed data.
 *
 * Usage:
 *   php build_relations.php [--limit=500] [--offset=0]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Lock;
use App\Logger;
use App\Services\RelationBuilder;

// ---------------------------------------------------------------------------
// CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['limit:', 'offset:', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php build_relations.php [--limit=500] [--offset=0]\n";
    exit(0);
}

$limit = (int)($opts['limit'] ?? 500);
$offset = (int)($opts['offset'] ?? 0);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'relations');

$lock = new Lock($config['parser']['lock_dir'], 'build_relations');
if (!$lock->acquire()) {
    $logger->warning("Another relations process is running");
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);
$builder = new RelationBuilder($db, $logger);

$logger->console("Building category relations (limit: {$limit}, offset: {$offset})");

$stats = $builder->buildCategoryRelations($limit, $offset);

$logger->console("\nRelation building complete:");
$logger->console("  Offers processed:    {$stats['processed']}");
$logger->console("  Categories touched:  {$stats['categories_created']}");
$logger->console("  Relations created:   {$stats['relations_created']}");

$lock->release();
