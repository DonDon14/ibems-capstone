<?php

use App\Services\DeductionBatchService;
use App\Services\DeductionCarryoverTraceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class DeductionBatchServiceTest extends CIUnitTestCase
{
    use FeatureTestTrait;

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

    public function testOlderPeriodsRemainNavigableAfterTheFirstHundred(): void
    {
        $db = Database::connect();
        $extraPeriods = [];
        for ($id = 2; $id <= 101; $id++) {
            $extraPeriods[] = [
                'id' => $id,
                'period_code' => 'TEST-' . $id,
                'label' => 'Test period ' . $id,
                'date_start' => '2026-09-01',
                'date_end' => '2026-09-15',
                'status' => 'finalized',
            ];
        }
        $db->table('deduction_periods')->insertBatch($extraPeriods);
        $this->withRoutes([['GET', 'test/deduction-period-history', 'DeductionWorkspaceController::index']]);
        $session = ['logged_in' => true, 'user_id' => 900, 'role' => 'PAYROLL_CLERK', 'available_roles' => ['PAYROLL_CLERK']];

        $first = $this->withSession($session)->get('test/deduction-period-history?page=1');
        $first->assertOK();
        $this->assertStringContainsString('Page 1 of 3', (string) $first->getBody());
        $this->assertStringContainsString('Older periods', (string) $first->getBody());

        $last = $this->withSession($session)->get('test/deduction-period-history?page=3');
        $last->assertOK();
        $this->assertStringContainsString('Page 3 of 3', (string) $last->getBody());
        $this->assertStringContainsString('2026-08-A', (string) $last->getBody());

        $direct = $this->withSession($session)->get('test/deduction-period-history?period_id=1');
        $direct->assertOK();
        $this->assertStringContainsString('selected historical period', (string) $direct->getBody());
        $this->assertStringContainsString('August A', (string) $direct->getBody());
    }

    public function testPreparationRejectsMalformedOrFractionalCentAmountsWithoutCreatingBatch(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $service = new DeductionBatchService();
        foreach (['10.005', 'invalid', '1e2', -1, INF] as $amount) {
            $result = $service->prepare(1, [['user_id' => 501, 'requested_amount' => $amount]], 900);
            $this->assertSame('error', $result['status']);
        }
        $db = Database::connect();
        $this->assertSame(0, $db->table('deduction_batches')->countAllResults());
        $this->assertSame(600.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
    }

    public function testPreparationStopsWhenDebtHistoryIsUnavailable(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $db = Database::connect();
        $db->query('DROP TABLE ' . $db->getPrefix() . 'debt_cashbook_entries');

        $result = (new DeductionBatchService())->prepare(1, [
            ['user_id' => 501, 'requested_amount' => 250],
        ], 900);

        $this->assertSame('error', $result['status']);
        $this->assertSame(503, $result['code']);
        $this->assertSame(0, $db->table('deduction_batches')->countAllResults());
        $this->assertSame(600.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
    }

    public function testSharedWorkspaceShowsPeriodBreakdownAndFrozenPreparationSnapshot(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $db = Database::connect();
        $db->table('users')->where('id', 501)->update(['name' => 'Employee', 'employee_id' => 'EMP-501', 'base_salary' => 5000]);
        $db->table('debt_cashbook_entries')->insert([
            'user_id' => 501, 'direction' => 'debit', 'amount' => 600, 'created_at' => '2026-08-05 10:00:00',
        ]);
        $this->withRoutes([['GET', 'test/deduction-workspace', 'DeductionWorkspaceController::index']]);
        $session = ['logged_in' => true, 'user_id' => 900, 'role' => 'PAYROLL_CLERK', 'available_roles' => ['PAYROLL_CLERK']];

        $preview = $this->withSession($session)->get('test/deduction-workspace?period_id=1');
        $preview->assertOK();
        $this->assertStringContainsString('Current period register', (string) $preview->getBody());
        $this->assertStringContainsString('Period charges', (string) $preview->getBody());
        $this->assertStringContainsString('Current salary reference', (string) $preview->getBody());

        $prepared = (new DeductionBatchService())->prepare(1, [
            ['user_id' => 501, 'requested_amount' => 250],
        ], 900);
        $this->assertSame('success', $prepared['status']);
        $db->table('users')->where('id', 501)->update(['base_salary' => 6500]);
        $db->table('balances')->where('user_id', 501)->update(['current_debt' => 700]);
        $db->table('debt_cashbook_entries')->insert([
            'user_id' => 501, 'direction' => 'debit', 'amount' => 100, 'created_at' => '2026-08-20 10:00:00',
        ]);

        $snapshot = $this->withSession($session)->get('test/deduction-workspace?period_id=1');
        $snapshot->assertOK();
        $this->assertStringContainsString('Preparation snapshot', (string) $snapshot->getBody());
        $this->assertStringContainsString('Salary reference at preparation', (string) $snapshot->getBody());
        $this->assertMatchesRegularExpression('/Salary reference at preparation.*?&#8369;5,000.00/s', (string) $snapshot->getBody());
        $this->assertStringContainsString('&#8369;6,500.00', (string) $snapshot->getBody());
    }

    public function testSharedWorkspaceWarnsAndDisablesPreparationWithoutDebtHistory(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $db = Database::connect();
        $db->query('DROP TABLE ' . $db->getPrefix() . 'debt_cashbook_entries');
        $this->withRoutes([['GET', 'test/deduction-workspace', 'DeductionWorkspaceController::index']]);

        $page = $this->withSession([
            'logged_in' => true, 'user_id' => 900, 'role' => 'PAYROLL_CLERK', 'available_roles' => ['PAYROLL_CLERK'],
        ])->get('test/deduction-workspace?period_id=1');

        $page->assertOK();
        $this->assertStringContainsString('batch preparation is disabled', (string) $page->getBody());
        $this->assertStringNotContainsString('Prepare selected batch', (string) $page->getBody());
    }

    public function testLegacyBatchDoesNotPresentMissingBreakdownAsZeroActivity(): void
    {
        $markup = view('components/deduction_period_breakdown', [
            'prepared' => true,
            'account' => [
                'opening_debt_snapshot' => 0,
                'period_debits_snapshot' => 0,
                'period_credits_snapshot' => 0,
                'debt_snapshot' => 110,
                'salary_snapshot' => 0,
            ],
        ]);

        $this->assertStringContainsString('Historical breakdown unavailable', $markup);
        $this->assertStringContainsString('110.00', $markup);
        $this->assertStringNotContainsString('<dt>Period charges</dt>', $markup);
    }

    public function testPeriodRegisterSupportsFullPartialAndNoDeductionChoices(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $this->seedEmployee(502, 'staff', 300);
        $this->seedEmployee(503, 'faculty', 200);

        $result = (new DeductionBatchService())->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'full', 'requested_amount' => 600],
            ['user_id' => 502, 'deduction_choice' => 'partial', 'requested_amount' => 125],
            ['user_id' => 503, 'deduction_choice' => 'none', 'requested_amount' => 0, 'preparation_reason' => 'No available net salary'],
        ], 900);

        $this->assertSame('success', $result['status']);
        $items = Database::connect()->table('deduction_batch_items')->orderBy('user_id')->get()->getResultArray();
        $this->assertSame(['full', 'partial', 'none'], array_column($items, 'deduction_choice'));
        $this->assertSame([600.0, 125.0, 0.0], array_map('floatval', array_column($items, 'requested_amount')));
        $this->assertSame('No available net salary', $items[2]['preparation_reason']);
        $this->assertSame(600.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
    }

    public function testNoDeductionRequiresReason(): void
    {
        $this->seedEmployee(501, 'staff', 100);
        $result = (new DeductionBatchService())->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'none', 'requested_amount' => 0],
        ], 900);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no deduction requires a reason', $result['message']);
    }

    public function testPreparedBatchCanBeEditedBeforeItIsApplied(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $this->seedEmployee(502, 'faculty', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'full', 'requested_amount' => 600],
            ['user_id' => 502, 'deduction_choice' => 'full', 'requested_amount' => 200],
        ], 900);
        $batchId = (int) $prepared['batch']['id'];

        $edited = $service->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'partial', 'requested_amount' => 150],
        ], 900);

        $this->assertSame('success', $edited['status']);
        $this->assertSame($batchId, (int) $edited['batch']['id']);
        $this->assertSame(1, Database::connect()->table('deduction_batch_items')->countAllResults());
        $item = Database::connect()->table('deduction_batch_items')->get()->getRowArray();
        $this->assertSame('partial', $item['deduction_choice']);
        $this->assertSame(150.0, (float) $item['requested_amount']);
        $this->assertSame(1, Database::connect()->table('audit_logs')->where('action', 'ACCOUNTING_EDIT_DEDUCTION_BATCH')->countAllResults());
        $changes = Database::connect()->table('audit_logs')->where('action', 'ACCOUNTING_EDIT_DEDUCTION_ITEM')->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $changes);
        $changed = json_decode((string) $changes[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $removed = json_decode((string) $changes[1]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(501, $changed['user_id']);
        $this->assertSame(600, $changed['before']['requested_amount']);
        $this->assertSame(150, $changed['after']['requested_amount']);
        $this->assertSame(502, $removed['user_id']);
        $this->assertSame(200, $removed['before']['requested_amount']);
        $this->assertNull($removed['after']);
    }

    public function testPreparationRejectsAStaleNonDraftPeriodWithoutChangingItsBatch(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 150]], 900);
        $this->assertSame('success', $prepared['status']);
        $db = Database::connect();
        $db->table('deduction_periods')->where('id', 1)->update(['status' => 'submitted']);

        $result = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);

        $this->assertSame(409, $result['code']);
        $this->assertSame(150.0, (float) $db->table('deduction_batch_items')->get()->getRow()->requested_amount);
        $this->assertSame(0, $db->table('audit_logs')->where('action', 'ACCOUNTING_EDIT_DEDUCTION_BATCH')->countAllResults());
    }

    public function testSharedWorkspaceLetsCreatorEditPreparedBatchWithoutPostingDebt(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        Database::connect()->table('users')->where('id', 501)->update(['name' => 'Sample Staff', 'employee_id' => 'EMP-501']);
        $this->withRoutes([
            ['GET', 'test/shared-deductions', 'DeductionWorkspaceController::index'],
            ['POST', 'test/shared-deductions/batches', 'DeductionWorkspaceController::prepareBatch'],
        ]);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'partial', 'requested_amount' => 150],
        ], 900);
        $batchId = (int) $prepared['batch']['id'];
        $owner = ['logged_in' => true, 'user_id' => 900, 'role' => 'COLLECTING_OFFICER', 'available_roles' => ['COLLECTING_OFFICER']];

        $page = $this->withSession($owner)->get('test/shared-deductions?period_id=1');
        $page->assertOK();
        $body = (string) $page->getBody();
        $this->assertStringContainsString('Save prepared changes', $body);
        $this->assertStringContainsString('value="150"', $body);
        $this->assertStringContainsString('name="requests[501][selected]" value="1" checked', $body);
        $this->assertStringContainsString('Submit batch for results', $body);

        $otherSession = ['logged_in' => true, 'user_id' => 901, 'role' => 'PAYROLL_CLERK', 'available_roles' => ['PAYROLL_CLERK']];
        $other = $this->withSession($otherSession)
            ->get('test/shared-deductions?period_id=1');
        $other->assertOK();
        $this->assertStringNotContainsString('Save prepared changes', (string) $other->getBody());
        $this->assertStringContainsString('Only the batch creator may edit or submit', (string) $other->getBody());

        $this->withSession($otherSession)->post('test/shared-deductions/batches', [
            csrf_token() => csrf_hash(),
            'period_id' => 1,
            'requests' => [501 => ['selected' => '1', 'deduction_choice' => 'partial', 'requested_amount' => '100', 'preparation_reason' => '']],
        ])->assertRedirect();
        $this->assertSame(150.0, (float) Database::connect()->table('deduction_batch_items')->get()->getRow()->requested_amount);

        $this->withSession($owner)->post('test/shared-deductions/batches', [
            csrf_token() => csrf_hash(),
            'period_id' => 1,
            'requests' => [501 => ['selected' => '1', 'deduction_choice' => 'partial', 'requested_amount' => '120', 'preparation_reason' => '']],
        ])->assertRedirect();
        $this->assertSame($batchId, (int) Database::connect()->table('deduction_batches')->get()->getRow()->id);
        $this->assertSame(120.0, (float) Database::connect()->table('deduction_batch_items')->get()->getRow()->requested_amount);
        $this->assertSame(600.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $this->assertSame(1, Database::connect()->table('audit_logs')->where('action', 'ACCOUNTING_EDIT_DEDUCTION_BATCH')->countAllResults());
    }

    public function testSubmittingBatchDoesNotPostRequestedAmounts(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $this->seedEmployee(502, 'staff', 300);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'partial', 'requested_amount' => 150],
            ['user_id' => 502, 'deduction_choice' => 'full', 'requested_amount' => 300],
        ], 900);

        $submitted = $service->submit((int) $prepared['batch']['id'], 900);

        $this->assertSame('success', $submitted['status']);
        $this->assertSame('submitted', $submitted['batch']['status']);
        $balances = Database::connect()->table('balances')->orderBy('user_id')->get()->getResultArray();
        $this->assertSame([600.0, 300.0], array_map('floatval', array_column($balances, 'current_debt')));
        $this->assertSame(0, Database::connect()->table('debt_cashbook_entries')->countAllResults());
        $this->assertSame(2, Database::connect()->table('deduction_batch_items')->where('result_status', 'pending')->countAllResults());
    }

    public function testSubmittedBatchRequiresOfficialResultBeforeBalanceChanges(): void
    {
        $this->seedEmployee(501, 'staff', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [
            ['user_id' => 501, 'deduction_choice' => 'partial', 'requested_amount' => 60],
        ], 900);
        $batchId = (int) $prepared['batch']['id'];
        $service->submit($batchId, 900);

        $this->assertSame(200.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;
        $this->assertSame('error', $service->confirmResult($itemId, ['confirmed_amount' => 60], 900)['status']);
        $confirmed = $service->confirmResult($itemId, ['confirmed_amount' => 60, 'result_reference' => 'PAY-501'], 900);
        $this->assertSame('success', $confirmed['status']);
        $this->assertSame(140.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
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

    public function testConfirmationRejectsMalformedOrFractionalCentAmountsBeforePosting(): void
    {
        $this->seedEmployee(501, 'staff', 600);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => '50.00']], 900);
        $service->submit((int) $prepared['batch']['id'], 900);
        $db = Database::connect();
        $itemId = (int) $db->table('deduction_batch_items')->get()->getRow()->id;
        foreach (['10.005', 'invalid', '1e2', -1, INF] as $amount) {
            $result = $service->confirmResult($itemId, [
                'confirmed_amount' => $amount,
                'reason_code' => 'insufficient_salary',
                'result_reference' => 'TEST-INVALID',
            ], 900);
            $this->assertSame('error', $result['status']);
        }
        $this->assertSame(600.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $this->assertSame(0, $db->table('debt_cashbook_entries')->countAllResults());
        $this->assertSame('pending', $db->table('deduction_batch_items')->where('id', $itemId)->get()->getRow()->result_status);

        $valid = $service->confirmResult($itemId, [
            'confirmed_amount' => '25.50',
            'reason_code' => 'insufficient_salary',
            'result_reference' => 'TEST-VALID-PARTIAL',
        ], 900);
        $this->assertSame('success', $valid['status']);
        $this->assertSame(574.5, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
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

    public function testAssignedAccountingOfficerCanFinalizeWithAuditTrail(): void
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
        $otherFinalize = $service->finalize($batchId, 901);
        $finalized = $service->finalize($batchId, 900);

        $this->assertSame('submitted', $submitted['batch']['status']);
        $this->assertSame('success', $confirmed['status']);
        $this->assertSame('reconciled', $reconciled['batch']['status']);
        $this->assertSame(403, $otherFinalize['code']);
        $this->assertSame('finalized', $finalized['batch']['status']);
        $period = Database::connect()->table('deduction_periods')->where('id', 1)->get()->getRowArray();
        $this->assertSame('finalized', $period['status']);
        $this->assertSame(900, (int) $period['finalized_by']);
        $this->assertSame(1, Database::connect()->table('audit_logs')->where('action', 'ACCOUNTING_FINALIZE_DEDUCTION_BATCH')->countAllResults());
    }

    public function testBatchTransitionsRejectRepeatsWithoutDuplicateAudit(): void
    {
        $this->seedEmployee(501, 'staff', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);
        $batchId = (int) $prepared['batch']['id'];
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;

        $this->assertSame('success', $service->submit($batchId, 900)['status']);
        $this->assertSame(409, $service->submit($batchId, 900)['code']);
        $this->assertSame('success', $service->confirmResult($itemId, [
            'confirmed_amount' => 100, 'result_reference' => 'PAYROLL-REPEAT-501',
        ], 900)['status']);
        $this->assertSame('success', $service->reconcile($batchId, 900)['status']);
        $this->assertSame(409, $service->reconcile($batchId, 900)['code']);
        $this->assertSame('success', $service->finalize($batchId, 900)['status']);
        $this->assertSame(409, $service->finalize($batchId, 900)['code']);

        $db = Database::connect();
        foreach (['ACCOUNTING_SUBMIT_DEDUCTION_BATCH', 'ACCOUNTING_RECONCILE_DEDUCTION_BATCH', 'ACCOUNTING_FINALIZE_DEDUCTION_BATCH'] as $action) {
            $this->assertSame(1, $db->table('audit_logs')->where('action', $action)->countAllResults(), $action);
        }
    }

    public function testSubmitDoesNotAdvancePeriodWhenAuditWriteFails(): void
    {
        $this->seedEmployee(501, 'staff', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);
        $batchId = (int) $prepared['batch']['id'];
        $db = Database::connect();
        $db->query('DROP TABLE ' . $db->getPrefix() . 'audit_logs');

        $result = $service->submit($batchId, 900);

        $this->assertSame('error', $result['status']);
        $this->assertSame('prepared', $db->table('deduction_batches')->where('id', $batchId)->get()->getRow()->status);
        $this->assertSame('draft', $db->table('deduction_periods')->where('id', 1)->get()->getRow()->status);
        $db->resetTransStatus();
    }

    public function testReconcileAndFinalizeRollBackWhenAuditWriteFails(): void
    {
        foreach (['reconcile' => 'processed', 'finalize' => 'reconciled'] as $transition => $expectedStatus) {
            $this->resetSchema();
            $this->seedEmployee(501, 'staff', 200);
            $service = new DeductionBatchService();
            $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);
            $batchId = (int) $prepared['batch']['id'];
            $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;
            $this->assertSame('success', $service->submit($batchId, 900)['status']);
            $this->assertSame('success', $service->confirmResult($itemId, [
                'confirmed_amount' => 100, 'result_reference' => 'PAYROLL-ATOMIC-501',
            ], 900)['status']);
            if ($transition === 'finalize') {
                $this->assertSame('success', $service->reconcile($batchId, 900)['status']);
            }

            $db = Database::connect();
            $db->query('DROP TABLE ' . $db->getPrefix() . 'audit_logs');
            $result = $service->{$transition}($batchId, 900);

            $this->assertSame('error', $result['status'], $transition);
            $this->assertSame($expectedStatus, $db->table('deduction_batches')->where('id', $batchId)->get()->getRow()->status, $transition);
            $this->assertSame($expectedStatus, $db->table('deduction_periods')->where('id', 1)->get()->getRow()->status, $transition);
            $db->resetTransStatus();
        }
    }

    public function testCashierHeadCollectingOfficerAndPayrollClerkUseSamePostingEndpoint(): void
    {
        $this->withRoutes([
            ['POST', 'test/shared-confirm/(:num)', 'DeductionWorkspaceController::confirmItem/$1'],
        ]);
        foreach (['COLLECTING_OFFICER', 'PAYROLL_CLERK', 'CASHIER_HEAD'] as $role) {
            $this->resetSchema();
            $this->seedEmployee(501, 'staff', 200);
            $service = new DeductionBatchService();
            $batch = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);
            $service->submit((int) $batch['batch']['id'], 900);
            $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;

            $this->withSession([
                'logged_in' => true, 'user_id' => 901, 'role' => $role, 'available_roles' => [$role],
            ])->post('test/shared-confirm/' . $itemId, [
                csrf_token() => csrf_hash(),
                'confirmed_amount' => 75,
                'reason_code' => 'insufficient_salary',
                'result_reference' => 'TEST-' . $role,
            ])->assertRedirect();

            $this->assertSame(125.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt, $role);
            $this->assertSame(1, Database::connect()->table('debt_cashbook_entries')->countAllResults(), $role);
            $this->assertSame(901, (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->confirmed_by, $role);
        }
    }

    public function testEmployeeDeductionStatementUsesConfirmationTimeBalances(): void
    {
        $this->withRoutes([
            ['GET', 'test/my-deductions', 'UserController::deductionsData'],
        ]);
        $this->seedEmployee(501, 'staff', 200);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [['user_id' => 501, 'requested_amount' => 100]], 900);
        $service->submit((int) $prepared['batch']['id'], 900);
        $itemId = (int) Database::connect()->table('deduction_batch_items')->get()->getRow()->id;

        // A separate payment after preparation changes the balance before posting.
        Database::connect()->table('balances')->where('user_id', 501)->update(['current_debt' => 180]);
        $confirmed = $service->confirmResult($itemId, [
            'confirmed_amount' => 75,
            'reason_code' => 'insufficient_salary',
            'result_reference' => 'TEST-EMPLOYEE-STATEMENT',
        ], 900);
        $this->assertSame('success', $confirmed['status']);

        $result = $this->withSession(['logged_in' => true, 'user_id' => 501, 'role' => 'USER'])
            ->get('test/my-deductions');
        $this->assertTrue($result->isOK(), (string) $result->getJSON());
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertSame(180.0, (float) $body['deductions'][0]['debt_before']);
        $this->assertSame(105.0, (float) $body['deductions'][0]['debt_after']);
        $this->assertSame(25.0, (float) $body['deductions'][0]['undeducted_amount']);
        $this->assertSame('insufficient_salary', $body['deductions'][0]['reason_code']);
        $this->assertSame('TEST-EMPLOYEE-STATEMENT', $body['deductions'][0]['result_reference']);
        $this->assertSame(180.0, (float) $body['summary']['debt_before']);
        $this->assertSame(105.0, (float) $body['summary']['debt_after']);
        $this->assertSame(200.0, (float) Database::connect()->table('deduction_batch_items')->where('id', $itemId)->get()->getRow()->debt_snapshot);
    }

    public function testNextPeriodShowsPriorUnresolvedResultWithSourceAndAge(): void
    {
        $this->seedEmployee(501, 'staff', 200);
        $this->seedEmployee(502, 'staff', 80);
        $service = new DeductionBatchService();
        $prepared = $service->prepare(1, [
            ['user_id' => 501, 'requested_amount' => 100],
            ['user_id' => 502, 'requested_amount' => 80],
        ], 900);
        $service->submit((int) $prepared['batch']['id'], 900);
        $itemId = (int) Database::connect()->table('deduction_batch_items')->where('user_id', 501)->get()->getRow()->id;
        $confirmed = $service->confirmResult($itemId, [
            'confirmed_amount' => 50,
            'reason_code' => 'insufficient_salary',
            'result_reference' => 'PAY-2026-08-A-501',
        ], 900);
        $this->assertSame('success', $confirmed['status']);
        $this->assertSame('partially_processed', Database::connect()->table('deduction_batches')->get()->getRow()->status);
        $confirmedThreeDaysAgo = ibems_now()->setTimezone(new DateTimeZone('Asia/Manila'))
            ->setTime(0, 0)->modify('-3 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        Database::connect()->table('deduction_batch_items')->where('id', $itemId)
            ->update(['confirmed_at' => $confirmedThreeDaysAgo]);
        Database::connect()->table('deduction_periods')->insert([
            'id' => 2, 'period_code' => '2026-08-B', 'label' => 'August B',
            'date_start' => '2026-08-16', 'date_end' => '2026-08-31', 'status' => 'draft',
        ]);

        $prior = (new DeductionCarryoverTraceService())->priorResults([501, 502], [
            'id' => 2, 'date_start' => '2026-08-16',
        ]);
        $this->assertArrayNotHasKey(502, $prior);
        $this->assertSame('2026-08-A', $prior[501]['period_code']);
        $this->assertSame('partially_deducted', $prior[501]['result_status']);
        $this->assertSame('PAY-2026-08-A-501', $prior[501]['result_reference']);
        $this->assertSame(150.0, $prior[501]['balance_after_result']);
        $this->assertSame(3, $prior[501]['days_since_result']);
        $this->assertSame([], (new DeductionCarryoverTraceService())->priorResults([501], [
            'id' => 1, 'date_start' => '2026-08-01',
        ]));

        $this->withRoutes([
            ['GET', 'test/deduction-workspace', 'DeductionWorkspaceController::index'],
        ]);
        $page = $this->withSession([
            'logged_in' => true, 'user_id' => 900, 'role' => 'PAYROLL_CLERK',
            'available_roles' => ['PAYROLL_CLERK'],
        ])->get('test/deduction-workspace?period_id=2');
        $page->assertOK();
        $this->assertStringContainsString('Latest earlier result with a remaining balance', (string) $page->getBody());
        $this->assertStringContainsString('PAY-2026-08-A-501', (string) $page->getBody());
        foreach (['COLLECTING_OFFICER', 'CASHIER_HEAD'] as $cashierRole) {
            $cashierPage = $this->withSession([
                'logged_in' => true, 'user_id' => 901, 'role' => $cashierRole,
                'available_roles' => [$cashierRole],
            ])->get('test/deduction-workspace?period_id=2');
            $cashierPage->assertOK();
            $this->assertStringContainsString('PAY-2026-08-A-501', (string) $cashierPage->getBody());
        }
    }

    public function testZeroRequestRequiresAnExplicitResultAndRetryMatchesAllResultDetails(): void
    {
        $this->seedEmployee(501, 'staff', 200);
        $service = new DeductionBatchService();
        $batch = $service->prepare(1, [['user_id' => 501, 'deduction_choice' => 'none', 'requested_amount' => 0, 'preparation_reason' => 'Official result pending']], 900);
        $this->assertSame('success', $batch['status']);
        $service->submit((int) $batch['batch']['id'], 900);
        $db = Database::connect();
        $id = (int) $db->table('deduction_batch_items')->get()->getRow()->id;
        $before = $db->table('deduction_batch_items')->get()->getRowArray();
        $payload = ['confirmed_amount' => 0, 'result_reference' => 'QA-ZERO', 'reason_code' => '', 'result_notes' => 'Official zero result'];
        $this->assertSame('error', $service->confirmResult($id, $payload, 900)['status']);
        $this->assertSame($before, $db->table('deduction_batch_items')->get()->getRowArray());
        $payload['reason_code'] = 'not_deducted';
        $this->assertSame('success', $service->confirmResult($id, $payload, 900)['status']);
        $snapshot = $db->table('deduction_batch_items')->get()->getRowArray();
        $this->assertTrue($service->confirmResult($id, $payload, 900)['idempotent']);
        foreach ([['reason_code' => 'insufficient_salary'], ['result_notes' => 'Changed official result']] as $change) {
            $this->assertSame(409, $service->confirmResult($id, array_replace($payload, $change), 900)['code']);
            $this->assertSame($snapshot, $db->table('deduction_batch_items')->get()->getRowArray());
        }
        $this->assertSame(200.0, (float) $db->table('balances')->get()->getRow()->current_debt);
        $this->assertSame(0, $db->table('debt_cashbook_entries')->countAllResults());
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
        $db->query('CREATE TABLE ' . $tn('users') . ' (id INTEGER PRIMARY KEY, employee_id TEXT, name TEXT, email TEXT, user_type TEXT, base_salary REAL DEFAULT 0, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('balances') . ' (user_id INTEGER PRIMARY KEY, credit_limit REAL, current_debt REAL, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_periods') . ' (id INTEGER PRIMARY KEY, period_code TEXT, label TEXT, date_start TEXT, date_end TEXT, status TEXT, reviewed_by INTEGER, submitted_by INTEGER, confirmed_by INTEGER, finalized_by INTEGER, reviewed_at TEXT, submitted_at TEXT, confirmed_at TEXT, finalized_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, period_id INTEGER UNIQUE, status TEXT, total_accounts INTEGER, total_requested REAL, total_confirmed REAL, total_carryover REAL, legacy_settlement_run_id INTEGER, notes TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('deduction_batch_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER, user_id INTEGER, debt_snapshot REAL, opening_debt_snapshot REAL DEFAULT 0, period_debits_snapshot REAL DEFAULT 0, period_credits_snapshot REAL DEFAULT 0, salary_snapshot REAL DEFAULT 0, deduction_choice TEXT DEFAULT "full", preparation_reason TEXT, requested_amount REAL, confirmed_amount REAL, carryover_amount REAL, result_status TEXT, reason_code TEXT, result_reference TEXT, result_notes TEXT, confirmed_by INTEGER, confirmed_at TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, payload_json TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, debt_before REAL, debt_after REAL, credit_limit_snapshot REAL, available_credit_snapshot REAL, reference_type TEXT, reference_id INTEGER, actor_id INTEGER, remarks TEXT, meta_json TEXT, created_at TEXT, updated_at TEXT)');
        $db->table('deduction_periods')->insert(['id' => 1, 'period_code' => '2026-08-A', 'label' => 'August A', 'date_start' => '2026-08-01', 'date_end' => '2026-08-15', 'status' => 'draft']);
    }
}
