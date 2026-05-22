<?php

declare(strict_types=1);

/**
 * update_b2b_prices.php — Login to b2b.gunfire.com and update wholesale prices.
 *
 * Usage:
 *   php update_b2b_prices.php [--limit=50] [--offset=0] [--sku=SWL-03-018552]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Lock;
use App\Logger;
use App\Suppliers\GunfireB2b\GunfireB2bParser;

$opts = getopt('', ['limit:', 'offset:', 'sku:', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php update_b2b_prices.php [--limit=50] [--offset=0] [--sku=CODE]\n";
    echo "  --limit    Products per batch (default: 50)\n";
    echo "  --offset   Skip first N products\n";
    echo "  --sku      Update single product by SKU code\n";
    exit(0);
}

$limit = (int)($opts['limit'] ?? 50);
$offset = (int)($opts['offset'] ?? 0);
$singleSku = $opts['sku'] ?? '';

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'b2b');

$lock = new Lock($config['parser']['lock_dir'], 'update_b2b');
if (!$lock->acquire()) {
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);

// Get B2B config from supplier or config.php
$b2bConfig = $config['suppliers']['gunfire'] ?? [];
$supplierRow = $db->fetchOne("SELECT config_json FROM suppliers WHERE code = 'gunfire'");
$supplierCfg = json_decode($supplierRow['config_json'] ?? '{}', true) ?: [];
$b2bConfig = array_merge($b2bConfig, $supplierCfg);

$parser = new GunfireB2bParser($db, $logger, $b2bConfig);

// Login
if (!$parser->login()) {
    $logger->console("Не вдалося авторизуватись в B2B");
    $lock->release();
    exit(1);
}

$startTime = microtime(true);

if (!empty($singleSku)) {
    // Single product by SKU
    $logger->console("Пошук по SKU: {$singleSku}");
    $result = $parser->searchBySku($singleSku);
    if ($result) {
        $logger->console("Знайдено: " . ($result['name'] ?? '—'));
        $logger->console("  SKU: " . ($result['sku'] ?? '—'));
        $logger->console("  Net: " . number_format((float)($result['net_price'] ?? 0), 2) . " PLN");
        $logger->console("  Gross: " . (isset($result['gross_price']) ? number_format($result['gross_price'], 2) : '—') . " PLN");
        $logger->console("  Suggested: " . (isset($result['suggested_price']) ? number_format($result['suggested_price'], 2) : '—') . " PLN");
        $logger->console("  Stock: " . ($result['stock'] ?? '—'));
        $logger->console("  EAN: " . ($result['ean'] ?? '—'));
        $logger->console("  IAI: " . ($result['iai'] ?? '—'));
    } else {
        $logger->console("Товар не знайдено");
    }
} else {
    // Bulk update
    $logger->console("=== Оновлення B2B цін (ліміт: {$limit}, зсув: {$offset}) ===\n");
    $stats = $parser->updateGunfireOffers($limit, $offset);

    $elapsed = round(microtime(true) - $startTime, 1);
    $logger->console("\n=== Результат ===");
    $logger->console("  Оновлено:   {$stats['updated']}");
    $logger->console("  Не знайдено: {$stats['not_found']}");
    $logger->console("  Помилок:    {$stats['errors']}");
    $logger->console("  Час:        {$elapsed}с");
}

$lock->release();
