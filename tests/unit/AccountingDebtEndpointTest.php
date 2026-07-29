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

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->withRoutes([
            ['POST', 'accounting/debts/deduct', 'AccountingController::deductDebt'],
            ['POST', 'accounting/debts/deduct-full', 'AccountingController::deductFullDebt'],
            ['POST', 'accounting/debts/credit-limit', 'AccountingController::updateCreditLimit'],
            ['POST', 'accounting/settlement/apply', 'AccountingController::applySettlementRun'],
        ]);
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

    public function testCreditLimitUpdatePreservesDebtAndWritesAudit(): void
    {
        $this->seedBalance(501, 500, 125);

        $result = $this->accountingPost('accounting/debts/credit-limit', [
            'user_id' => 501,
            'credit_limit' => 750,
            'reason' => 'Approved increase',
        ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(500.0, (float) ($body['previous_credit_limit'] ?? 0));
        $this->assertSame(750.0, (float) ($body['new_credit_limit'] ?? 0));

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_CREDIT_LIMIT')->get()->getRowArray();
        $this->assertSame(750.0, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(125.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertNotNull($audit);
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
            'name' => 'Debt Account',
            'email' => 'debt' . $userId . '@example.test',
            'role' => 'USER',
            'user_type' => 'staff',
            'base_salary' => $baseSalary,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('balances')->insert([
            'user_id' => $userId,
            'credit_limit' => $creditLimit,
            'current_debt' => $currentDebt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        foreach (['debt_cashbook_entries', 'audit_logs', 'settlement_runs', 'balances', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }

        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            base_salary REAL NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('balances') . ' (
            user_id INTEGER PRIMARY KEY,
            credit_limit REAL NOT NULL DEFAULT 0,
            current_debt REAL NOT NULL DEFAULT 0,
            updated_at TEXT
        )');

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
    }
}
