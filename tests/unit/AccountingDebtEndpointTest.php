<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class AccountingDebtEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testDeductionWorkflowProvidesScalableEmployeeFilters(): void
    {
        $view = file_get_contents(APPPATH . 'Views/accounting/debts.php');
        $script = file_get_contents(FCPATH . 'assets/js/accounting-debts.js');

        $this->assertStringContainsString('workflow-candidate-search', $view);
        $this->assertStringContainsString('workflow-candidate-type', $view);
        $this->assertStringContainsString('workflow-candidate-salary', $view);
        $this->assertStringContainsString('workflow-select-matching', $view);
        $this->assertStringContainsString('workflow-employees-modal', $view);
        $this->assertStringContainsString('workflow-summary-employees', $view);
        $this->assertStringContainsString('max-h-[65vh]', $view);
        $this->assertStringContainsString('grid-template-columns: 18px minmax(320px, 1fr) 180px 240px', $view);
        $this->assertStringContainsString('.workflow-preparation-reason', $view);
        $this->assertStringContainsString('z-index: 1350', $view);
        $this->assertStringContainsString('function filterWorkflowCandidates()', $script);
        $this->assertStringContainsString('amount.readOnly = choice.value === "full"', $script);
        $this->assertStringContainsString('No deduction requires a reason', $script);
        $this->assertStringContainsString('data-workflow-batch-action="apply"', $script);
        $this->assertStringNotContainsString('workflow-confirmed-amount', $script);
    }

    public function testAccountingHasDedicatedInAppPayrollDeductionWorkflow(): void
    {
        $layout = file_get_contents(APPPATH . 'Views/layouts/accounting.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $view = file_get_contents(APPPATH . 'Views/accounting/debts.php');
        $script = file_get_contents(FCPATH . 'assets/js/accounting-debts.js');

        $this->assertStringContainsString("'accounting/deductions'", $layout);
        $this->assertStringNotContainsString("accounting/deduction-batches/(:num)/export", $routes);
        $this->assertStringNotContainsString("accounting/deduction-batches/(:num)/import-results", $routes);
        $this->assertStringNotContainsString('workflow-results-import', $view);
        $this->assertStringContainsString('Edit deductions', $script);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->withRoutes([
            ['GET', 'accounting/debts/data', 'AccountingController::debtsData'],
            ['GET', 'accounting/debts/daily-summary', 'AccountingController::dailySummary'],
            ['GET', 'accounting/salary-schedules', 'AccountingController::salarySchedules'],
            ['POST', 'accounting/debts/deduct', 'AccountingController::deductDebt'],
            ['POST', 'accounting/debts/deduct-full', 'AccountingController::deductFullDebt'],
            ['POST', 'accounting/debts/financial-profile', 'AccountingController::updateFinancialProfile'],
            ['POST', 'accounting/settlement/apply', 'AccountingController::applySettlementRun'],
        ]);
    }

    public function testDebtDataUsesBalanceStateLabelsAndAccountCount(): void
    {
        $this->seedBalance(501, 100, 100);
        $this->seedBalance(502, 500, 125);
        $this->seedBalance(503, 500, 0);

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/debts/data');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(3, (int) ($body['summary']['employee_account_count'] ?? 0));
        $this->assertSame(2, (int) ($body['summary']['employee_debt_accounts'] ?? 0));

        $statuses = array_column($body['data'] ?? [], 'debt_status_label', 'user_id');
        $this->assertSame('At Credit Limit', $statuses[501] ?? null);
        $this->assertSame('Outstanding', $statuses[502] ?? null);
        $this->assertSame('No Outstanding Debt', $statuses[503] ?? null);
    }

    public function testManualDeductionUpdatesBalanceCashbookAndAudit(): void
    {
        $this->seedBalance(501, 500, 180);

        $result = $this->accountingPost('accounting/debts/deduct', [
            'user_id' => 501,
            'amount' => 55,
            'reason' => 'Payroll deduction',
        ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertSame(180.0, (float) ($body['previous_debt'] ?? 0));
        $this->assertSame(125.0, (float) ($body['new_debt'] ?? 0));

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $cashbook = $db->table('debt_cashbook_entries')->where('user_id', 501)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_DEDUCT_DEBT')->get()->getRowArray();

        $this->assertSame(125.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertSame('credit', $cashbook['direction'] ?? null);
        $this->assertSame(55.0, (float) ($cashbook['amount'] ?? 0));
        $this->assertSame(180.0, (float) ($cashbook['debt_before'] ?? 0));
        $this->assertSame(125.0, (float) ($cashbook['debt_after'] ?? 0));
        $this->assertSame(900, (int) ($audit['actor_id'] ?? 0));
    }

    public function testDailySummaryUsesManilaBusinessDayForUtcCashbookTimestamps(): void
    {
        $manila = new DateTimeZone('Asia/Manila');
        $utc = new DateTimeZone('UTC');
        $storedAt = (new DateTimeImmutable('today 00:30:00', $manila))->setTimezone($utc)->format('Y-m-d H:i:s');

        Database::connect()->table('debt_cashbook_entries')->insert([
            'user_id' => 501,
            'entry_type' => 'confirmed_salary_deduction',
            'direction' => 'credit',
            'amount' => 140,
            'debt_before' => 200,
            'debt_after' => 60,
            'created_at' => $storedAt,
            'updated_at' => $storedAt,
        ]);

        Database::connect()->table('deduction_batch_items')->insert([
            'batch_id' => 1,
            'user_id' => 502,
            'debt_at_cutoff' => 40,
            'requested_amount' => 40,
            'confirmed_amount' => 40,
            'carryover_amount' => 0,
            'result_status' => 'fully_deducted',
            'confirmed_at' => $storedAt,
            'created_at' => $storedAt,
            'updated_at' => $storedAt,
        ]);

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/debts/daily-summary');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame((new DateTimeImmutable('now', $manila))->format('Y-m-d'), $body['date'] ?? null);
        $this->assertSame(1, (int) ($body['deduction_count'] ?? 0));
        $this->assertSame(40.0, (float) ($body['deducted_amount'] ?? 0));
    }

    public function testDeductionCannotExceedCurrentDebtAndWritesNothing(): void
    {
        $this->seedBalance(501, 500, 40);

        $result = $this->accountingPost('accounting/debts/deduct', [
            'user_id' => 501,
            'amount' => 41,
            'reason' => 'Too much',
        ]);

        $result->assertStatus(400);
        $body = $this->jsonBody($result);
        $this->assertSame('Deduction cannot exceed current debt.', $body['message'] ?? null);

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(40.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertSame(0, $db->table('debt_cashbook_entries')->countAllResults());
        $this->assertSame(0, $db->table('audit_logs')->countAllResults());
    }

    public function testFullDeductionClearsDebtAndRecordsCreditEntry(): void
    {
        $this->seedBalance(501, 500, 275);

        $result = $this->accountingPost('accounting/debts/deduct-full', [
            'user_id' => 501,
            'reason' => 'Final payroll settlement',
        ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(0.0, (float) ($body['new_debt'] ?? -1));
        $this->assertSame(275.0, (float) ($body['deducted_amount'] ?? 0));

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $cashbook = $db->table('debt_cashbook_entries')->where('entry_type', 'full_deduction')->get()->getRowArray();
        $this->assertSame(0.0, (float) ($balance['current_debt'] ?? -1));
        $this->assertSame('credit', $cashbook['direction'] ?? null);
        $this->assertSame(275.0, (float) ($cashbook['amount'] ?? 0));
        $this->assertSame(500.0, (float) ($cashbook['available_credit_snapshot'] ?? 0));
    }

    public function testSalaryReductionPreservesDebtAndDerivesCreditLimit(): void
    {
        $this->seedBalance(501, 5000, 1200, 32000);

        $result = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-1',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 10,
            'reason' => 'Contract compensation adjustment',
        ]);

        $this->assertTrue($result->isOK(), (string) $result->getJSON());
        $body = $this->jsonBody($result);
        $this->assertSame(5000.0, (float) ($body['previous_credit_limit'] ?? 0));
        $this->assertSame(1463.4, (float) ($body['new_credit_limit'] ?? 0));

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();
        $this->assertSame(1463.4, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(0.1, (float) ($balance['credit_rate'] ?? 0));
        $this->assertSame(1200.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertNotNull($audit);
    }

    public function testAccountingSetsSalaryAndCreditTogetherWithAuditTrail(): void
    {
        $adminSource = file_get_contents(APPPATH . 'Controllers/AdminController.php');
        $adminView = file_get_contents(APPPATH . 'Views/admin/user-view.php');
        $this->assertStringContainsString('resolveEmployeeFinancialProfile', $adminSource);
        $this->assertStringNotContainsString('DEFAULT_EMPLOYEE_CREDIT_LIMIT', $adminSource);
        $this->assertStringContainsString('uv-a-schedule', $adminView);
        $this->assertStringContainsString('uv-a-credit-percent', $adminView);

        $this->seedBalance(501, 0, 0, 0);

        $result = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'plantilla',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-18',
            'salary_step' => 3,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 30,
            'reason' => 'Initial financial profile assignment',
        ]);

        $this->assertTrue($result->isOK(), (string) $result->getJSON());
        $body = $this->jsonBody($result);
        $this->assertSame(54933.0, (float) ($body['new_base_salary'] ?? 0));
        $this->assertSame(16479.9, (float) ($body['new_credit_limit'] ?? 0));

        $db = Database::connect();
        $user = $db->table('users')->where('id', 501)->get()->getRowArray();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();

        $this->assertSame(54933.0, (float) ($user['base_salary'] ?? 0));
        $this->assertSame('plantilla', $user['employment_type'] ?? null);
        $this->assertSame('SG-18', $user['salary_grade'] ?? null);
        $this->assertSame(16479.9, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(0.3, (float) ($balance['credit_rate'] ?? 0));
        $this->assertSame(0.0, (float) ($balance['current_debt'] ?? -1));
        $this->assertSame(900, (int) ($audit['actor_id'] ?? 0));
        $payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
        $this->assertSame('Initial financial profile assignment', $payload['reason'] ?? null);
    }

    public function testAccountingListsAndCanInitializeEligibleEmployeeWithoutBalance(): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 504,
            'employee_id' => 'EMP-504',
            'name' => 'Needs Setup',
            'email' => 'needs.setup@example.test',
            'role' => 'USER',
            'user_type' => 'faculty',
            'base_salary' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $list = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/debts/data');
        $list->assertOK();
        $body = $this->jsonBody($list);
        $row = array_values(array_filter($body['data'] ?? [], static fn(array $item): bool => (int) ($item['user_id'] ?? 0) === 504))[0] ?? [];
        $this->assertFalse((bool) ($row['financial_profile_configured'] ?? true));
        $this->assertSame(1, (int) ($body['summary']['needs_setup_count'] ?? 0));

        $save = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 504,
            'employment_type' => 'part_time',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 25,
            'reason' => 'Initial Accounting setup',
        ]);
        $this->assertTrue($save->isOK(), (string) $save->getJSON());
        $saved = $this->jsonBody($save);
        $this->assertTrue((bool) ($saved['financial_profile_created'] ?? false));
        $balance = $db->table('balances')->where('user_id', 504)->get()->getRowArray();
        $this->assertSame(7926.25, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(0.0, (float) ($balance['current_debt'] ?? -1));
    }

    public function testSettlementDeductsUpToSalaryAndWritesConnectedLedger(): void
    {
        $this->seedBalance(501, 1000, 600, 250);

        $result = $this->accountingPost('accounting/settlement/apply', [
            'run_month' => '2026-07',
            'selected_user_ids' => [501],
            'notes' => 'July payroll settlement',
        ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(1, (int) ($body['total_accounts'] ?? 0));
        $this->assertSame(600.0, (float) ($body['total_debt_before'] ?? 0));
        $this->assertSame(250.0, (float) ($body['total_deducted'] ?? 0));
        $this->assertSame(350.0, (float) ($body['total_debt_after'] ?? 0));

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $cashbook = $db->table('debt_cashbook_entries')->where('entry_type', 'salary_deduction')->get()->getRowArray();
        $this->assertSame(350.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertSame('settlement_run', $cashbook['reference_type'] ?? null);
        $this->assertSame(250.0, (float) ($cashbook['amount'] ?? 0));
        $this->assertSame(1, $db->table('settlement_runs')->countAllResults());
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'ACCOUNTING_SETTLEMENT_DEDUCT')->countAllResults());
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'ACCOUNTING_RUN_SETTLEMENT')->countAllResults());
    }

    public function testDuplicateSettlementMonthIsRejectedWithoutSecondDeduction(): void
    {
        $this->seedBalance(501, 1000, 600, 250);

        $first = $this->accountingPost('accounting/settlement/apply', [
            'run_month' => '2026-07',
            'selected_user_ids' => [501],
        ]);
        $first->assertOK();

        $second = $this->accountingPost('accounting/settlement/apply', [
            'run_month' => '2026-07',
            'selected_user_ids' => [501],
        ]);
        $second->assertStatus(409);
        $body = $this->jsonBody($second);
        $this->assertSame('Settlement run for 2026-07 already exists.', $body['message'] ?? null);

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(350.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertSame(1, $db->table('settlement_runs')->countAllResults());
        $this->assertSame(1, $db->table('debt_cashbook_entries')->where('entry_type', 'salary_deduction')->countAllResults());
    }

    private function accountingPost(string $path, array $payload)
    {
        $security = service('security');
        $payload[$security->getTokenName()] = $security->getHash();

        return $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->withBodyFormat('json')->post($path, $payload);
    }

    private function jsonBody($result): array
    {
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function seedBalance(int $userId, float $creditLimit, float $currentDebt, float $baseSalary = 0): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => $userId,
            'employee_id' => 'EMP-' . $userId,
            'name' => 'Debt Account',
            'email' => 'debt' . $userId . '@example.test',
            'role' => 'USER',
            'user_type' => 'staff',
            'base_salary' => $baseSalary,
            'employment_type' => 'plantilla',
            'salary_grade' => 'SG-TEST',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'salary_schedule_id' => 1,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('balances')->insert([
            'user_id' => $userId,
            'credit_limit' => $creditLimit,
            'credit_rate' => 0.25,
            'current_debt' => $currentDebt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        foreach (['deduction_batch_items', 'debt_cashbook_entries', 'audit_logs', 'settlement_runs', 'salary_schedule_rates', 'salary_schedules', 'balances', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }

        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            base_salary REAL NOT NULL DEFAULT 0,
            employment_type TEXT,
            salary_grade TEXT,
            salary_step INTEGER,
            salary_effective_date TEXT,
            salary_schedule_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('balances') . ' (
            user_id INTEGER PRIMARY KEY,
            credit_limit REAL NOT NULL DEFAULT 0,
            credit_rate REAL NOT NULL DEFAULT 0.25,
            current_debt REAL NOT NULL DEFAULT 0,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('salary_schedules') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            effective_from TEXT NOT NULL,
            effective_to TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('salary_schedule_rates') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_id INTEGER NOT NULL,
            salary_grade INTEGER NOT NULL,
            salary_step INTEGER NOT NULL,
            monthly_salary REAL NOT NULL
        )');

        $db->table('salary_schedules')->insert([
            'id' => 1,
            'code' => 'PH-NG-2026-T3',
            'name' => 'Philippine National Government 2026 - Third Tranche',
            'effective_from' => '2026-01-01',
            'is_active' => 1,
        ]);
        $db->table('salary_schedule_rates')->insertBatch([
            ['schedule_id' => 1, 'salary_grade' => 1, 'salary_step' => 1, 'monthly_salary' => 14634],
            ['schedule_id' => 1, 'salary_grade' => 11, 'salary_step' => 1, 'monthly_salary' => 31705],
            ['schedule_id' => 1, 'salary_grade' => 18, 'salary_step' => 3, 'monthly_salary' => 54933],
        ]);

        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            payload_json TEXT,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('settlement_runs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_month TEXT NOT NULL UNIQUE,
            run_by INTEGER,
            run_at TEXT,
            total_accounts INTEGER NOT NULL DEFAULT 0,
            total_debt_before REAL NOT NULL DEFAULT 0,
            notes TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            entry_type TEXT,
            direction TEXT,
            amount REAL,
            debt_before REAL,
            debt_after REAL,
            credit_limit_snapshot REAL,
            available_credit_snapshot REAL,
            reference_type TEXT,
            reference_id INTEGER,
            actor_id INTEGER,
            remarks TEXT,
            meta_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('deduction_batch_items') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER,
            user_id INTEGER,
            debt_at_cutoff REAL,
            requested_amount REAL,
            confirmed_amount REAL,
            carryover_amount REAL,
            result_status TEXT,
            confirmed_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->resetDataCache();
    }
}
