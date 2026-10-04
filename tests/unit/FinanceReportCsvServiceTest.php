<?php

use App\Services\FinanceReportCsvService;
use PHPUnit\Framework\TestCase;

/** @internal */
final class FinanceReportCsvServiceTest extends TestCase
{
    public function testExportPreservesVisibleCentsSectionsReferencesAndSpreadsheetSafety(): void
    {
        $report = [
            'from_date' => '2026-09-23', 'to_date' => '2026-09-23', 'store_id' => 1,
            'stores' => [['id' => 1, 'name' => '=Unsafe Store', 'transactions' => 1, 'sales_cents' => 10005,
                'cash_cents' => 4000, 'debt_cents' => 6005, 'other_cents' => 0]],
            'totals' => ['transactions' => 1, 'sales_cents' => 10005, 'cash_cents' => 4000, 'debt_cents' => 6005, 'other_cents' => 0],
            'customer_types' => [['customer_label' => 'Staff', 'audience' => 'internal', 'transactions' => 1, 'sales_cents' => 10005]],
            'products' => [['store_id' => 1, 'product_id' => 7, 'product_name' => 'Notebook', 'product_label' => 'Notebook · A5', 'quantity' => 2, 'revenue_cents' => 10005]],
            'employee_purchases' => [['employee_name' => 'Employee One', 'employee_id' => 'E-1', 'transactions' => 1, 'sales_cents' => 10005]],
            'charges' => [['store_name' => '=Unsafe Store', 'created_at' => '2026-09-23 01:00:00', 'department_name' => null,
                'employee_name' => 'Employee One', 'employee_id' => 'E-1', 'client_txn_id' => 'TX-1', 'charge_cents' => 6005]],
        ];
        $deductions = ['count' => 1, 'total_cents' => 3000, 'rows' => [[
            'recorded_at' => '2026-09-23 02:00:00', 'employee_name' => 'Employee One', 'employee_id' => 'E-1',
            'period_code' => '2026-09-A', 'reference' => 'PAY-1', 'source' => 'Confirmed payroll batch', 'amount_cents' => 3000,
        ]]];
        $waste = ['total_quantity' => 2, 'unit_totals' => ['portions' => 2], 'rows' => [[
            'store_name' => '=Unsafe Store', 'business_date' => '2026-09-23', 'item_name' => 'Rice meal',
            'source' => 'Shared batch', 'disposition' => 'spoiled', 'quantity' => 2, 'unit' => 'portions',
        ]]];
        $csv = (new FinanceReportCsvService())->render($report, $deductions, $waste, [['id' => 1, 'store_name' => '=Unsafe Store']]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[$row[0]][] = $row;
            $this->assertCount(14, $row);
        }
        fclose($stream);
        $this->assertSame("'=Unsafe Store", $rows['Sales by store'][0][1]);
        $this->assertSame('100.05', $rows['Sales total'][0][9]);
        $this->assertSame('40.00', $rows['Sales total'][0][10]);
        $this->assertSame('60.05', $rows['Sales total'][0][11]);
        $this->assertSame('60.05', $rows['Debt / charge transactions'][0][11]);
        $this->assertSame('Notebook · A5', $rows['Products sold'][0][3]);
        $this->assertSame('2026-09-A · PAY-1', $rows['Confirmed deductions'][0][4]);
        $this->assertSame('30.00', $rows['Deductions total'][0][13]);
        $this->assertSame('2', $rows['Waste subtotal'][0][7]);
        $this->assertSame('portions', $rows['Waste subtotal'][0][8]);
    }

    public function testExportRouteUsesTheSameFinanceReadPolicy(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("finance/reports/export', 'FinanceReportController::export', ['filter' => 'access:finance.reports']", $routes);
    }
}
