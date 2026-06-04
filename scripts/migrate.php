<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use SinclearChat\Config;
use SinclearChat\Database;

$config = Config::getInstance();
$db = Database::getMigrationConnection();

$migrationsDir = dirname(__DIR__) . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

$defaultTtlDays = max(1, $config->getInt('DEFAULT_TTL_DAYS', 30));

foreach ($files as $file) {
    $filename = basename($file);
    echo "Running migration: {$filename}...\n";

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        echo "  Skipped (empty)\n";
        continue;
    }

    $sql = stripDelimiterDirectives($sql);
    $sql = str_replace('{{DEFAULT_TTL_DAYS}}', (string) $defaultTtlDays, $sql);

    try {
        $db->exec($sql);
        echo "  Done.\n";
    } catch (\PDOException $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "All migrations completed.\n";

function stripDelimiterDirectives(string $sql): string
{
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    $out = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (stripos($trimmed, 'DELIMITER ') === 0) {
            continue;
        }
        if (substr($trimmed, -2) === '//') {
            $line = rtrim(substr($line, 0, strrpos($line, '//')));
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}
