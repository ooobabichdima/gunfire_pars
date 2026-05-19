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
    private int $retryCount;
    private int $retryDelayMs;
    private int $delayMinMs;
    private int $delayMaxMs;
    private float $lastRequestTime = 0;
    private array $defaultHeaders;
    private array $clientConfig;

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
        $this->retryCount = $config['retry_count'] ?? 3;
        $this->retryDelayMs = $config['retry_delay_ms'] ?? 2000;
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

    private function request(string $method, string $url, array $options = []): ?ResponseInterface
    {
        $this->throttle();

        // Rotate User-Agent
        if (!isset($options['headers']['User-Agent'])) {
            $options['headers'] = array_merge($this->defaultHeaders, $options['headers'] ?? []);
            $options['headers']['User-Agent'] = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        }

        $attempt = 0;
        $lastException = null;
        $proxyUrl = null;
        $useProxy = $this->proxyManager !== null && $this->proxyManager->isEnabled() && !isset($options['auth']);

        while ($attempt <= $this->retryCount) {
            try {
                if ($attempt > 0) {
                    $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
                    $this->logger->debug("Retry #{$attempt} after {$delay}ms for {$url}");
                    usleep($delay * 1000);
                }

                $requestOptions = $options;

                // Apply proxy
                if ($useProxy) {
                    $proxyUrl = $this->proxyManager->getNext();
                    if ($proxyUrl !== null) {
                        $requestOptions['proxy'] = $proxyUrl;
                        $requestOptions['timeout'] = min($this->clientConfig['timeout'], 15);
                        $requestOptions['connect_timeout'] = min($this->clientConfig['connect_timeout'], 8);
                    }
                }

                $this->lastRequestTime = microtime(true);
                $response = $this->client->request($method, $url, $requestOptions);
                $statusCode = $response->getStatusCode();

                // 403 with proxy — proxy is blocked, try next
                if ($statusCode === 403 && $useProxy && $proxyUrl !== null) {
                    $this->proxyManager->markFailed($proxyUrl);
                    $this->logger->debug("Proxy blocked (403), rotating: {$proxyUrl}");
                    $attempt++;
                    continue;
                }

                // Don't retry client errors (except 429)
                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    if ($useProxy && $proxyUrl !== null) {
                        $this->proxyManager->markSuccess($proxyUrl);
                    }
                    return $response;
                }

                // Retry on 429 and 5xx
                if ($statusCode === 429 || $statusCode >= 500) {
                    if ($useProxy && $proxyUrl !== null) {
                        $this->proxyManager->markFailed($proxyUrl);
                    }
                    $this->logger->warning("HTTP {$statusCode}, will retry: {$url}");
                    $attempt++;
                    continue;
                }

                // Success
                if ($useProxy && $proxyUrl !== null) {
                    $this->proxyManager->markSuccess($proxyUrl);
                }

                return $response;

            } catch (ConnectException $e) {
                $lastException = $e;
                if ($useProxy && $proxyUrl !== null) {
                    $this->proxyManager->markFailed($proxyUrl);
                    $this->logger->debug("Proxy timeout, rotating: {$proxyUrl}");
                } else {
                    $this->logger->warning("Connection error (attempt {$attempt}): " . mb_substr($e->getMessage(), 0, 120));
                }
                $attempt++;
            } catch (ServerException $e) {
                $lastException = $e;
                if ($useProxy && $proxyUrl !== null) {
                    $this->proxyManager->markFailed($proxyUrl);
                }
                $this->logger->warning("Server error (attempt {$attempt}): " . mb_substr($e->getMessage(), 0, 120));
                $attempt++;
            } catch (RequestException $e) {
                $lastException = $e;
                if ($useProxy && $proxyUrl !== null) {
                    $this->proxyManager->markFailed($proxyUrl);
                    $attempt++;
                    continue;
                }
                $this->logger->error("Request error: " . mb_substr($e->getMessage(), 0, 120));
                return null;
            }
        }

        // Last resort: try direct (without proxy) if all proxy attempts failed
        if ($useProxy && $proxyUrl !== null) {
            $this->logger->info("All proxy attempts failed, trying direct connection: {$url}");
            try {
                $directOptions = $options;
                unset($directOptions['proxy']);
                $this->lastRequestTime = microtime(true);
                $response = $this->client->request($method, $url, $directOptions);
                if ($response->getStatusCode() < 400) {
                    return $response;
                }
            } catch (\Throwable $e) {
                $this->logger->debug("Direct fallback also failed: " . mb_substr($e->getMessage(), 0, 80));
            }
        }

        $this->logger->error("All retries failed for {$url}");
        return null;
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
