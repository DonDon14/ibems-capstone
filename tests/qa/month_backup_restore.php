<?php

declare(strict_types=1);

// A local, read-only source rehearsal; never connects to the application's default database.
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$directory = realpath(dirname(__DIR__, 2) . '/writable/simulation');
$sourcePath = realpath($argv[1] ?? '');
if ($directory === false || $sourcePath === false || dirname($sourcePath) !== $directory
    || !preg_match('/^ibems-month-simulation-\d{8}\.sqlite$/D', basename($sourcePath))) {
    throw new RuntimeException('Select an existing isolated month simulation database.');
}
$restorePath = $directory . '/month-restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
if (file_exists($restorePath)) {
    throw new RuntimeException('The restore destination already exists.');
}
$source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
$restore = new SQLite3($restorePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$source->enableExceptions(true);
$restore->enableExceptions(true);

/** Compare schema and each complete row without relying on physical page/file ordering. */
function databaseEvidence(SQLite3 $db): array
{
    $schema = [];
    $tables = [];
    $result = $db->query("SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $schema[] = $row;
        if ($row['type'] === 'table') {
            $table = $row['name'];
            $rows = [];
            $data = $db->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"');
            while ($record = $data->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = hash('sha256', serialize($record));
            }
            sort($rows, SORT_STRING);
            $tables[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
        }
    }
    ksort($tables);
    return ['schema_sha256' => hash('sha256', serialize($schema)), 'tables' => $tables];
}

try {
    $source->exec('BEGIN');
    $before = databaseEvidence($source);
    if (!$source->backup($restore)) {
        throw new RuntimeException('SQLite backup failed.');
    }
    $after = databaseEvidence($restore);
    if ($before !== $after) {
        throw new RuntimeException('Restored schema or table contents differ from the source snapshot.');
    }
    $integrity = $restore->querySingle('PRAGMA integrity_check');
    $violations = $restore->query('PRAGMA foreign_key_check')->fetchArray(SQLITE3_ASSOC);
    if ($integrity !== 'ok' || $violations !== false) {
        throw new RuntimeException('Restored database failed integrity or foreign-key checks.');
    }
    $source->exec('COMMIT');
    $evidence = [
        'status' => 'passed', 'checked_at_utc' => gmdate('c'),
        'source' => basename($sourcePath), 'restored' => basename($restorePath),
        'integrity' => $integrity, 'foreign_key_violations' => 0,
        'table_count' => count($before['tables']),
        'contents' => $after,
    ];
    $report = $restorePath . '.json';
    if (file_put_contents($report, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot save restore evidence.');
    }
    echo json_encode(['status' => 'passed', 'table_count' => $evidence['table_count'],
        'restored' => basename($restorePath), 'report' => basename($report)], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    $source->close();
    $restore->close();
}
