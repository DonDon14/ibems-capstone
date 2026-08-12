<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class IbemsFinancialSnapshot extends BaseCommand
{
    protected $group       = 'IBEMS';
    protected $name        = 'ibems:financial-snapshot';
    protected $description = 'Export a read-only, normalized financial reconciliation snapshot.';
    protected $usage       = 'ibems:financial-snapshot --output <path>';
    protected $arguments   = [];
    protected $options     = [
        '--output' => 'Required JSON output path.',
    ];

    /** @var array<string, array<string, string>> */
    private array $schemas = [
        'balances' => [
            'user_id' => 'int', 'credit_limit' => 'money', 'current_debt' => 'money',
        ],
        'products' => [
            'id' => 'int', 'store_id' => 'int', 'price' => 'money', 'stock_qty' => 'int', 'is_active' => 'bool',
        ],
        'transactions' => [
            'id' => 'int', 'user_id' => 'nullable_int', 'store_id' => 'int', 'amount' => 'money',
            'payment_method' => 'string', 'status' => 'string', 'created_at' => 'string',
        ],
        'transaction_items' => [
            'id' => 'int', 'transaction_id' => 'int', 'product_id' => 'int', 'qty' => 'int',
            'unit_price' => 'money', 'line_total' => 'money',
        ],
        'inventory_movements' => [
            'id' => 'int', 'product_id' => 'int', 'store_id' => 'int', 'type' => 'string',
            'qty' => 'int', 'txn_id' => 'nullable_int', 'created_at' => 'string',
        ],
        'store_day_sessions' => [
            'id' => 'int', 'store_id' => 'int', 'business_date' => 'string', 'status' => 'string',
            'opening_cash' => 'money', 'opening_ecash' => 'money', 'expected_cash' => 'nullable_money',
            'expected_ecash' => 'nullable_money', 'counted_cash' => 'nullable_money',
            'counted_ecash' => 'nullable_money', 'variance_cash' => 'nullable_money',
            'variance_ecash' => 'nullable_money', 'accountability_amount' => 'nullable_money',
        ],
        'store_day_variance_cases' => [
            'id' => 'int', 'case_ref' => 'string', 'store_day_session_id' => 'int', 'store_id' => 'int',
            'status' => 'string', 'owner_user_id' => 'nullable_int', 'resolved_by' => 'nullable_int',
            'resolved_at' => 'nullable_string', 'disposition' => 'nullable_string',
        ],
        'store_day_variance_case_events' => [
            'id' => 'int', 'case_id' => 'int', 'actor_id' => 'nullable_int', 'event_type' => 'string',
            'from_status' => 'nullable_string', 'to_status' => 'nullable_string', 'note' => 'nullable_string',
            'evidence_json' => 'nullable_string', 'created_at' => 'string',
        ],
        'store_cash_movements' => [
            'id' => 'int', 'store_id' => 'int', 'business_date' => 'string', 'channel' => 'string',
            'movement_type' => 'string', 'amount' => 'money', 'created_at' => 'string',
        ],
        'debt_cashbook_entries' => [
            'id' => 'int', 'user_id' => 'int', 'entry_type' => 'string', 'direction' => 'string',
            'amount' => 'money', 'debt_before' => 'money', 'debt_after' => 'money',
            'credit_limit_snapshot' => 'money', 'available_credit_snapshot' => 'money',
            'reference_type' => 'nullable_string', 'reference_id' => 'nullable_int', 'created_at' => 'string',
        ],
        'settlement_runs' => [
            'id' => 'int', 'run_month' => 'string', 'total_accounts' => 'int',
            'total_debt_before' => 'money', 'run_at' => 'string',
        ],
        'deduction_periods' => [
            'id' => 'int', 'period_code' => 'string', 'status' => 'string', 'date_start' => 'string',
            'date_end' => 'string',
        ],
        'deduction_batches' => [
            'id' => 'int', 'period_id' => 'int', 'status' => 'string', 'total_requested' => 'money',
            'total_confirmed' => 'money', 'total_carryover' => 'money',
        ],
        'deduction_batch_items' => [
            'id' => 'int', 'batch_id' => 'int', 'user_id' => 'int', 'debt_snapshot' => 'money',
            'requested_amount' => 'money', 'confirmed_amount' => 'money', 'carryover_amount' => 'money',
            'result_status' => 'string',
        ],
        'audit_logs' => [
            'id' => 'int', 'actor_id' => 'nullable_int', 'action' => 'string', 'entity' => 'string',
            'entity_id' => 'nullable_int', 'created_at' => 'string',
        ],
    ];

    public function run(array $params): void
    {
        $output = trim((string) CLI::getOption('output'));
        if ($output === '') {
            CLI::error('The --output option is required.');
            exit(1);
        }

        $db = Database::connect();
        $db->initialize();
        $tables = [];

        foreach ($this->schemas as $table => $schema) {
            if (!$db->tableExists($table)) {
                throw new \RuntimeException("Missing reconciliation table: {$table}");
            }

            $columns = array_keys($schema);
            $primary = $columns[0];
            $rows = $db->table($table)
                ->select(implode(', ', $columns))
                ->orderBy($primary, 'ASC')
                ->get()
                ->getResultArray();
            $normalized = array_map(fn (array $row): array => $this->normalizeRow($row, $schema), $rows);
            $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $tables[$table] = [
                'count' => count($normalized),
                'sha256' => hash('sha256', $json === false ? '[]' : $json),
                'rows' => $normalized,
            ];
        }

        $snapshot = [
            'format_version' => 1,
            'driver' => (string) $db->DBDriver,
            'database' => (string) $db->database,
            'generated_at_utc' => gmdate('c'),
            'tables' => $tables,
        ];

        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create snapshot directory: {$directory}");
        }

        $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents($output, $encoded . PHP_EOL) === false) {
            throw new \RuntimeException("Unable to write snapshot: {$output}");
        }

        CLI::write('Financial snapshot written: ' . $output, 'green');
    }

    /** @param array<string, string> $schema */
    private function normalizeRow(array $row, array $schema): array
    {
        $normalized = [];
        foreach ($schema as $column => $type) {
            $value = $row[$column] ?? null;
            $normalized[$column] = match ($type) {
                'int' => (int) $value,
                'nullable_int' => $value === null ? null : (int) $value,
                'bool' => ibems_bool($value),
                'money' => number_format((float) $value, 2, '.', ''),
                'nullable_money' => $value === null ? null : number_format((float) $value, 2, '.', ''),
                'nullable_string' => $value === null ? null : (string) $value,
                default => (string) $value,
            };
        }

        return $normalized;
    }
}
