<?php

use App\Models\StoreCapabilityModel;
use App\Services\DailyStoreReportService;
use App\Services\DeductionBatchService;
use App\Services\DeductionPeriodService;
use App\Services\FinanceDeductionReportService;
use App\Services\FinanceSalesReportService;
use App\Services\StoreDayOperationService;
use App\Services\TransactionService;
use App\Services\UserPurchaseCardService;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** An isolated acceptance path across the actual POS, reporting, and deduction services. @internal */
final class IfrEndToEndScenarioTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDebtSaleFlowsThroughStoreReportAndPartialDeduction(): void
    {
        $databaseConfig = config(Database::class);
        $this->assertSame('tests', $databaseConfig->defaultGroup);
        // This separate process uses the same unprefixed schema as a clean app install.
        $databaseConfig->tests['DBPrefix'] = '';
        $db = Database::connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame('', $db->getPrefix());
        $this->assertTrue((new MigrationRunner(config(Migrations::class), $db))->latest());
        $db->resetDataCache();
        $this->assertTrue($db->fieldExists('waste_disposition', 'inventory_movements'));

        $now = date('Y-m-d H:i:s');
        $date = ibems_business_date();
        foreach ([
            [7, 'Store Cashier', 'STORE_SYSTEM'],
            [20, 'Store Supervisor', 'STORE_SUPERVISOR'],
            [30, 'BE Director', 'BE_DIRECTOR'],
            [40, 'Cashier Head', 'CASHIER_HEAD'],
            [501, 'Faculty Employee', 'USER'],
        ] as [$id, $name, $role]) {
            $db->table('users')->insert([
                'id' => $id, 'employee_id' => 'E' . $id,
                'name' => $name, 'email' => 'user' . $id . '@example.test',
                'password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT),
                'role' => $role, 'user_type' => 'staff', 'qr_token' => 'test-' . $id,
                'base_salary' => 1000.50,
                'is_active' => 1, 'created_at' => $now,
            ]);
        }
        $db->table('users')->where('id', 501)->update(['debt_pin_hash' => password_hash('1234', PASSWORD_BCRYPT)]);
        $roleForeignKeys = $db->query('PRAGMA foreign_key_list(user_roles)')->getResultArray();
        $this->assertContains('users', array_column($roleForeignKeys, 'table'));
        $this->assertSame([], $db->query('PRAGMA foreign_key_check')->getResultArray());
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 500, 'current_debt' => 0, 'updated_at' => $now]);
        $db->table('stores')->insert(['id' => 1, 'store_name' => 'Acceptance Store', 'officer_id' => 7, 'is_active' => 1, 'created_at' => $now]);
        $db->table('store_supervisors')->insert(['store_id' => 1, 'user_id' => 20]);
        $productForeignKeys = $db->query('PRAGMA foreign_key_list(products)')->getResultArray();
        $this->assertContains('stores', array_column($productForeignKeys, 'table'), json_encode($productForeignKeys));
        $itemForeignKeys = $db->query('PRAGMA foreign_key_list(transaction_items)')->getResultArray();
        $this->assertContains('products', array_column($itemForeignKeys, 'table'), json_encode($itemForeignKeys));
        $db->table('products')->insert([
            'id' => 101, 'store_id' => 1, 'sku' => 'ACCEPT-101', 'name' => 'Notebook', 'variant_label' => 'A5',
            'price' => 100, 'stock_qty' => 5, 'is_active' => 1, 'updated_at' => $now,
        ]);
        (new StoreCapabilityModel())->syncForStore(1, ['retail']);

        $storeDay = new StoreDayOperationService();
        $opened = $storeDay->open(['store_id' => 1, 'opening_cash' => 0, 'opening_ecash' => 0], 7, 'STORE_SYSTEM');
        $this->assertSame('success', $opened['payload']['status'] ?? null, json_encode($opened));
        $unlocked = (new UserPurchaseCardService($db))->unlock(501);
        $this->assertSame('success', $unlocked['status'], json_encode($unlocked));
        $sale = (new TransactionService())->createTransaction([
            'store_id' => 1, 'payment_method' => 'debt', 'customer_user_id' => 501,
            'debt_pin' => '1234', 'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');
        $this->assertSame('success', $sale['status'], json_encode($sale));
        $this->assertSame(100.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $this->assertSame(4, (int) $db->table('products')->where('id', 101)->get()->getRow()->stock_qty);
        $db->table('products')->where('id', 101)->update(['name' => 'Exercise Book', 'variant_label' => 'A4']);

        $closed = $storeDay->close(['store_id' => 1, 'counted_cash' => 0, 'counted_ecash' => 0], 7, 'STORE_SYSTEM');
        $this->assertSame('success', $closed['payload']['status'] ?? null, json_encode($closed));
        $sessionId = (int) $db->table('store_day_sessions')->where('store_id', 1)->get()->getRow()->id;
        $handoff = new DailyStoreReportService($db);
        $submitted = $handoff->submit($sessionId, 20, 'STORE_SUPERVISOR', 'Test-only closed day');
        $this->assertSame('success', $submitted['status'], json_encode($submitted));
        $report = $submitted['report'];
        $this->assertSame(100.0, (float) $report['sales_total']);
        $this->assertSame(100.0, (float) $report['debt_total']);
        $this->assertSame(0.0, (float) $report['cash_total']);
        $reportId = (int) $report['id'];
        $this->assertSame('be_approved', $handoff->review($reportId, 30, true, 'Reconciled', 'BE_DIRECTOR')['report']['status']);
        $received = $handoff->receive($reportId, 40, 'CASHIER_HEAD');
        $this->assertSame('cashier_received', $received['report']['status']);
        $this->assertNotEmpty($received['report']['accounting_furnished_at']);

        $period = (new DeductionPeriodService())->create([
            'frequency' => 'custom', 'date_start' => $date, 'date_end' => $date,
        ], 40);
        $this->assertSame('success', $period['status'], json_encode($period));
        $deductions = new DeductionBatchService();
        $prepared = $deductions->prepare((int) $period['period']['id'], [
            ['user_id' => 501, 'deduction_choice' => 'full', 'requested_amount' => 100],
        ], 40);
        $this->assertSame('success', $prepared['status'], json_encode($prepared));
        $batchId = (int) $prepared['batch']['id'];
        $this->assertSame('submitted', $deductions->submit($batchId, 40)['batch']['status']);
        $this->assertSame(100.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $itemId = (int) $db->table('deduction_batch_items')->where('batch_id', $batchId)->get()->getRow()->id;
        $confirmed = $deductions->confirmResult($itemId, [
            'confirmed_amount' => 60, 'reason_code' => 'insufficient_salary',
            'result_reference' => 'ACCEPTANCE-PAY-501',
        ], 40);
        $this->assertSame('success', $confirmed['status'], json_encode($confirmed));
        $this->assertSame(40.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $this->assertSame('reconciled', $deductions->reconcile($batchId, 40)['batch']['status']);
        $this->assertSame('finalized', $deductions->finalize($batchId, 40)['batch']['status']);

        $sales = (new FinanceSalesReportService($db))->build($date, $date, 1);
        $this->assertSame(10000, $sales['totals']['sales_cents']);
        $this->assertSame(10000, $sales['totals']['debt_cents']);
        $this->assertSame('Notebook · A5', $sales['products'][0]['product_label']);
        $deductionReport = (new FinanceDeductionReportService($db))->build($date, $date);
        $this->assertSame(6000, $deductionReport['total_cents']);
        $this->assertSame(1, $deductionReport['count']);
        $this->assertSame(1, $db->table('debt_cashbook_entries')->where('entry_type', 'confirmed_salary_deduction')->countAllResults());

        $this->withRoutes([
            ['GET', 'test/finance-report-page', 'FinanceReportController::index'],
            ['GET', 'test/finance-report-export', 'FinanceReportController::export'],
        ]);
        $query = '?period=custom&from_date=' . $date . '&to_date=' . $date . '&store_id=1';
        $session = ['logged_in' => true, 'user_id' => 40, 'role' => 'CASHIER_HEAD', 'available_roles' => ['CASHIER_HEAD']];
        $page = $this->withSession($session)->get('test/finance-report-page' . $query);
        $page->assertOK();
        $this->assertStringContainsString('Download this report (CSV)', (string) $page->getBody());
        $this->assertStringContainsString('100.00', (string) $page->getBody());
        $this->assertStringContainsString('Notebook &middot; A5', (string) $page->getBody());
        $this->assertStringContainsString('period=custom&amp;from_date=' . $date, (string) $page->getBody());
        $export = $this->withSession($session)->get('test/finance-report-export' . $query);
        $export->assertOK();
        $this->assertStringContainsString('text/csv', $export->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment;', $export->response()->getHeaderLine('Content-Disposition'));
        $csv = (string) $export->response()->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $sections = [];
        while (($csvRow = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $sections[$csvRow[0]][] = $csvRow;
        }
        fclose($stream);
        $this->assertSame('100.00', $sections['Sales total'][0][9]);
        $this->assertSame('100.00', $sections['Sales total'][0][11]);
        $this->assertSame('Notebook · A5', $sections['Products sold'][0][3]);
        $this->assertSame('60.00', $sections['Deductions total'][0][13]);
        $invalid = $this->withSession($session)->get('test/finance-report-export?period=custom&from_date=2026-02-30&to_date=' . $date . '&store_id=1');
        $this->assertSame(400, $invalid->response()->getStatusCode());
        $this->assertSame('', $invalid->response()->getHeaderLine('Content-Disposition'));

        foreach ([
            ['day', '2026-08-18', '2026-08-18'],
            ['week', '2026-08-17', '2026-08-23'],
            ['month', '2026-08-01', '2026-08-31'],
        ] as [$preset, $expectedFrom, $expectedTo]) {
            $url = 'test/finance-report-page?period=' . $preset . '&anchor_date=2026-08-18&store_id=1';
            $presetPage = $this->withSession($session)->get($url);
            $presetPage->assertOK();
            $this->assertStringContainsString('Reporting range: ' . $expectedFrom . ' to ' . $expectedTo, (string) $presetPage->getBody());
            $this->assertStringContainsString('period=custom&amp;from_date=' . $expectedFrom . '&amp;to_date=' . $expectedTo, (string) $presetPage->getBody());
        }
        $badAnchor = $this->withSession($session)->get('test/finance-report-export?period=week&anchor_date=2026-02-30&store_id=1');
        $this->assertSame(400, $badAnchor->response()->getStatusCode());
    }
}
