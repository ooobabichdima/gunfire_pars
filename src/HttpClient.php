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
    private int $retryCount;
    private int $retryDelayMs;
    private int $delayMinMs;
    private int $delayMaxMs;
    private float $lastRequestTime = 0;

    public function __construct(array $config, Logger $logger)
    {
        $this->logger = $logger;
        $this->retryCount = $config['retry_count'] ?? 3;
        $this->retryDelayMs = $config['retry_delay_ms'] ?? 2000;
        $this->delayMinMs = $config['delay_min_ms'] ?? 1500;
        $this->delayMaxMs = $config['delay_max_ms'] ?? 4000;

        $this->client = new Client([
            'timeout'         => $config['timeout'] ?? 30,
            'connect_timeout' => $config['connect_timeout'] ?? 10,
            'headers'         => [
                'User-Agent'      => $config['user_agent'] ?? 'Mozilla/5.0',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            'http_errors' => false,
            'verify'      => true,
        ]);
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

    private function request(string $method, string $url, array $options = []): ?ResponseInterface
    {
        $this->throttle();

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $this->retryCount) {
            try {
                if ($attempt > 0) {
                    $delay = $this->retryDelayMs * (2 ** ($attempt - 1));
                    $this->logger->debug("Retry #{$attempt} after {$delay}ms for {$url}");
                    usleep($delay * 1000);
                }

                $this->lastRequestTime = microtime(true);
                $response = $this->client->request($method, $url, $options);

                $statusCode = $response->getStatusCode();

                // Don't retry client errors (except 429)
                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    return $response;
                }

                // Retry on 429 and 5xx
                if ($statusCode === 429 || $statusCode >= 500) {
                    $this->logger->warning("HTTP {$statusCode}, will retry: {$url}");
                    $attempt++;
                    continue;
                }

                return $response;

            } catch (ConnectException $e) {
                $lastException = $e;
                $this->logger->warning("Connection error (attempt {$attempt}): {$e->getMessage()}");
                $attempt++;
            } catch (ServerException $e) {
                $lastException = $e;
                $this->logger->warning("Server error (attempt {$attempt}): {$e->getMessage()}");
                $attempt++;
            } catch (RequestException $e) {
                $lastException = $e;
                $this->logger->error("Request error: {$e->getMessage()}");
                return null;
            }
        }

        $this->logger->error("All {$this->retryCount} retries failed for {$url}", [
            'last_error' => $lastException?->getMessage(),
        ]);

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
