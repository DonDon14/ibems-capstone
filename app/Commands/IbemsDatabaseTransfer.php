<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

class IbemsDatabaseTransfer extends BaseCommand
{
    private const STAGING_HOST = 'aws-0-ap-southeast-1.pooler.supabase.com';
    private const STAGING_USERNAME = 'postgres.pukjmscgjtmqvhdncjpo';

    protected $group       = 'IBEMS';
    protected $name        = 'ibems:database-transfer';
    protected $description = 'Export or import a canonical logical database transfer artifact.';
    protected $usage       = 'ibems:database-transfer --export <path> | --import <path>';
    protected $arguments   = [];
    protected $options     = [
        '--export' => 'Write a canonical JSON transfer artifact.',
        '--import' => 'Atomically replace PostgreSQL staging data from an artifact.',
    ];

    /** @var list<string> */
    private array $tables = [
        'users',
        'user_roles',
        'balances',
        'stores',
        'store_supervisors',
        'store_categories',
        'store_payment_methods',
        'products',
        'store_day_sessions',
        'store_day_variance_cases',
        'store_day_variance_case_events',
        'store_day_variance_case_attachments',
        'store_day_variance_case_handoffs',
        'store_opening_balances',
        'store_cash_movements',
        'transactions',
        'transaction_items',
        'inventory_movements',
        'notifications',
        'salary_import_batches',
        'salary_import_rows',
        'settlement_runs',
        'deduction_periods',
        'deduction_batches',
        'deduction_batch_items',
        'debt_pin_security',
        'debt_cashbook_entries',
        'debt_investigations',
        'audit_logs',
    ];

    /** @var array<string, list<string>> */
    private array $booleanColumns = [
        'users' => ['is_active'],
        'stores' => ['is_active'],
        'store_categories' => ['is_active'],
        'store_payment_methods' => ['is_active', 'is_system_reserved'],
        'products' => ['is_active'],
    ];

    public function run(array $params): void
    {
        $exportPath = trim((string) CLI::getOption('export'));
        $importPath = trim((string) CLI::getOption('import'));

        if (($exportPath === '') === ($importPath === '')) {
            throw new RuntimeException('Specify exactly one of --export or --import.');
        }

        if ($exportPath !== '') {
            $this->export($exportPath);
            return;
        }

        $this->import($importPath);
    }

    private function export(string $path): void
    {
        $db = Database::connect();
        $db->initialize();
        $tablePayloads = [];

        foreach ($this->tables as $table) {
            if (! $db->tableExists($table)) {
                throw new RuntimeException("Missing transfer table: {$table}");
            }

            $columns = $this->tableColumns($db, $table);
            $primary = in_array('id', $columns, true) ? 'id' : (in_array('user_id', $columns, true) ? 'user_id' : $columns[0]);
            $rows = $db->table($table)
                ->select(implode(', ', $columns))
                ->orderBy($primary, 'ASC')
                ->get()
                ->getResultArray();
            $normalized = array_map(fn (array $row): array => $this->normalizeRow($table, $row), $rows);
            $encodedRows = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tablePayloads[$table] = [
                'count' => count($normalized),
                'sha256' => hash('sha256', $encodedRows === false ? '[]' : $encodedRows),
                'rows' => $normalized,
            ];
        }

        $artifact = [
            'format_version' => 1,
            'source_driver' => (string) $db->DBDriver,
            'source_database' => (string) $db->database,
            'created_at_utc' => gmdate('c'),
            'tables' => $tablePayloads,
        ];
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create transfer directory: {$directory}");
        }
        $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
            throw new RuntimeException("Unable to write transfer artifact: {$path}");
        }

        CLI::write(sprintf('Database transfer exported: %d tables from %s / %s', count($this->tables), $db->DBDriver, $db->database), 'green');
    }

    private function import(string $path): void
    {
        $db = Database::connect();
        $db->initialize();
        $this->assertStagingImportTarget($db);
        if (! is_file($path)) {
            throw new RuntimeException("Transfer artifact not found: {$path}");
        }

        $artifact = json_decode((string) file_get_contents($path), true);
        if (! is_array($artifact) || (int) ($artifact['format_version'] ?? 0) !== 1 || ! is_array($artifact['tables'] ?? null)) {
            throw new RuntimeException('Invalid database transfer artifact.');
        }
        foreach ($this->tables as $table) {
            if (! isset($artifact['tables'][$table]['rows']) || ! is_array($artifact['tables'][$table]['rows'])) {
                throw new RuntimeException("Transfer artifact is missing table: {$table}");
            }
        }

        $db->transBegin();
        try {
            $quotedTables = implode(', ', array_map(static fn (string $table): string => 'public."' . $table . '"', $this->tables));
            $db->query("TRUNCATE TABLE {$quotedTables} RESTART IDENTITY CASCADE");

            foreach ($this->tables as $table) {
                $rows = $artifact['tables'][$table]['rows'];
                foreach (array_chunk($rows, 200) as $batch) {
                    if ($batch !== [] && $db->table($table)->insertBatch($batch) === false) {
                        throw new RuntimeException("Failed to import table: {$table}");
                    }
                }
                if (in_array('id', $this->tableColumns($db, $table), true)) {
                    $db->query(
                        "SELECT setval(pg_get_serial_sequence('public.{$table}', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM public.\"{$table}\""
                    );
                }
            }

            if (! $db->transStatus()) {
                throw new RuntimeException('PostgreSQL reported a failed transfer transaction.');
            }
            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }

        CLI::write(sprintf('Database transfer imported: %d tables into PostgreSQL staging', count($this->tables)), 'green');
    }

    private function assertStagingImportTarget(object $db): void
    {
        $isExactStagingTarget = (string) ($db->DBDriver ?? '') === 'Postgre'
            && (string) ($db->database ?? '') === 'postgres'
            && strtolower((string) ($db->hostname ?? '')) === self::STAGING_HOST
            && (string) ($db->username ?? '') === self::STAGING_USERNAME;

        if (! $isExactStagingTarget) {
            throw new RuntimeException('Database transfer imports are restricted to the verified Supabase staging project.');
        }
        if (getenv('IBEMS_ALLOW_STAGING_RESET') !== '1') {
            throw new RuntimeException('Set IBEMS_ALLOW_STAGING_RESET=1 for an explicit staging import.');
        }
    }

    private function normalizeRow(string $table, array $row): array
    {
        ksort($row, SORT_STRING);
        foreach ($this->booleanColumns[$table] ?? [] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = ibems_bool($row[$column]);
            }
        }

        return $row;
    }

    /** @return list<string> */
    private function tableColumns(object $db, string $table): array
    {
        if ((string) $db->DBDriver === 'Postgre') {
            $rows = $db->query(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
                ['public', $table]
            )->getResultArray();
        } else {
            $rows = $db->query(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
                [(string) $db->database, $table]
            )->getResultArray();
        }

        $columns = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['column_name'] ?? ''),
            $rows
        )));
        sort($columns, SORT_STRING);
        if ($columns === []) {
            throw new RuntimeException("Unable to resolve public columns for table: {$table}");
        }

        return $columns;
    }
}
