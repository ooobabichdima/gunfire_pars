<?php

declare(strict_types=1);

namespace App\Suppliers;

use App\Database;
use App\HttpClient;
use App\Logger;
use App\Suppliers\Gunfire\GunfireParser;
use App\Suppliers\Ibis\IbisParser;

final class SupplierParserFactory
{
    public static function create(
        string $code,
        Database $db,
        HttpClient $http,
        Logger $logger
    ): SupplierParserInterface {
        $supplier = $db->fetchOne(
            'SELECT id, config_json FROM suppliers WHERE code = ? AND is_active = 1',
            [$code]
        );

        if (!$supplier) {
            throw new \RuntimeException("Supplier '{$code}' not found or inactive");
        }

        $dbConfig = json_decode($supplier['config_json'] ?? '{}', true) ?: [];

        $fileConfig = require dirname(__DIR__, 2) . '/config/config.php';
        $supplierFileConfig = $fileConfig['suppliers'][$code] ?? [];

        $config = array_merge($supplierFileConfig, $dbConfig);

        return match ($code) {
            'gunfire' => new GunfireParser($db, $http, $logger, $config),
            'ibis'    => new IbisParser($db, $http, $logger, $config),
            default   => throw new \RuntimeException("No parser class for supplier: {$code}"),
        };
    }

    public static function getAvailableCodes(): array
    {
        return ['gunfire', 'ibis'];
    }
}
