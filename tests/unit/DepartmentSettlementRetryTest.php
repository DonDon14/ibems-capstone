<?php

use App\Services\DepartmentDebtService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class DepartmentSettlementRetryTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $db->resetTransStatus();
        foreach (['department_settlement_receipts', 'department_debt_entries', 'department_debt_periods', 'audit_logs'] as $table) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        $db->query('CREATE TABLE ' . $db->prefixTable('department_debt_periods') . ' (id INTEGER PRIMARY KEY, department_id INTEGER, allocation_amount REAL, used_amount REAL, outstanding_amount REAL, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $db->prefixTable('department_debt_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER, period_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, allocation_before REAL, allocation_after REAL, used_before REAL, used_after REAL, outstanding_before REAL, outstanding_after REAL, actor_id INTEGER, reference_no TEXT, remarks TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $db->prefixTable('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, payload_json TEXT, created_at TEXT)');
        require_once APPPATH . 'Database/Migrations/2026-10-02-000001_AddDepartmentSettlementReceipts.php';
        (new \App\Database\Migrations\AddDepartmentSettlementReceipts(Database::forge($db)))->up();
        $db->table('department_debt_periods')->insert(['id' => 10, 'department_id' => 1, 'allocation_amount' => 500, 'used_amount' => 200, 'outstanding_amount' => 200]);
    }

    public function testLostResponseRetryReplaysOriginalReceiptAtZeroDebt(): void
    {
        $first = $this->settle('settlement-intent-0001', 200);
        $this->assertSame('success', $first['status']);
        $before = $this->snapshot();
        $this->assertSame($first, $this->settle('settlement-intent-0001', 200));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0.0, (float) Database::connect()->table('department_debt_periods')->get()->getRow()->outstanding_amount);
    }

    public function testDistinctEqualPaymentsAreAllowedAndKeyReuseWithChangedDetailsFails(): void
    {
        $first = $this->settle('settlement-intent-0001', 50);
        $second = $this->settle('settlement-intent-0002', 50);
        $this->assertSame('success', $second['status']);
        $this->assertNotSame($first['settlement_entry_id'], $second['settlement_entry_id']);
        $before = $this->snapshot();
        $this->assertSame(409, $this->settle('settlement-intent-0001', 60)['code']);
        $this->assertSame(409, (new DepartmentDebtService())->recordSettlement(1, 10, 50, 'DIFFERENT', 'Synthetic payment', 900, 'settlement-intent-0001')['code']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, Database::connect()->table('department_debt_entries')->countAllResults());
    }

    public function testMissingMalformedKeysAndMissingMigrationFailBeforeWrites(): void
    {
        $before = $this->snapshot();
        foreach ([null, '', [], 'short', str_repeat('x', 101)] as $key) $this->assertSame(400, $this->settle($key)['code']);
        $this->assertSame($before, $this->snapshot());
        Database::connect()->query('DROP TABLE ' . Database::connect()->prefixTable('department_settlement_receipts'));
        $this->assertSame(503, $this->settle('settlement-intent-0001')['code']);
        $this->assertSame(200.0, (float) Database::connect()->table('department_debt_periods')->get()->getRow()->outstanding_amount);
    }

    public function testEveryPostingFailureRollsBackAndTheSameKeyCanRetry(): void
    {
        $db = Database::connect();
        foreach (['department_debt_entries', 'audit_logs', 'department_settlement_receipts'] as $table) {
            $before = $this->snapshot();
            $db->query('CREATE TRIGGER fail_settlement BEFORE INSERT ON ' . $db->prefixTable($table) . " BEGIN SELECT RAISE(ABORT, 'synthetic posting failure'); END");
            $failed = $this->settle('settlement-failure-' . $table, 25);
            $this->assertSame(409, $failed['code']);
            $this->assertFalse($failed['retry_safe']);
            $this->assertSame($before, $this->snapshot());
            $db->query('DROP TRIGGER fail_settlement');
            $db->resetTransStatus();
            $this->assertSame('success', $this->settle('settlement-failure-' . $table, 25)['status']);
        }
    }

    public function testOverpaymentAndWrongPeriodDoNotWrite(): void
    {
        $before = $this->snapshot();
        $rejected = $this->settle('settlement-intent-0001', 201);
        $this->assertSame(409, $rejected['code']);
        $this->assertTrue($rejected['retry_safe']);
        $this->assertSame(409, (new DepartmentDebtService())->recordSettlement(2, 10, 20, 'SYNTHETIC-REF', 'Synthetic payment', 900, 'settlement-intent-0002')['code']);
        $this->assertSame($before, $this->snapshot());
    }

    private function settle(mixed $key, float $amount = 50): array
    {
        return (new DepartmentDebtService())->recordSettlement(1, 10, $amount, 'SYNTHETIC-REF', 'Synthetic payment', 900, $key);
    }

    private function snapshot(): array
    {
        $db = Database::connect();
        return array_map(static fn ($table) => $db->table($table)->get()->getResultArray(), ['department_debt_periods', 'department_debt_entries', 'audit_logs', 'department_settlement_receipts']);
    }
}
