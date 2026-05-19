<?php

declare(strict_types=1);

namespace App;

final class ProxyManager
{
    private Logger $logger;
    private string $cacheFile;
    private int $cacheTtl;

    /** @var array<int, array{url: string, fails: int, lastUsed: float}> */
    private array $proxies = [];
    private int $currentIndex = 0;
    private int $maxFails = 3;
    private bool $enabled = true;

    private const FREE_PROXY_APIS = [
        'https://api.proxyscrape.com/v2/?request=displayproxies&protocol=http&timeout=5000&country=all&ssl=yes&anonymity=all',
        'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt',
        'https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt',
        'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt',
        'https://raw.githubusercontent.com/hookzof/socks5_list/master/proxy.txt',
    ];

    public function __construct(Logger $logger, string $cacheDir, int $cacheTtlMinutes = 30)
    {
        $this->logger = $logger;
        $this->cacheFile = rtrim($cacheDir, '/') . '/proxy_list.json';
        $this->cacheTtl = $cacheTtlMinutes * 60;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Load proxies from cache or fetch fresh ones.
     */
    public function load(): void
    {
        if ($this->loadFromCache()) {
            $working = $this->getWorkingCount();
            $this->logger->console("[proxy] Кеш: завантажено {$working} робочих проксі (кеш " . (int)((time() - filemtime($this->cacheFile)) / 60) . " хв)");
            return;
        }

        $this->logger->console("[proxy] Кеш відсутній або застарілий. Збираю свіжі проксі...");
        $this->fetchFreshProxies();
    }

    /**
     * Get a random working proxy URL.
     */
    public function getNext(): ?string
    {
        if (!$this->enabled || empty($this->proxies)) {
            return null;
        }

        // Collect working proxies
        $working = [];
        foreach ($this->proxies as $i => $proxy) {
            if ($proxy['fails'] < $this->maxFails) {
                $working[] = $i;
            }
        }

        if (empty($working)) {
            $this->logger->console("[proxy] Всі проксі вичерпані, оновлюю список...");
            $this->fetchFreshProxies();

            $working = [];
            foreach ($this->proxies as $i => $proxy) {
                if ($proxy['fails'] < $this->maxFails) {
                    $working[] = $i;
                }
            }
        }

        if (empty($working)) {
            return null;
        }

        // Pick random working proxy
        $idx = $working[array_rand($working)];
        return $this->proxies[$idx]['url'];
    }

    /**
     * Mark current proxy as failed.
     */
    public function markFailed(?string $proxyUrl): void
    {
        if ($proxyUrl === null) {
            return;
        }

        foreach ($this->proxies as &$proxy) {
            if ($proxy['url'] === $proxyUrl) {
                $proxy['fails']++;
                $this->logger->debug("Proxy failed ({$proxy['fails']}/{$this->maxFails}): {$proxyUrl}");
                break;
            }
        }
        unset($proxy);

        $this->saveToCache();
    }

    /**
     * Mark current proxy as successful (reset fail counter).
     */
    public function markSuccess(?string $proxyUrl): void
    {
        if ($proxyUrl === null) {
            return;
        }

        foreach ($this->proxies as &$proxy) {
            if ($proxy['url'] === $proxyUrl) {
                $proxy['fails'] = 0;
                $proxy['lastUsed'] = microtime(true);
                break;
            }
        }
        unset($proxy);
    }

    /**
     * Add a custom proxy (e.g., paid ones from config).
     */
    public function addProxy(string $url): void
    {
        foreach ($this->proxies as $p) {
            if ($p['url'] === $url) {
                return;
            }
        }

        // Prepend custom proxies (higher priority)
        array_unshift($this->proxies, [
            'url'      => $url,
            'fails'    => 0,
            'lastUsed' => 0.0,
        ]);
    }

    public function getCount(): int
    {
        return count($this->proxies);
    }

    public function getWorkingCount(): int
    {
        return count(array_filter($this->proxies, fn($p) => $p['fails'] < $this->maxFails));
    }

    /**
     * Fetch free proxy lists from multiple sources.
     */
    private function fetchFreshProxies(): void
    {
        $this->logger->console("[proxy] Завантаження списків проксі...");
        $rawProxies = [];
        $sourceNum = 0;
        $totalSources = count(self::FREE_PROXY_APIS);

        foreach (self::FREE_PROXY_APIS as $apiUrl) {
            $sourceNum++;
            try {
                $ctx = stream_context_create([
                    'http' => ['timeout' => 10, 'ignore_errors' => true],
                    'ssl'  => ['verify_peer' => false],
                ]);
                $content = @file_get_contents($apiUrl, false, $ctx);
                if ($content === false) {
                    continue;
                }

                $lines = preg_split('/[\r\n]+/', $content);
                foreach ($lines as $line) {
                    $line = trim($line);
                    // Match IP:PORT pattern
                    if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):(\d{2,5})$/', $line, $m)) {
                        $rawProxies[] = "http://{$m[1]}:{$m[2]}";
                    }
                }

                $host = parse_url($apiUrl, PHP_URL_HOST);
                $found = count($lines);
                $this->logger->console("[proxy] [{$sourceNum}/{$totalSources}] {$host}: +{$found} проксі");

            } catch (\Throwable $e) {
                $this->logger->console("[proxy] [{$sourceNum}/{$totalSources}] Помилка: " . mb_substr($e->getMessage(), 0, 60));
            }
        }

