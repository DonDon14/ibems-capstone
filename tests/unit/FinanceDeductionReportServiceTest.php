<?php

use App\Services\FinanceDeductionReportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class FinanceDeductionReportServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $tn = static fn (string $table): string => $db->getPrefix() . $table;
        foreach (['debt_cashbook_entries', 'deduction_batch_items', 'deduction_batches', 'deduction_periods', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('users') . ' (id INTEGER PRIMARY KEY, name TEXT, employee_id TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_periods') . ' (id INTEGER PRIMARY KEY, period_code TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_batches') . ' (id INTEGER PRIMARY KEY, period_id INTEGER)');
        $db->query('CREATE TABLE ' . $tn('deduction_batch_items') . ' (id INTEGER PRIMARY KEY, batch_id INTEGER, user_id INTEGER, confirmed_amount REAL, confirmed_at TEXT, result_reference TEXT)');
        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (id INTEGER PRIMARY KEY, user_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, created_at TEXT, reference_type TEXT, reference_id INTEGER)');
        $db->table('users')->insert(['id' => 1, 'name' => 'Employee One', 'employee_id' => 'E-1']);
        $db->table('deduction_periods')->insert(['id' => 1, 'period_code' => '2026-09-A']);
        $db->table('deduction_batches')->insert(['id' => 1, 'period_id' => 1]);
        $db->table('deduction_batch_items')->insertBatch([
            ['id' => 1, 'batch_id' => 1, 'user_id' => 1, 'confirmed_amount' => 40, 'confirmed_at' => '2026-09-23 01:00:00', 'result_reference' => 'PAY-1'],
            ['id' => 2, 'batch_id' => 1, 'user_id' => 1, 'confirmed_amount' => 0, 'confirmed_at' => '2026-09-23 01:30:00', 'result_reference' => 'PAY-2'],
        ]);
        $db->table('debt_cashbook_entries')->insertBatch([
            ['id' => 1, 'user_id' => 1, 'entry_type' => 'confirmed_salary_deduction', 'direction' => 'credit', 'amount' => 40, 'created_at' => '2026-09-23 01:00:00', 'reference_type' => 'deduction_batch_item', 'reference_id' => 1],
            ['id' => 2, 'user_id' => 1, 'entry_type' => 'salary_deduction', 'direction' => 'credit', 'amount' => 20, 'created_at' => '2026-09-23 02:00:00', 'reference_type' => 'legacy', 'reference_id' => 2],
            ['id' => 3, 'user_id' => 1, 'entry_type' => 'manual_deduction', 'direction' => 'debit', 'amount' => 9, 'created_at' => '2026-09-23 02:30:00', 'reference_type' => null, 'reference_id' => null],
            ['id' => 4, 'user_id' => 1, 'entry_type' => 'full_deduction', 'direction' => 'credit', 'amount' => 10, 'created_at' => '2026-09-22 02:00:00', 'reference_type' => null, 'reference_id' => null],
        ]);
    }

    public function testConfirmedAndLegacyDeductionsReconcileWithoutMirror(): void
    {
        $report = (new FinanceDeductionReportService())->build('2026-09-23', '2026-09-23');
        $this->assertSame(2, $report['count']);
        $this->assertSame(6000, $report['total_cents']);
        $this->assertSame(['Legacy deduction', 'Confirmed payroll batch'], array_column($report['rows'], 'source'));
        $this->assertSame('PAY-1', $report['rows'][1]['reference']);
    }

    public function testLinkedLegacyCashbookMirrorsDoNotDoubleCountAcrossDates(): void
    {
        $db = Database::connect();
        $db->table('debt_cashbook_entries')->insertBatch([
            ['id' => 5, 'user_id' => 1, 'entry_type' => 'salary_deduction', 'direction' => 'credit', 'amount' => 40, 'created_at' => '2026-09-23 01:05:00', 'reference_type' => 'deduction_batch', 'reference_id' => 1],
            ['id' => 6, 'user_id' => 1, 'entry_type' => 'salary_deduction', 'direction' => 'credit', 'amount' => 40, 'created_at' => '2026-09-24 01:00:00', 'reference_type' => 'deduction_batch_item', 'reference_id' => 1],
            ['id' => 7, 'user_id' => 1, 'entry_type' => 'salary_deduction', 'direction' => 'credit', 'amount' => 5, 'created_at' => '2026-09-24 02:00:00', 'reference_type' => 'deduction_batch', 'reference_id' => 1],
        ]);

        $service = new FinanceDeductionReportService();
        $firstDay = $service->build('2026-09-23', '2026-09-23');
        $this->assertSame(2, $firstDay['count']);
        $this->assertSame(6000, $firstDay['total_cents']);

        $nextDay = $service->build('2026-09-24', '2026-09-24');
        $this->assertSame(1, $nextDay['count']);
        $this->assertSame(500, $nextDay['total_cents']);
        $this->assertSame('Legacy deduction', $nextDay['rows'][0]['source']);
    }

    public function testDateAndInvalidRange(): void
    {
        $report = (new FinanceDeductionReportService())->build('2026-09-22', '2026-09-22');
        $this->assertSame(1000, $report['total_cents']);
        $this->expectException(InvalidArgumentException::class);
        (new FinanceDeductionReportService())->build('2026-09-24', '2026-09-23');
    }
}
