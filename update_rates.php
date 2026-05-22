<?php

declare(strict_types=1);

/**
 * update_rates.php — Fetch currency rates from PrivatBank + NBP.
 *
 * Usage: php update_rates.php
 * Cron:  0 9 * * * php /path/to/update_rates.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Logger;
use App\Services\CurrencyRate;

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'rates');
$db = Database::getInstance($config['db']);

$service = new CurrencyRate($db, $logger);

$logger->console("=== Оновлення курсів валют ===\n");
$rates = $service->updateFromPrivatBank();

if (empty($rates)) {
    $logger->console("Помилка оновлення курсів");
    exit(1);
}

$logger->console("\nГотово.");
