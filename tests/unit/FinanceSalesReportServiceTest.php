<?php

use App\Services\FinanceSalesReportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class FinanceSalesReportServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $tn = static fn (string $table): string => $db->getPrefix() . $table;
        foreach (['department_debt_transactions', 'departments', 'transaction_items', 'transaction_payments', 'transactions', 'products', 'users', 'stores'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('stores') . ' (id INTEGER PRIMARY KEY, store_name TEXT)');
        $db->query('CREATE TABLE ' . $tn('users') . ' (id INTEGER PRIMARY KEY, name TEXT, employee_id TEXT)');
        $db->query('CREATE TABLE ' . $tn('products') . ' (id INTEGER PRIMARY KEY, name TEXT)');
        $db->query('CREATE TABLE ' . $tn('transactions') . ' (id INTEGER PRIMARY KEY, client_txn_id TEXT, created_at TEXT, store_id INTEGER, user_id INTEGER, customer_type TEXT, amount REAL, payment_method TEXT, status TEXT)');
        $db->query('CREATE TABLE ' . $tn('transaction_payments') . ' (id INTEGER PRIMARY KEY, transaction_id INTEGER, payment_method TEXT, amount REAL)');
        $db->query('CREATE TABLE ' . $tn('transaction_items') . ' (id INTEGER PRIMARY KEY, transaction_id INTEGER, product_id INTEGER, item_name_snapshot TEXT, variant_snapshot TEXT, qty INTEGER, line_total REAL)');
        $db->query('CREATE TABLE ' . $tn('department_debt_transactions') . ' (transaction_id INTEGER PRIMARY KEY, department_id INTEGER)');
        $db->query('CREATE TABLE ' . $tn('departments') . ' (id INTEGER PRIMARY KEY, name TEXT)');
        $db->table('stores')->insertBatch([
            ['id' => 1, 'store_name' => 'Main'], ['id' => 2, 'store_name' => 'Annex'],
        ]);
        $db->table('users')->insert(['id' => 9, 'name' => 'Employee Nine', 'employee_id' => 'E-9']);
        $db->table('products')->insert(['id' => 7, 'name' => 'Notebook']);
        $db->table('departments')->insert(['id' => 3, 'name' => 'Registrar']);
        $db->table('transactions')->insertBatch([
            ['id' => 1, 'client_txn_id' => 'T1', 'created_at' => '2026-09-23 01:00:00', 'store_id' => 1, 'user_id' => 9, 'customer_type' => 'staff', 'amount' => 100, 'payment_method' => 'cash', 'status' => 'completed'],
            ['id' => 2, 'client_txn_id' => 'T2', 'created_at' => '2026-09-23 02:00:00', 'store_id' => 1, 'user_id' => 9, 'customer_type' => 'staff', 'amount' => 100, 'payment_method' => 'cash', 'status' => 'completed'],
            ['id' => 3, 'client_txn_id' => 'T3', 'created_at' => '2026-09-23 03:00:00', 'store_id' => 1, 'user_id' => 9, 'customer_type' => 'staff', 'amount' => 50, 'payment_method' => 'debt', 'status' => 'pending'],
            ['id' => 4, 'client_txn_id' => 'T4', 'created_at' => '2026-09-23 04:00:00', 'store_id' => 2, 'user_id' => null, 'customer_type' => 'walk_in', 'amount' => 30, 'payment_method' => 'card', 'status' => 'completed'],
            ['id' => 5, 'client_txn_id' => 'T5', 'created_at' => '2026-09-22 03:00:00', 'store_id' => 2, 'user_id' => 9, 'customer_type' => 'staff', 'amount' => 20, 'payment_method' => 'debt', 'status' => 'completed'],
        ]);
        $db->table('transaction_payments')->insertBatch([
            ['id' => 1, 'transaction_id' => 2, 'payment_method' => 'cash', 'amount' => 60],
            ['id' => 2, 'transaction_id' => 2, 'payment_method' => 'debt', 'amount' => 40],
        ]);
        $db->table('transaction_items')->insertBatch([
            ['id' => 1, 'transaction_id' => 1, 'product_id' => 7, 'item_name_snapshot' => 'Notebook', 'qty' => 2, 'line_total' => 100],
            ['id' => 2, 'transaction_id' => 2, 'product_id' => 7, 'item_name_snapshot' => 'Notebook', 'qty' => 1, 'line_total' => 100],
            ['id' => 3, 'transaction_id' => 3, 'product_id' => 7, 'item_name_snapshot' => null, 'qty' => 1, 'line_total' => 50],
        ]);
    }

    public function testDailyReportReconcilesStoresSplitChargesProductsAndCustomerTypes(): void
    {
        $report = (new FinanceSalesReportService())->build('2026-09-23', '2026-09-23');
        $this->assertSame(3, $report['totals']['transactions']);
        $this->assertSame(23000, $report['totals']['sales_cents']);
        $this->assertSame(16000, $report['totals']['cash_cents']);
        $this->assertSame(4000, $report['totals']['debt_cents']);
        $this->assertSame(3000, $report['totals']['other_cents']);
        $this->assertCount(1, $report['charges']);
        $this->assertSame(4000, $report['charges'][0]['charge_cents']);
        $this->assertSame('E-9', $report['charges'][0]['employee_id']);
        $this->assertSame(3, (int) $report['products'][0]['quantity']);
        $this->assertSame(20000, $report['products'][0]['revenue_cents']);
        $this->assertSame(['internal', 'external'], array_column($report['customer_types'], 'audience'));
        $this->assertCount(1, $report['employee_purchases']);
        $this->assertSame(20000, $report['employee_purchases'][0]['sales_cents']);
        $this->assertSame(2, (int) $report['employee_purchases'][0]['transactions']);
    }

    public function testStoreAndDateFiltersExcludeOtherSales(): void
    {
        $report = (new FinanceSalesReportService())->build('2026-09-22', '2026-09-23', 2);
        $this->assertSame(2, $report['totals']['transactions']);
        $this->assertSame(5000, $report['totals']['sales_cents']);
        $this->assertSame(2000, $report['totals']['debt_cents']);
        $this->assertCount(1, $report['charges']);
        $this->assertSame('T5', $report['charges'][0]['client_txn_id']);
    }

    public function testProductReportKeepsTheNameRecordedAtSaleAfterCatalogChanges(): void
    {
        $db = Database::connect();
        $db->table('products')->where('id', 7)->update(['name' => 'Exercise Book']);
        $renamed = (new FinanceSalesReportService())->build('2026-09-23', '2026-09-23');
        $this->assertSame('Notebook', $renamed['products'][0]['product_name']);

        $db->table('products')->where('id', 7)->delete();
        $deleted = (new FinanceSalesReportService())->build('2026-09-23', '2026-09-23');
        $this->assertSame('Notebook', $deleted['products'][0]['product_name']);
        $this->assertSame(20000, $deleted['products'][0]['revenue_cents']);
    }

    public function testProductReportKeepsHistoricalVariantsDistinct(): void
    {
        $db = Database::connect();
        $db->table('transaction_items')->where('id', 1)->update(['variant_snapshot' => 'A5']);
        $db->table('transaction_items')->where('id', 2)->update(['variant_snapshot' => 'A4']);

        $report = (new FinanceSalesReportService())->build('2026-09-23', '2026-09-23');
        $this->assertCount(2, $report['products']);
        $labels = array_column($report['products'], 'product_label');
        sort($labels);
        $this->assertSame(['Notebook · A4', 'Notebook · A5'], $labels);
        $this->assertSame(20000, array_sum(array_column($report['products'], 'revenue_cents')));
    }

    public function testDepartmentDebtWalkInIsReportedAsInternal(): void
    {
        $db = Database::connect();
        $db->table('transactions')->insert(['id' => 6, 'client_txn_id' => 'DEPT-6', 'created_at' => '2026-09-23 05:00:00', 'store_id' => 2, 'user_id' => null, 'customer_type' => 'walk_in', 'amount' => 50, 'payment_method' => 'debt', 'status' => 'completed']);
        $db->table('department_debt_transactions')->insert(['transaction_id' => 6, 'department_id' => 3]);
        $report = (new FinanceSalesReportService())->build('2026-09-23', '2026-09-23');
        $walkIns = array_values(array_filter($report['customer_types'], static fn (array $row): bool => $row['customer_type'] === 'walk_in'));
        $this->assertCount(2, $walkIns);
        $this->assertSame(['external', 'internal'], array_column($walkIns, 'audience'));
        $this->assertSame([3000, 5000], array_column($walkIns, 'sales_cents'));
        $this->assertSame('Department purchase', $walkIns[1]['customer_label']);
        $departmentCharge = array_values(array_filter($report['charges'], static fn (array $row): bool => $row['client_txn_id'] === 'DEPT-6'));
        $this->assertSame('Registrar', $departmentCharge[0]['department_name']);
    }

    public function testInvalidRangeAndUnknownStoreFailClosed(): void
    {
        $service = new FinanceSalesReportService();
        foreach ([['2026-02-30', '2026-03-01', 0], ['2026-09-24', '2026-09-23', 0], ['2026-09-23', '2026-09-23', 99]] as $input) {
            try {
                $service->build(...$input);
                $this->fail('Invalid report filter should have been rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
