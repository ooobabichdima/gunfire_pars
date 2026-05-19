<?php

$config = require __DIR__ . '/config/config.php';

echo "custom_proxies:\n";
$proxies = $config['http']['custom_proxies'] ?? [];
echo "  count: " . count($proxies) . "\n";
foreach ($proxies as $p) {
    echo "  - " . preg_replace('#://[^@]+@#', '://***@', $p) . "\n";
}
