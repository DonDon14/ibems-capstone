<?php

use App\Services\DeductionBatchService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class DeductionBatchServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
    }

    public function testPreparationSnapshotsDebtWithoutReducingBalance(): void
    {
        $this->seedEmployee(501, 'faculty', 600);
        $result = (new DeductionBatchService())->prepare(1, [
            ['user_id' => 501, 'requested_amount' => 250],
        ], 900, 'First-half payroll');

        $this->assertSame('success', $result['status']);
        $db = Database::connect();
        $this->assertSame(600.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $item = $db->table('deduction_batch_items')->get()->getRowArray();
        $this->assertSame(600.0, (float) $item['debt_snapshot']);
        $this->assertSame(250.0, (float) $item['requested_amount']);
        $this->assertSame('pending', $item['result_status']);
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'ACCOUNTING_PREPARE_DEDUCTION_BATCH')->countAllResults());
    }

    public function testPartialConfirmationReducesOnlyConfirmedAmountAndCarriesBalance(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 250]], 900);
        $service->submit((int) $prepared['batch']['id'], 900);
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;

        $result = $service->confirmResult($itemId, [
            'confirmed_amount' => 100,
            'reason_code' => 'insufficient_salary',
            'result_reference' => 'PAYROLL-2026-08-A-501',
        ], 900);

        $this->assertSame('success', $prepared['status']);
        $this->assertSame('success', $result['status']);
        $this->assertSame(600.0, (float) $result['debt_before']);
        $this->assertSame(500.0, (float) $result['debt_after']);
        $item = Database::connect()->table('deduction_batch_items')->where('id', $itemId)->get()->getRowArray();
        $this->assertSame('partially_deducted', $item['result_status']);
        $this->assertSame(100.0, (float) $item['confirmed_amount']);
        $this->assertSame(500.0, (float) $item['carryover_amount']);
        $this->assertSame(1, Database::connect()->table('debt_cashbook_entries')->countAllResults());
    }

    public function testFailedResultKeepsDebtAndRequiresReason(): void
    {
        $this->seedEmployee(501, 'faculty', 300);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 200]], 900);
        $service->submit((int) $prepared['batch']['id'], 900);
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;

        $invalid = $service->confirmResult($itemId, [
            'confirmed_amount' => 0,
            'result_reference' => 'PAYROLL-FAIL-501',
        ], 900);
        $this->assertSame('A valid reason code is required for a partial or failed deduction.', $invalid['message']);

        $result = $service->confirmResult($itemId, [
            'confirmed_amount' => 0,
            'reason_code' => 'employee_not_found',
            'result_reference' => 'PAYROLL-FAIL-501',
        ], 900);
        $this->assertSame('success', $result['status']);
        $this->assertSame(300.0, (float) $result['debt_after']);
        $this->assertSame(0, Database::connect()->table('debt_cashbook_entries')->countAllResults());
    }

    public function testRepeatedOfficialResultIsIdempotent(): void
    {
        $this->seedEmployee(501, 'staff', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 150]], 900);
        $service->submit((int) $prepared['batch']['id'], 900);
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;
        $payload = ['confirmed_amount' => 150, 'result_reference' => 'PAYROLL-IDEMPOTENT-501'];

        $first = $service->confirmResult($itemId, $payload, 900);
        $second = $service->confirmResult($itemId, $payload, 900);

        $this->assertSame('success', $first['status']);
        $this->assertTrue($second['idempotent'] ?? false);
        $this->assertSame(50.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $this->assertSame(1, Database::connect()->table('debt_cashbook_entries')->countAllResults());
    }

    public function testBatchMustBeSubmittedReconciledAndIndependentlyFinalized(): void
    {
        $this->seedEmployee(501, 'faculty', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);
        $batchId = (int) $prepared['batch']['id'];
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;

        $tooEarly = $service->confirmResult($itemId, [
            'confirmed_amount' => 100, 'result_reference' => 'PAYROLL-LIFECYCLE-501',
        ], 900);
        $this->assertSame(409, $tooEarly['code']);

        $submitted = $service->submit($batchId, 900);
        $confirmed = $service->confirmResult($itemId, [
            'confirmed_amount' => 100, 'result_reference' => 'PAYROLL-LIFECYCLE-501',
        ], 900);
        $reconciled = $service->reconcile($batchId, 900);
        $selfFinalize = $service->finalize($batchId, 900);
        $finalized = $service->finalize($batchId, 901);

        $this->assertSame('submitted', $submitted['batch']['status']);
        $this->assertSame('success', $confirmed['status']);
        $this->assertSame('reconciled', $reconciled['batch']['status']);
        $this->assertSame(403, $selfFinalize['code']);
        $this->assertSame('finalized', $finalized['batch']['status']);
        $period = Database::connect()->table('deduction_periods')->where('id', 1)->get()->getRowArray();
        $this->assertSame('finalized', $period['status']);
        $this->assertSame(901, (int) $period['finalized_by']);
    }

    private function seedEmployee(int $userId, string $type, float $debt): void
    {
        $db = Database::connect();
        $db->table('users')->insert(['id' => $userId, 'user_type' => $type, 'is_active' => 1]);
        $db->table('balances')->insert(['user_id' => $userId, 'credit_limit' => 1000, 'current_debt' => $debt]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $tn = static fn (string $name): string => $db->getPrefix() . $name;
        foreach (['debt_cashbook_entries', 'audit_logs', 'deduction_batch_items', 'deduction_batches', 'deduction_periods', 'balances', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('users') . ' (id INTEGER PRIMARY KEY, user_type TEXT, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('balances') . ' (user_id INTEGER PRIMARY KEY, credit_limit REAL, current_debt REAL, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_periods') . ' (id INTEGER PRIMARY KEY, status TEXT, reviewed_by INTEGER, submitted_by INTEGER, confirmed_by INTEGER, finalized_by INTEGER, reviewed_at TEXT, submitted_at TEXT, confirmed_at TEXT, finalized_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, period_id INTEGER UNIQUE, status TEXT, total_accounts INTEGER, total_requested REAL, total_confirmed REAL, total_carryover REAL, legacy_settlement_run_id INTEGER, notes TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_batch_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER, user_id INTEGER, debt_snapshot REAL, requested_amount REAL, confirmed_amount REAL, carryover_amount REAL, result_status TEXT, reason_code TEXT, result_reference TEXT, result_notes TEXT, confirmed_by INTEGER, confirmed_at TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, payload_json TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, debt_before REAL, debt_after REAL, credit_limit_snapshot REAL, available_credit_snapshot REAL, reference_type TEXT, reference_id INTEGER, actor_id INTEGER, remarks TEXT, meta_json TEXT, created_at TEXT, updated_at TEXT)');
        $db->table('deduction_periods')->insert(['id' => 1, 'status' => 'draft']);
    }
}
