<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

final class HttpClient
{
    private Client $client;
    private Logger $logger;
    private ?ProxyManager $proxyManager;
    private int $delayMinMs;
    private int $delayMaxMs;
    private float $lastRequestTime = 0;
    private array $defaultHeaders;
    private array $clientConfig;
    private int $directFailCount = 0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
    ];

    public function __construct(array $config, Logger $logger, ?ProxyManager $proxyManager = null)
    {
        $this->logger = $logger;
        $this->proxyManager = $proxyManager;
        $this->delayMinMs = $config['delay_min_ms'] ?? 1500;
        $this->delayMaxMs = $config['delay_max_ms'] ?? 4000;

        $this->defaultHeaders = [
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,pl;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection'      => 'keep-alive',
            'Sec-Fetch-Dest'  => 'document',
            'Sec-Fetch-Mode'  => 'navigate',
            'Sec-Fetch-Site'  => 'none',
            'Sec-Fetch-User'  => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        $this->clientConfig = [
            'timeout'         => $config['timeout'] ?? 30,
            'connect_timeout' => $config['connect_timeout'] ?? 15,
            'http_errors'     => false,
            'verify'          => false,
        ];

        $this->client = new Client($this->clientConfig);
    }

    public function get(string $url, array $options = []): ?ResponseInterface
    {
        return $this->request('GET', $url, $options);
    }

    public function getHtml(string $url, array $options = []): ?string
    {
        $response = $this->get($url, $options);
        if ($response === null) {
            return null;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode === 404) {
            $this->logger->warning("HTTP 404: {$url}");
            return null;
        }

        if ($statusCode >= 400) {
            $this->logger->error("HTTP {$statusCode}: {$url}");
            return null;
        }

        return (string)$response->getBody();
    }

    public function getStatusCode(string $url): int
    {
        $response = $this->get($url);
        return $response ? $response->getStatusCode() : 0;
    }

    public function getProxyManager(): ?ProxyManager
    {
        return $this->proxyManager;
    }

    /**
     * Strategy: round-robin between direct + proxies.
     * Each product gets a different IP.
     * direct → proxy1 → proxy2 → direct → proxy3 → ...
     */
    private function request(string $method, string $url, array $options = []): ?ResponseInterface
    {
        $this->throttle();

        if (!isset($options['headers']['User-Agent'])) {
            $options['headers'] = array_merge($this->defaultHeaders, $options['headers'] ?? []);
            $options['headers']['User-Agent'] = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        }

        $hasProxy = $this->proxyManager !== null
            && $this->proxyManager->isEnabled()
            && !isset($options['auth']);

        // Build list of channels: [null=direct, proxy1, proxy2, ...]
        $channels = $this->buildChannelList($hasProxy);

        foreach ($channels as $channelProxy) {
            $response = $this->attemptRequest($method, $url, $options, $channelProxy);

            if ($response === null) {
                // Timeout/connection error — mark failed, try next
                if ($channelProxy !== null && $hasProxy) {
                    $this->proxyManager->markFailed($channelProxy);
                }
                if ($channelProxy === null) {
                    $this->directFailCount++;
                }
                continue;
            }

            $statusCode = $response->getStatusCode();

            // Ban/rate-limit — try next channel
            if ($statusCode === 403 || $statusCode === 429) {
                if ($channelProxy !== null && $hasProxy) {
                    $this->proxyManager->markFailed($channelProxy);
                    $this->logger->debug("Proxy blocked ({$statusCode}): " . mb_substr($channelProxy, 0, 25));
                } else {
                    $this->directFailCount++;
                    $this->logger->debug("Direct blocked ({$statusCode})");
                }
                continue;
            }

            // Server error — try next
            if ($statusCode >= 500) {
                if ($channelProxy !== null && $hasProxy) {
                    $this->proxyManager->markFailed($channelProxy);
                }
                continue;
            }

            // Success — mark proxy as good, reset direct counter
            if ($channelProxy !== null && $hasProxy) {
                $this->proxyManager->markSuccess($channelProxy);
            }
            if ($channelProxy === null) {
                $this->directFailCount = 0;
            }

            return $response;
        }

        $this->logger->error("Всі канали невдалі: {$url}");
        return null;
    }

    /**
     * Build a shuffled list of channels for this request.
     * Includes direct (null) + proxy URLs, randomized.
     */
    private function buildChannelList(bool $hasProxy): array
    {
        $channels = [];

        // Add direct if not consistently banned
        if ($this->directFailCount < 5) {
            $channels[] = null; // null = direct connection
        }

        // Add up to 3 random proxies
        if ($hasProxy) {
            for ($i = 0; $i < 3; $i++) {
                $proxy = $this->proxyManager->getNext();
                if ($proxy !== null && !in_array($proxy, $channels, true)) {
                    $channels[] = $proxy;
                }
            }
        }

        // Always have at least direct as fallback
        if (empty($channels)) {
            $channels[] = null;
        }

        // Shuffle so each request uses a random channel first
        shuffle($channels);

        return $channels;
    }

    private function attemptRequest(string $method, string $url, array $options, ?string $proxyUrl): ?ResponseInterface
    {
        try {
            $requestOptions = $options;

            if ($proxyUrl !== null) {
                $requestOptions['proxy'] = $proxyUrl;
                $requestOptions['timeout'] = 12;
                $requestOptions['connect_timeout'] = 6;
            }

            $this->lastRequestTime = microtime(true);
            return $this->client->request($method, $url, $requestOptions);

        } catch (ConnectException $e) {
            $label = $proxyUrl ? mb_substr($proxyUrl, 0, 25) : 'direct';
            $this->logger->debug("Timeout ({$label}): " . mb_substr($e->getMessage(), 0, 60));
            return null;
        } catch (ServerException $e) {
            return null;
        } catch (RequestException $e) {
            return null;
        }
    }

    private function throttle(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            $minDelay = random_int($this->delayMinMs, $this->delayMaxMs);
            if ($elapsed < $minDelay) {
                $wait = (int)($minDelay - $elapsed);
                usleep($wait * 1000);
            }
        }
    }
}