        $rawProxies = array_unique($rawProxies);
        $this->logger->console("[proxy] Зібрано " . count($rawProxies) . " унікальних проксі");
        $this->logger->console("[proxy] Тестування (до 50 проксі)...");

        $tested = $this->quickTest($rawProxies, 50);

        $this->proxies = [];
        foreach ($tested as $url) {
            $this->proxies[] = [
                'url'      => $url,
                'fails'    => 0,
                'lastUsed' => 0.0,
            ];
        }

        $this->logger->console("[proxy] Готово: " . count($this->proxies) . " робочих проксі збережено в кеш");
        $this->saveToCache();
    }

    /**
     * Quick-test proxies by connecting to a fast endpoint.
     */
    private function quickTest(array $proxyUrls, int $maxTest = 50): array
    {
        $working = [];
        $tested = 0;
        // Test against the actual target, not httpbin
        $testUrl = 'https://gunfire.com/en/';

        // Shuffle for randomness
        shuffle($proxyUrls);

        foreach ($proxyUrls as $proxyUrl) {
            if ($tested >= $maxTest || count($working) >= 20) {
                break;
            }

            $tested++;

            try {
                $ctx = stream_context_create([
                    'http' => [
                        'proxy'           => str_replace('http://', 'tcp://', $proxyUrl),
                        'request_fulluri' => true,
                        'timeout'         => 5,
                        'ignore_errors'   => true,
                        'header'          => "User-Agent: Mozilla/5.0\r\n",
                    ],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);

                $result = @file_get_contents($testUrl, false, $ctx);
                if ($result !== false && strlen($result) > 1000) {
                    $working[] = $proxyUrl;
                    fwrite(STDOUT, "\r[proxy] Тест: {$tested}/{$maxTest} | Робочих: " . count($working) . " | OK: {$proxyUrl}              ");
                } else {
                    fwrite(STDOUT, "\r[proxy] Тест: {$tested}/{$maxTest} | Робочих: " . count($working) . " | FAIL                                ");
                }
            } catch (\Throwable) {
                fwrite(STDOUT, "\r[proxy] Тест: {$tested}/{$maxTest} | Робочих: " . count($working) . " | timeout                              ");
            }
        }

        fwrite(STDOUT, "\n");
        return $working;
    }

    private function loadFromCache(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $mtime = filemtime($this->cacheFile);
        if ($mtime === false || (time() - $mtime) > $this->cacheTtl) {
            return false;
        }

        $data = json_decode(file_get_contents($this->cacheFile), true);
        if (!is_array($data) || empty($data)) {
            return false;
        }

        $this->proxies = $data;
        return true;
    }

    private function saveToCache(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->cacheFile,
            json_encode($this->proxies, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
