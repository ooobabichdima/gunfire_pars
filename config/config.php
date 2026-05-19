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
        'connect_timeout' => 15,
        'retry_count'     => 3,
        'retry_delay_ms'  => 5000,
        'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'delay_min_ms'    => 3000,
        'delay_max_ms'    => 7000,
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
        'ibis' => [
            'base_url'          => 'https://ibis.net.ua',
            'locale'            => 'ua',
            'currency'          => 'UAH',
            'xls_base_url'      => 'https://obmen.ibis.net.ua/arm/',
            'xls_auth_login'    => 'arm',
            'xls_auth_password' => 'arm',
        ],
    ],
];
