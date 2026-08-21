<?php

use App\Services\DebtPeriodRegisterService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class DebtPeriodRegisterServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $tn = static fn (string $name): string => $db->getPrefix() . $name;
        foreach (['debt_cashbook_entries', 'balances', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('users') . ' (id INTEGER PRIMARY KEY, employee_id TEXT, name TEXT, email TEXT, user_type TEXT, base_salary REAL, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('balances') . ' (user_id INTEGER PRIMARY KEY, credit_limit REAL, current_debt REAL)');
        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, direction TEXT, amount REAL, created_at TEXT)');
        $db->table('users')->insert(['id' => 501, 'employee_id' => 'EMP-501', 'name' => 'Employee', 'email' => 'employee@example.test', 'user_type' => 'staff', 'base_salary' => 5000, 'is_active' => 1]);
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 2000, 'current_debt' => 800]);
        $db->table('debt_cashbook_entries')->insertBatch([
            ['user_id' => 501, 'direction' => 'debit', 'amount' => 300, 'created_at' => '2026-08-05 10:00:00'],
            ['user_id' => 501, 'direction' => 'credit', 'amount' => 100, 'created_at' => '2026-08-10 10:00:00'],
            ['user_id' => 501, 'direction' => 'debit', 'amount' => 200, 'created_at' => '2026-08-20 10:00:00'],
        ]);
    }

    public function testReconstructsDebtAtCutoffFromCurrentBalanceAndLaterEntries(): void
    {
        $rows = (new DebtPeriodRegisterService())->build(['date_start' => '2026-08-01', 'date_end' => '2026-08-15']);
        $this->assertCount(1, $rows);
        $this->assertSame(600.0, (float) $rows[0]['cutoff_debt']);
        $this->assertSame(400.0, (float) $rows[0]['opening_debt']);
        $this->assertSame(300.0, (float) $rows[0]['period_debits']);
        $this->assertSame(100.0, (float) $rows[0]['period_credits']);
        $this->assertSame(5000.0, (float) $rows[0]['salary_reference']);
    }
}
