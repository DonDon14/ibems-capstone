<?php

use App\Services\DepartmentDebtService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class DepartmentBatchAllocationServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
    }

    public function testBatchAppliesTheSameAllocationAndCreatesAnAuditPerDepartment(): void
    {
        $this->seedDepartment(10, 'ACC', 'Accounting');
        $this->seedDepartment(20, 'HR', 'Human Resources');

        $result = (new DepartmentDebtService())->setAllocations([10, 20, 20], '2026-08', 7500, 'open', 'Approved August batch', 900);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['updated_count']);
        $periods = Database::connect()->table('department_debt_periods')->orderBy('department_id')->get()->getResultArray();
        $this->assertSame([10, 20], array_map('intval', array_column($periods, 'department_id')));
        $this->assertSame([7500.0, 7500.0], array_map('floatval', array_column($periods, 'allocation_amount')));
        $this->assertSame(2, Database::connect()->table('department_debt_entries')->countAllResults());
        $this->assertSame(2, Database::connect()->table('audit_logs')->where('action', 'ACCOUNTING_SET_DEPARTMENT_ALLOCATION')->countAllResults());
        $this->assertStringStartsWith('DEPT-ALLOC-', $result['batch_reference']);
        $ledgerMeta = array_map(static fn (array $row): array => json_decode((string) $row['meta_json'], true), Database::connect()->table('department_debt_entries')->orderBy('id')->get()->getResultArray());
        $this->assertSame([$result['batch_reference'], $result['batch_reference']], array_column($ledgerMeta, 'batch_reference'));
    }

    public function testUnsafeDepartmentRollsBackTheEntireBatch(): void
    {
        $this->seedDepartment(10, 'ACC', 'Accounting');
        $this->seedDepartment(20, 'HR', 'Human Resources');
        Database::connect()->table('department_debt_periods')->insert([
            'department_id' => 20,
            'period_month' => '2026-08-01',
            'allocation_amount' => 100,
            'used_amount' => 80,
            'outstanding_amount' => 80,
            'status' => 'open',
        ]);

        $result = (new DepartmentDebtService())->setAllocations([10, 20], '2026-08', 50, 'open', 'Unsafe reduction trial', 900);

        $this->assertSame('error', $result['status']);
        $this->assertSame(409, $result['code']);
        $this->assertStringContainsString('HR', $result['message']);
        $this->assertSame(0, Database::connect()->table('department_debt_periods')->where('department_id', 10)->countAllResults());
        $this->assertSame(0, Database::connect()->table('department_debt_entries')->countAllResults());
        $this->assertSame(0, Database::connect()->table('audit_logs')->countAllResults());
    }

    public function testBatchRequiresDepartmentsValidMonthAndAuditReason(): void
    {
        $service = new DepartmentDebtService();

        $this->assertSame(400, $service->setAllocations([], '2026-08', 100, 'open', 'reason', 1)['code']);
        $this->assertSame(400, $service->setAllocations([1], "2026-08' OR 1=1 --", 100, 'open', 'reason', 1)['code']);
        $this->assertSame('Every selected department must have a valid numeric ID.', $service->setAllocations(['1 OR 1=1'], '2026-08', 100, 'open', 'reason', 1)['message']);
        $this->assertSame('An audit reason is required for a batch allocation.', $service->setAllocations([1], '2026-08', 100, 'open', '', 1)['message']);
    }

    private function seedDepartment(int $id, string $code, string $name): void
    {
        Database::connect()->table('departments')->insert(['id' => $id, 'code' => $code, 'name' => $name, 'is_active' => 1]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $tn = static fn (string $name): string => $db->getPrefix() . $name;
        foreach (['audit_logs', 'department_debt_entries', 'department_debt_periods', 'departments'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('departments') . ' (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('department_debt_periods') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER, period_month TEXT, allocation_amount REAL, used_amount REAL, outstanding_amount REAL, status TEXT, notes TEXT, configured_by INTEGER, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('department_debt_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER, period_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, allocation_before REAL, allocation_after REAL, used_before REAL, used_after REAL, outstanding_before REAL, outstanding_after REAL, actor_id INTEGER, remarks TEXT, meta_json TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, payload_json TEXT, created_at TEXT)');
    }
}
