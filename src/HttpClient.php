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
    private bool $directBanned = false;

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

        if (!isset($options['headers']['User-Agent'])) {
            $options['headers'] = array_merge($this->defaultHeaders, $options['headers'] ?? []);
            $options['headers']['User-Agent'] = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        }

        $hasProxy = $this->proxyManager !== null && $this->proxyManager->isEnabled() && !isset($options['auth']);

        // Strategy: direct first → if banned, switch to proxy
        if (!$this->directBanned) {
            $response = $this->tryDirect($method, $url, $options);
            if ($response !== null) {
                $code = $response->getStatusCode();
                if ($code === 403 || $code === 429) {
                    $this->logger->console("  [http] Прямий запит заблоковано ({$code}), переключаюсь на проксі");
                    $this->directBanned = true;
                } else {
                    return $response;
                }
            } else {
                // Timeout on direct — also try proxy
                if ($hasProxy) {
                    $this->logger->debug("Direct timed out, trying proxy");
                    $this->directBanned = true;
                } else {
                    return null;
                }
            }
        }

        // Proxy mode
        if ($hasProxy) {
            $response = $this->tryWithProxies($method, $url, $options);
            if ($response !== null) {
                return $response;
            }

            // All proxies failed — try direct one more time as last resort
            $this->logger->debug("All proxies failed, last-resort direct attempt");
            $directResponse = $this->tryDirect($method, $url, $options);
            if ($directResponse !== null && $directResponse->getStatusCode() < 400) {
                $this->directBanned = false;
                return $directResponse;
            }
        }

        $this->logger->error("Всі спроби невдалі: {$url}");
        return null;
    }

    private function tryDirect(string $method, string $url, array $options): ?ResponseInterface
    {
        try {
            $this->lastRequestTime = microtime(true);
            unset($options['proxy']);
            return $this->client->request($method, $url, $options);
        } catch (ConnectException $e) {
            $this->logger->warning("Direct timeout: " . mb_substr($e->getMessage(), 0, 80));
            return null;
        } catch (RequestException $e) {
            $this->logger->warning("Direct error: " . mb_substr($e->getMessage(), 0, 80));
            return null;
        }
    }

    private function tryWithProxies(string $method, string $url, array $options): ?ResponseInterface
    {
        $maxProxyAttempts = min($this->retryCount + 2, $this->proxyManager->getWorkingCount() + 1);

        for ($attempt = 0; $attempt < $maxProxyAttempts; $attempt++) {
            $proxyUrl = $this->proxyManager->getNext();
            if ($proxyUrl === null) {
                $this->logger->debug("No more proxies available");
                break;
            }

            try {
                if ($attempt > 0) {
                    usleep(1000 * 1000); // 1s between proxy attempts
                }

                $requestOptions = $options;
                $requestOptions['proxy'] = $proxyUrl;
                $requestOptions['timeout'] = 12;
                $requestOptions['connect_timeout'] = 6;

                $this->lastRequestTime = microtime(true);
                $response = $this->client->request($method, $url, $requestOptions);
                $statusCode = $response->getStatusCode();

                if ($statusCode === 403 || $statusCode === 429) {
                    $this->proxyManager->markFailed($proxyUrl);
                    $this->logger->debug("Proxy {$proxyUrl} blocked ({$statusCode}), next...");
                    continue;
                }

                if ($statusCode >= 500) {
                    $this->proxyManager->markFailed($proxyUrl);
                    continue;
                }

                $this->proxyManager->markSuccess($proxyUrl);
                return $response;

            } catch (ConnectException $e) {
                $this->proxyManager->markFailed($proxyUrl);
                $this->logger->debug("Proxy timeout: " . mb_substr($proxyUrl, 0, 30));
                continue;
            } catch (ServerException $e) {
                $this->proxyManager->markFailed($proxyUrl);
                continue;
            } catch (RequestException $e) {
                $this->proxyManager->markFailed($proxyUrl);
                continue;
            }
        }

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
