<?php

declare(strict_types=1);

/**
 * debug_xls.php — Dump first 20 rows of XLS file to see actual structure.
 *
 * Usage: php debug_xls.php --file=logs/ibis_xls/Вільні\ залишки\ Київ.xls
 */

require_once __DIR__ . '/vendor/autoload.php';

$opts = getopt('', ['file:', 'rows:', 'help']);
if (isset($opts['help']) || empty($opts['file'])) {
    echo "Usage: php debug_xls.php --file=path/to/file.xls [--rows=20]\n";
    exit(0);
}

$file = $opts['file'];
$maxRows = (int)($opts['rows'] ?? 20);

if (!file_exists($file)) {
    echo "File not found: {$file}\n";
    exit(1);
}

echo "File: {$file}\n";
echo "Size: " . number_format(filesize($file)) . " bytes\n\n";

$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);

$sheetCount = $spreadsheet->getSheetCount();
echo "Sheets: {$sheetCount}\n\n";

for ($si = 0; $si < $sheetCount; $si++) {
    $sheet = $spreadsheet->getSheet($si);
    $title = $sheet->getTitle();
    $highestRow = $sheet->getHighestRow();
    $highestCol = $sheet->getHighestColumn();
    $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

    echo "=== Sheet [{$si}]: \"{$title}\" ({$highestRow} rows x {$highestCol} columns) ===\n\n";

    $rows = $sheet->toArray(null, true, true, true);
    $rowCount = 0;

    foreach ($rows as $rowIndex => $row) {
        if ($rowCount >= $maxRows) {
            echo "... (showing first {$maxRows} rows)\n";
            break;
        }

        $cells = [];
        $hasContent = false;
        foreach ($row as $col => $val) {
            $val = trim((string)($val ?? ''));
            if ($val !== '') {
                $hasContent = true;
            }
            $cells[] = "{$col}:" . mb_substr($val, 0, 40);
        }

        if ($hasContent) {
            echo "Row {$rowIndex}: " . implode(' | ', $cells) . "\n";
        } else {
            echo "Row {$rowIndex}: (empty)\n";
        }

        $rowCount++;
    }

    echo "\n";
}

echo "=== Done ===\n";
