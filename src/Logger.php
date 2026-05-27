<?php

declare(strict_types=1);

namespace App;

final class Logger
{
    private const LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    private string $logDir;
    private int $minLevel;
    private string $channel;

    public function __construct(string $logDir, string $level = 'info', string $channel = 'app')
    {
        $this->logDir = rtrim($logDir, '/');
        $this->minLevel = self::LEVELS[$level] ?? 1;
        $this->channel = $channel;

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf("[%s] [%s] [%s] %s%s\n", $timestamp, strtoupper($level), $this->channel, $message, $contextStr);

        $filename = sprintf('%s/%s_%s.log', $this->logDir, $this->channel, date('Y-m-d'));
        file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);

        // Also output to STDERR for CLI visibility
        if ($level === 'error' || $level === 'warning') {
            fwrite(STDERR, $line);
        }
    }

    public function console(string $message): void
    {
        if (PHP_SAPI === 'cli' && defined('STDOUT')) {
            fwrite(STDOUT, $message . "\n");
        }
    }
}
