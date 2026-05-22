<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;

final class CurrencyRate
{
    private Database $db;
    private Logger $logger;

    private const PRIVATBANK_API = 'https://api.privatbank.ua/p24api/pubinfo?json&exchange&coursid=5';

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function updateFromPrivatBank(): array
    {
        $this->logger->info("Fetching rates from PrivatBank API...");

        $json = @file_get_contents(self::PRIVATBANK_API);
        if ($json === false) {
            $this->logger->error("Failed to fetch PrivatBank rates");
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->logger->error("Invalid PrivatBank response");
            return [];
        }

        $rates = [];

        foreach ($data as $item) {
            $ccy = $item['ccy'] ?? '';
            $buy = (float)($item['buy'] ?? 0);
            $sale = (float)($item['sale'] ?? 0);

            if ($ccy === 'EUR' && $sale > 0) {
                $rates['eur_uah_rate'] = round($sale, 2);
                $this->saveRate('eur_uah_rate', (string)$rates['eur_uah_rate']);
                $this->logger->console("  EUR/UAH: {$rates['eur_uah_rate']} (buy: {$buy})");
            }

            if ($ccy === 'USD' && $sale > 0) {
                $rates['usd_uah_rate'] = round($sale, 2);
                $this->saveRate('usd_uah_rate', (string)$rates['usd_uah_rate']);
                $this->logger->console("  USD/UAH: {$rates['usd_uah_rate']} (buy: {$buy})");
            }
        }

        // PLN — PrivatBank не дає PLN напряму, рахуємо через EUR
        // Шукаємо PLN/EUR на інших джерелах або через NBP
        $plnRate = $this->fetchPlnRate($rates['eur_uah_rate'] ?? 0);
        if ($plnRate > 0) {
            $rates['pln_uah_rate'] = $plnRate;
            $this->saveRate('pln_uah_rate', (string)$plnRate);
            $this->logger->console("  PLN/UAH: {$plnRate}");
        }

        $this->saveRate('rates_updated_at', date('Y-m-d H:i:s'));
        $this->logger->info("Rates updated", $rates);

        return $rates;
    }

    public function getRate(string $key): float
    {
        $defaults = ['eur_uah_rate' => 45.5, 'usd_uah_rate' => 41.5, 'pln_uah_rate' => 10.9];
        $row = $this->db->fetchOne("SELECT value FROM settings WHERE `key` = ?", [$key]);
        return (float)($row['value'] ?? $defaults[$key] ?? 0);
    }

    public function getAllRates(): array
    {
        return [
            'eur_uah_rate' => $this->getRate('eur_uah_rate'),
            'usd_uah_rate' => $this->getRate('usd_uah_rate'),
            'pln_uah_rate' => $this->getRate('pln_uah_rate'),
            'updated_at'   => $this->db->fetchOne("SELECT value FROM settings WHERE `key` = 'rates_updated_at'")['value'] ?? 'never',
        ];
    }

    private function fetchPlnRate(float $eurUah): float
    {
        // NBP API — Polish National Bank gives EUR/PLN
        $json = @file_get_contents('https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json');
        if ($json !== false) {
            $data = json_decode($json, true);
            $eurPln = (float)($data['rates'][0]['mid'] ?? 0);
            if ($eurPln > 0 && $eurUah > 0) {
                $plnUah = round($eurUah / $eurPln, 4);
                $this->logger->console("  EUR/PLN (NBP): {$eurPln} → PLN/UAH: {$plnUah}");
                return round($plnUah, 2);
            }
        }

        // Fallback
        return 0;
    }

    private function saveRate(string $key, string $value): void
    {
        $this->db->query(
            "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$key, $value]
        );
    }
}
