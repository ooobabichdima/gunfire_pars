<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Logger;

session_start();

$config = require __DIR__ . '/../config/config.php';
$db = Database::getInstance($config['db']);
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'admin');

require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
