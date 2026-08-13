#!/usr/bin/env php
<?php

/**
 * Turns an Ordnance Survey Code-Point Open download into the SQLite file CodePointOpenLookup reads.
 *
 *   1. Download Code-Point Open from https://www.ordnancesurvey.co.uk/products/code-point-open
 *      (free; check the licence terms — attribution is normally required).
 *   2. Unzip it. The postcode data is a directory of CSV files, one per postcode area.
 *   3. php tools/import-code-point-open.php /path/to/unzipped/Data/CSV /path/to/postcodes.sqlite
 *
 * The CSV has no header. Columns used here, by position, per the OS product spec:
 *   0 postcode, 8 country code (GSS), 9 admin county, 10 admin district
 */

declare(strict_types=1);

$source = $argv[1] ?? null;
$target = $argv[2] ?? null;

if (null === $source || null === $target) {
    fwrite(STDERR, "usage: import-code-point-open.php <csv-directory> <output.sqlite>\n");

    exit(1);
}

if (!is_dir($source)) {
    fwrite(STDERR, "not a directory: {$source}\n");

    exit(1);
}

@unlink($target);

$pdo = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA journal_mode = OFF');
$pdo->exec('PRAGMA synchronous = OFF');
$pdo->exec('CREATE TABLE postcodes (postcode TEXT PRIMARY KEY, country_code TEXT NOT NULL, district TEXT)');

$insert = $pdo->prepare('INSERT OR IGNORE INTO postcodes (postcode, country_code, district) VALUES (?, ?, ?)');
$files = glob(rtrim($source, '/') . '/*.csv') ?: [];

if ([] === $files) {
    fwrite(STDERR, "no CSV files in {$source}\n");

    exit(1);
}

$rows = 0;
$pdo->beginTransaction();

foreach ($files as $file) {
    $handle = fopen($file, 'r');

    if (false === $handle) {
        continue;
    }

    while (false !== ($row = fgetcsv($handle, 0, ',', '"', ''))) {
        if (!isset($row[0], $row[8])) {
            continue;
        }

        // Code-Point pads the outward code to four characters; the lookup key has no spaces.
        $postcode = strtoupper(str_replace(' ', '', (string) $row[0]));

        if ('' === $postcode) {
            continue;
        }

        $insert->execute([$postcode, (string) $row[8], (string) ($row[10] ?? '')]);
        ++$rows;

        if (0 === $rows % 100000) {
            $pdo->commit();
            $pdo->beginTransaction();
            fprintf(STDERR, "  %d postcodes…\n", $rows);
        }
    }

    fclose($handle);
}

$pdo->commit();

printf("%d postcodes written to %s (%.1f MB)\n", $rows, $target, filesize($target) / 1048576);
