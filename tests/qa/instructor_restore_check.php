<?php
declare(strict_types=1);

// Restore only the disposable populated workflow clone in the release acceptance container.
$root = realpath(dirname(__DIR__, 2));
if (PHP_SAPI !== 'cli' || $root !== '/var/www/html'
    || getenv('IBEMS_DATABASE_DRIVER') !== 'SQLite3' || getenv('IBEMS_INSTRUCTOR_DEMO') !== '1') {
    throw new RuntimeException('Requires the isolated instructor acceptance container.');
}
$paths = glob($root . '/writable/instructor-qa-????????????.sqlite');
if (count($paths) !== 1) { throw new RuntimeException('Expected exactly one populated workflow clone.'); }
$sourcePath = $paths[0];
$restoredPath = $root . '/writable/instructor-restored-qa.sqlite';
if (is_file($restoredPath)) { throw new RuntimeException('Preserve existing restore evidence.'); }
$sourceHash = hash_file('sha256', $sourcePath);
$source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
if ($source->querySingle('SELECT marker FROM instructor_demo_metadata') !== 'synthetic-instructor-demo-v1'
    || (int) $source->querySingle('SELECT COUNT(*) FROM transactions') !== 7
    || (int) $source->querySingle('SELECT COUNT(*) FROM daily_store_reports') !== 6
    || (int) $source->querySingle("SELECT COUNT(*) FROM deduction_batches WHERE status='finalized'") !== 1) {
    throw new RuntimeException('Expected the completed synthetic workflow database.');
}
$restore = new SQLite3($restoredPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
if (!$source->backup($restore) || $restore->querySingle('PRAGMA integrity_check') !== 'ok'
    || $restore->query('PRAGMA foreign_key_check')->fetchArray()) {
    throw new RuntimeException('Restore integrity or foreign key check failed.');
}
$rows = static function (SQLite3 $db, string $sql): array {
    $out = []; $result = $db->query($sql);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; }
    return $out;
};
$schemaSql = "SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name";
if ($rows($source, $schemaSql) !== $rows($restore, $schemaSql)) { throw new RuntimeException('Restored schema differs.'); }
$tables = $rows($source, "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
foreach ($tables as $table) {
    $name = '"' . str_replace('"', '""', $table['name']) . '"';
    $canonical = static function (array $data): array {
        $data = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $data);
        sort($data, SORT_STRING); return $data;
    };
    if ($canonical($rows($source, 'SELECT * FROM ' . $name)) !== $canonical($rows($restore, 'SELECT * FROM ' . $name))) {
        throw new RuntimeException('Restored table differs: ' . $table['name']);
    }
}
$source->close(); $restore->close();
if (hash_file('sha256', $sourcePath) !== $sourceHash) { throw new RuntimeException('Original populated evidence changed.'); }
echo json_encode(['status'=>'passed','tables'=>count($tables),'schema_and_rows_match'=>true,'source_preserved'=>true], JSON_PRETTY_PRINT) . PHP_EOL;
