<?php

declare(strict_types=1);

/**
 * import_ibis.php — Download and import IBIS XLS price lists.
 *
 * Usage:
 *   php import_ibis.php [--download] [--file=path/to/file.xls] [--all]
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\HttpClient;
use App\Lock;
use App\Logger;
use App\Suppliers\Ibis\IbisParser;

$opts = getopt('', ['download', 'file:', 'all', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php import_ibis.php [--download] [--file=path.xls] [--all]\n";
    echo "  --download   Download fresh XLS files from obmen.ibis.net.ua\n";
    echo "  --file       Import a specific XLS file\n";
    echo "  --all        Download + import all files\n";
    exit(0);
}

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log']['dir'], $config['log']['level'], 'ibis_import');

$lock = new Lock($config['parser']['lock_dir'], 'import_ibis');
if (!$lock->acquire()) {
    fwrite(STDERR, "Lock file exists. Another process may be running.\n");
    exit(1);
}

$db = Database::getInstance($config['db']);
$http = new HttpClient($config['http'], $logger);

$supplierConfig = json_decode(
    $db->fetchOne("SELECT config_json FROM suppliers WHERE code = 'ibis'")['config_json'] ?? '{}',
    true
) ?: [];

$parser = new IbisParser($db, $http, $logger, array_merge($config['suppliers']['ibis'] ?? [], $supplierConfig));

$downloadDir = __DIR__ . '/logs/ibis_xls';

$doDownload = isset($opts['download']) || isset($opts['all']);
$specificFile = $opts['file'] ?? '';

if ($doDownload) {
    $logger->console("Downloading XLS files...");
    $downloaded = $parser->downloadXlsFiles($downloadDir);
    $logger->console("Downloaded " . count($downloaded) . " files");

    if (isset($opts['all'])) {
        foreach ($downloaded as $filePath) {
            $logger->console("\nImporting: " . basename($filePath));
            $stats = $parser->importXls($filePath);
            $logger->console("  Imported: {$stats['imported']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");
        }
    }
} elseif (!empty($specificFile)) {
    if (!file_exists($specificFile)) {
        fwrite(STDERR, "File not found: {$specificFile}\n");
        exit(1);
    }
    $logger->console("Importing: {$specificFile}");
    $stats = $parser->importXls($specificFile);
    $logger->console("Imported: {$stats['imported']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");
} else {
    // Import any existing files in download dir
    if (is_dir($downloadDir)) {
        $xlsFiles = glob($downloadDir . '/*.xls') ?: [];
        $xlsxFiles = glob($downloadDir . '/*.xlsx') ?: [];
        $allFiles = array_merge($xlsFiles, $xlsxFiles);

        if (empty($allFiles)) {
            $logger->console("No XLS files found. Run with --download first.");
        }

        foreach ($allFiles as $filePath) {
            $logger->console("Importing: " . basename($filePath));
            $stats = $parser->importXls($filePath);
            $logger->console("  Imported: {$stats['imported']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");
        }
    } else {
        $logger->console("No XLS files found. Run with --download first.");
    }
}

$lock->release();
$logger->console("\nDone.");
