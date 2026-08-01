<?php

declare(strict_types=1);

use OpenSpout\Reader\XLSX\Reader;

require __DIR__ . '/../../vendor/autoload.php';

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "Usage: php dump_xlsx.php <file.xlsx>\n");
    exit(1);
}

try {
    $reader = new Reader();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot create reader: ' . $e->getMessage() . "\n");
    exit(1);
}

$reader->open($path);

$rowNo = 0;
foreach ($reader->getSheetIterator() as $sheet) {
    foreach ($sheet->getRowIterator() as $row) {
        $rowNo++;
        $values = array_values($row->toArray());
        $col = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R'];
        $out = [];
        foreach ($values as $i => $v) {
            $label = $col[$i] ?? ('?' . $i);
            if ($v === null || $v === '') {
                continue;
            }
            if (is_string($v) && (str_contains($v, "\n") || mb_strlen($v) > 40)) {
                $v = str_replace("\n", '\\n', $v);
                $v = mb_substr($v, 0, 40) . '…';
            }
            $out[] = $label . '=' . (is_scalar($v) ? $v : json_encode($v));
        }
        printf("%3d: %s\n", $rowNo, implode(' | ', $out));
    }
}

$reader->close();
