<?php

use App\Services\CashierOfficeSalesService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class CashierOfficeSalesServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        foreach (['transaction_payments', 'transactions', 'stores'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $db->getPrefix() . $table);
        }
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'stores (id INTEGER PRIMARY KEY, store_name TEXT NOT NULL)');
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'transactions (id INTEGER PRIMARY KEY, store_id INTEGER NOT NULL, amount DECIMAL(10,2) NOT NULL, payment_method TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL)');
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'transaction_payments (id INTEGER PRIMARY KEY, transaction_id INTEGER NOT NULL, payment_method TEXT NOT NULL, amount DECIMAL(10,2) NOT NULL)');
        $db->table('stores')->insertBatch([
            ['id' => 1, 'store_name' => 'Main Store'],
            ['id' => 2, 'store_name' => 'Tech Store'],
        ]);
        $db->table('transactions')->insertBatch([
            ['id' => 1, 'store_id' => 1, 'amount' => 100, 'payment_method' => 'cash', 'status' => 'completed', 'created_at' => '2026-09-23 01:00:00'],
            ['id' => 2, 'store_id' => 1, 'amount' => 100, 'payment_method' => 'cash', 'status' => 'completed', 'created_at' => '2026-09-23 02:00:00'],
            ['id' => 3, 'store_id' => 1, 'amount' => 50, 'payment_method' => 'debt', 'status' => 'pending', 'created_at' => '2026-09-23 03:00:00'],
            ['id' => 4, 'store_id' => 2, 'amount' => 30, 'payment_method' => 'card', 'status' => 'completed', 'created_at' => '2026-09-23 04:00:00'],
        ]);
        $db->table('transaction_payments')->insertBatch([
            ['id' => 1, 'transaction_id' => 2, 'payment_method' => 'cash', 'amount' => 60],
            ['id' => 2, 'transaction_id' => 2, 'payment_method' => 'debt', 'amount' => 40],
        ]);
    }

    public function testDailyTotalsIncludeSplitPaymentsButNotPendingSales(): void
    {
        $report = (new CashierOfficeSalesService(Database::connect()))->daily('2026-09-23');

        $this->assertSame(3, $report['totals']['transactions']);
        $this->assertSame(23000, $report['totals']['sales_cents']);
        $this->assertSame(16000, $report['totals']['cash_cents']);
        $this->assertSame(4000, $report['totals']['debt_cents']);
        $this->assertSame(3000, $report['totals']['other_cents']);
        $this->assertSame('Main Store', $report['stores'][0]['name']);
    }

    public function testInvalidBusinessDateFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CashierOfficeSalesService(Database::connect()))->daily('2026-02-30');
    }

    public function testCashierDashboardSeparatesDirectTotalsFromReviewedReports(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/office/cashier.php');
        $this->assertStringContainsString('BE Director-reviewed store submissions arrive in Daily Reports', $view);
        $this->assertStringContainsString('shared Deductions page', $view);
        $this->assertStringNotContainsString('Report routing is not active yet', $view);
    }

    public function testBeDirectorDashboardDescribesAssignedSupervisorHandoff(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/OfficePortalController.php');
        $this->assertStringContainsString('An assigned Store Supervisor submits each closed-day snapshot', $controller);
        $this->assertStringNotContainsString('Store submission is still being connected', $controller);
    }
}
