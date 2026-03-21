<?php

declare(strict_types=1);

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int)(getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'supplier_aggregator',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'http' => [
        'timeout'         => 30,
        'connect_timeout' => 10,
        'retry_count'     => 3,
        'retry_delay_ms'  => 2000,
        'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'delay_min_ms'    => 1500,
        'delay_max_ms'    => 4000,
    ],

    'parser' => [
        'batch_size'    => 50,
        'max_retries'   => 3,
        'lock_dir'      => __DIR__ . '/../logs',
    ],

    'log' => [
        'dir'   => __DIR__ . '/../logs',
        'level' => getenv('LOG_LEVEL') ?: 'info', // debug, info, warning, error
    ],

    'suppliers' => [
        'gunfire' => [
            'base_url'  => 'https://gunfire.com',
            'locale'    => 'en',
            'currency'  => 'PLN',
        ],
    ],
];
