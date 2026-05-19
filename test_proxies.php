<?php

/**
 * test_proxies.php — Test all proxies against gunfire.com
 */

$proxies = [
    '62.192.175.128:59100:babichdima4:86VaFZ9NTP',
    '151.243.174.252:59100:babichdima4:86VaFZ9NTP',
    '62.192.175.169:59100:babichdima4:86VaFZ9NTP',
    '194.60.90.56:59100:babichdima4:86VaFZ9NTP',
    '62.192.175.168:59100:babichdima4:86VaFZ9NTP',
    '194.60.90.55:59100:babichdima4:86VaFZ9NTP',
    '62.192.175.165:59100:babichdima4:86VaFZ9NTP',
    '194.60.90.54:59100:babichdima4:86VaFZ9NTP',
    '62.192.175.164:59100:babichdima4:86VaFZ9NTP',
    '194.60.90.53:59100:babichdima4:86VaFZ9NTP',
];

$testUrls = [
    'gunfire'  => 'https://gunfire.com/en/',
    'httpbin'  => 'https://httpbin.org/ip',
    'ibis'     => 'https://ibis.net.ua/',
];

echo "=== Тест проксі ===\n\n";

// Test direct first
echo "--- ПРЯМИЙ (без проксі) ---\n";
foreach ($testUrls as $name => $url) {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = round(microtime(true) - $start, 1);
    $size = $body ? strlen($body) : 0;
    curl_close($ch);

    $status = $code == 200 ? "\033[32mOK\033[0m" : "\033[31m{$code}\033[0m";
    echo "  {$name}: {$status} | {$time}s | {$size} bytes\n";
}

echo "\n--- ПРОКСІ ---\n";
printf("%-22s | %-12s | %-12s | %-12s | %-8s\n", "Proxy IP", "gunfire", "httpbin", "ibis", "Ваш IP");
echo str_repeat('-', 80) . "\n";

foreach ($proxies as $proxyStr) {
    $parts = explode(':', $proxyStr);
    $ip = $parts[0];
    $port = $parts[1];
    $user = $parts[2];
    $pass = $parts[3];
    $proxyUrl = "http://{$user}:{$pass}@{$ip}:{$port}";

    $results = [];
    $externalIp = '?';

    foreach ($testUrls as $name => $url) {
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $proxyUrl,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time = round(microtime(true) - $start, 1);
        curl_close($ch);

        if ($name === 'httpbin' && $code == 200 && $body) {
            $json = json_decode($body, true);
            $externalIp = $json['origin'] ?? '?';
        }

        $results[$name] = $code == 200 ? "\033[32m200 {$time}s\033[0m" : "\033[31m{$code} {$time}s\033[0m";
    }

    printf("%-22s | %-22s | %-22s | %-22s | %s\n",
        "{$ip}:{$port}",
        $results['gunfire'],
        $results['httpbin'],
        $results['ibis'],
        $externalIp
    );
}

echo "\n=== Готово ===\n";
