<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\ProductModel;
use App\Models\SettlementRunModel;
use App\Models\TransactionModel;
use App\Services\SettingService;

class ReportsController extends BaseController
{
    private function csvResponse(string $filename, array $headers, array $rows)
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    public function dailySales()
    {
        $date = $this->request->getGet('date') ?: date('Y-m-d');
        $rows = (new TransactionModel())
            ->select('payment_method, customer_type, COUNT(*) as txn_count, SUM(amount) as total_amount')
            ->where('DATE(created_at)', $date)
            ->groupBy(['payment_method', 'customer_type'])
            ->findAll();

        if ($this->request->getGet('export') === 'csv') {
            $csvRows = array_map(static fn(array $row): array => [
                $row['payment_method'],
                $row['customer_type'],
                $row['txn_count'],
                $row['total_amount'],
            ], $rows);

            return $this->csvResponse('daily-sales-' . $date . '.csv', ['payment_method', 'customer_type', 'transactions', 'total_amount'], $csvRows);
        }

        return view('reports/daily_sales', ['title' => 'Daily Sales', 'rows' => $rows, 'date' => $date]);
    }

    public function debtAging()
    {
        $rows = (new BalanceModel())
            ->select('balances.*, users.name, users.employee_id')
            ->join('users', 'users.id = balances.user_id')
            ->orderBy('current_debt', 'DESC')
            ->findAll();

        if ($this->request->getGet('export') === 'csv') {
            $csvRows = array_map(static fn(array $row): array => [
                $row['employee_id'],
                $row['name'],
                $row['credit_limit'],
                $row['current_debt'],
            ], $rows);

            return $this->csvResponse('debt-aging.csv', ['employee_id', 'name', 'credit_limit', 'current_debt'], $csvRows);
        }

        return view('reports/debt_aging', ['title' => 'Debt Aging', 'rows' => $rows]);
    }

    public function lowStock()
    {
        $settingService = new SettingService();
        $threshold = (int) $settingService->get('low_stock_threshold', '10');
        $rows = (new ProductModel())
            ->select('products.*, stores.store_name')
            ->join('stores', 'stores.id = products.store_id')
            ->where('products.stock_qty <=', $threshold)
            ->orderBy('products.stock_qty', 'ASC')
            ->findAll();

        if ($this->request->getGet('export') === 'csv') {
            $csvRows = array_map(static fn(array $row): array => [
                $row['store_name'],
                $row['sku'],
                $row['name'],
                $row['category'] ?? 'General',
                $row['stock_qty'],
                $row['price'],
            ], $rows);

            return $this->csvResponse('low-stock.csv', ['store', 'sku', 'name', 'category', 'stock_qty', 'price'], $csvRows);
        }

        return view('reports/low_stock', [
            'title' => 'Low Stock',
            'rows' => $rows,
            'threshold' => $threshold,
        ]);
    }

    public function settlementHistory()
    {
        $rows = (new SettlementRunModel())->orderBy('id', 'DESC')->findAll();

        if ($this->request->getGet('export') === 'csv') {
            $csvRows = array_map(static fn(array $row): array => [
                $row['id'],
                $row['run_month'],
                $row['run_at'],
                $row['total_accounts'],
                $row['total_debt_before'],
            ], $rows);

            return $this->csvResponse('settlement-history.csv', ['id', 'run_month', 'run_at', 'total_accounts', 'total_debt_before'], $csvRows);
        }

        return view('reports/settlement_history', ['title' => 'Settlement History', 'rows' => $rows]);
    }

    public function auditTrail()
    {
        $rows = (new AuditLogModel())->orderBy('id', 'DESC')->findAll(500);

        if ($this->request->getGet('export') === 'csv') {
            $csvRows = array_map(static fn(array $row): array => [
                $row['id'],
                $row['actor_id'],
                $row['action'],
                $row['entity'],
                $row['entity_id'],
                $row['created_at'],
            ], $rows);

            return $this->csvResponse('audit-trail.csv', ['id', 'actor_id', 'action', 'entity', 'entity_id', 'created_at'], $csvRows);
        }

        return view('reports/audit_trail', ['title' => 'Audit Trail', 'rows' => $rows]);
    }
}
