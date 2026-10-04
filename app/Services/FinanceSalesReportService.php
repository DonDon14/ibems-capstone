<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Read-only, source-reconciled sales and charge reporting for finance offices. */
final class FinanceSalesReportService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array<string,mixed> */
    public function build(string $fromDate, string $toDate, int $storeId = 0): array
    {
        $this->assertDate($fromDate);
        $this->assertDate($toDate);
        if ($fromDate > $toDate || $storeId < 0) {
            throw new InvalidArgumentException('Choose a valid date range and store.');
        }

        $from = ibems_business_day_utc_bounds($fromDate)['start'];
        $to = ibems_business_day_utc_bounds($toDate)['end'];
        $stores = [];
        $storeQuery = $this->db->table('stores')->select('id, store_name')->orderBy('store_name', 'ASC');
        if ($storeId > 0) {
            $storeQuery->where('id', $storeId);
        }
        foreach ($storeQuery->get()->getResultArray() as $store) {
            $id = (int) $store['id'];
            $stores[$id] = [
                'id' => $id,
                'name' => (string) $store['store_name'],
                'transactions' => 0,
                'sales_cents' => 0,
                'cash_cents' => 0,
                'debt_cents' => 0,
                'other_cents' => 0,
            ];
        }
        if ($storeId > 0 && $stores === []) {
            throw new InvalidArgumentException('Store not found.');
        }

        $transactions = $this->scope($this->db->table('transactions t'), $from, $to, $storeId)
            ->select('t.store_id, COUNT(t.id) AS transaction_count, COALESCE(SUM(t.amount), 0) AS sales_amount', false)
            ->groupBy('t.store_id')->get()->getResultArray();
        foreach ($transactions as $row) {
            $id = (int) $row['store_id'];
            if (!isset($stores[$id])) {
                continue;
            }
            $stores[$id]['transactions'] = (int) $row['transaction_count'];
            $stores[$id]['sales_cents'] = $this->cents($row['sales_amount']);
        }

        $methodSql = 'COALESCE(tp.payment_method, t.payment_method)';
        $amountSql = 'COALESCE(tp.amount, t.amount)';
        $payments = $this->scope($this->db->table('transactions t'), $from, $to, $storeId)
            ->select("t.store_id, {$methodSql} AS payment_method, COALESCE(SUM({$amountSql}), 0) AS payment_amount", false)
            ->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left')
            ->groupBy('t.store_id')->groupBy($methodSql, false)->get()->getResultArray();
        foreach ($payments as $row) {
            $id = (int) $row['store_id'];
            if (!isset($stores[$id])) {
                continue;
            }
            $method = strtolower((string) $row['payment_method']);
            $key = $method === 'cash' ? 'cash_cents' : ($method === 'debt' ? 'debt_cents' : 'other_cents');
            $stores[$id][$key] += $this->cents($row['payment_amount']);
        }

        $charges = $this->scope($this->db->table('transactions t'), $from, $to, $storeId)
            ->select("t.id, t.client_txn_id, t.created_at, t.store_id, s.store_name, t.user_id, u.name AS employee_name, u.employee_id, d.name AS department_name, t.customer_type, COALESCE(SUM(CASE WHEN tp.payment_method = 'debt' THEN tp.amount ELSE 0 END), 0) + CASE WHEN COUNT(tp.id) = 0 AND t.payment_method = 'debt' THEN t.amount ELSE 0 END AS charge_amount", false)
            ->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left')
            ->join('stores s', 's.id = t.store_id', 'inner')
            ->join('users u', 'u.id = t.user_id', 'left')
            ->join('department_debt_transactions ddt', 'ddt.transaction_id = t.id', 'left')
            ->join('departments d', 'd.id = ddt.department_id', 'left')
            ->groupBy('t.id, t.client_txn_id, t.created_at, t.store_id, s.store_name, t.user_id, u.name, u.employee_id, d.name, t.customer_type, t.payment_method, t.amount')
            ->orderBy('t.created_at', 'DESC')->get()->getResultArray();
        $charges = array_values(array_filter(array_map(function (array $row): array {
            $row['charge_cents'] = $this->cents($row['charge_amount']);
            unset($row['charge_amount']);
            return $row;
        }, $charges), static fn (array $row): bool => $row['charge_cents'] > 0));

        $productNameSql = "COALESCE(NULLIF(ti.item_name_snapshot, ''), p.name)";
        $variantSql = "COALESCE(NULLIF(ti.variant_snapshot, ''), '')";
        $products = $this->scope($this->db->table('transactions t'), $from, $to, $storeId)
            ->select("t.store_id, ti.product_id, {$productNameSql} AS product_name, {$variantSql} AS variant_label, COALESCE(SUM(ti.qty), 0) AS quantity, COALESCE(SUM(ti.line_total), 0) AS revenue", false)
            ->join('transaction_items ti', 'ti.transaction_id = t.id', 'inner')
            ->join('products p', 'p.id = ti.product_id', 'left')
            ->groupBy('t.store_id, ti.product_id')
            ->groupBy($productNameSql, false)
            ->groupBy($variantSql, false)
            ->orderBy('revenue', 'DESC')->get()->getResultArray();
        foreach ($products as &$product) {
            $product['revenue_cents'] = $this->cents($product['revenue']);
            $name = (string) ($product['product_name'] ?: ('Product #' . $product['product_id']));
            $variant = trim((string) $product['variant_label']);
            $product['product_label'] = $variant === '' ? $name : $name . ' · ' . $variant;
            unset($product['revenue']);
        }
        unset($product);

        $audienceSql = "CASE WHEN t.customer_type = 'walk_in' AND ddt.transaction_id IS NULL THEN 'external' ELSE 'internal' END";
        $customerTypes = $this->scope($this->db->table('transactions t'), $from, $to, $storeId)
            ->select("t.customer_type, {$audienceSql} AS audience, COUNT(t.id) AS transactions, COALESCE(SUM(t.amount), 0) AS sales_amount", false)
            ->join('department_debt_transactions ddt', 'ddt.transaction_id = t.id', 'left')
            ->groupBy('t.customer_type')->groupBy($audienceSql, false)->get()->getResultArray();
        foreach ($customerTypes as &$type) {
            $type['sales_cents'] = $this->cents($type['sales_amount']);
            $type['customer_label'] = $type['customer_type'] === 'walk_in' && $type['audience'] === 'internal'
                ? 'Department purchase' : ucwords(str_replace('_', ' ', (string) $type['customer_type']));
            unset($type['sales_amount']);
        }
        unset($type);

        $employeePurchases = $this->scope($this->db->table('transactions t'), $from, $to, $storeId)
            ->select('t.user_id, u.name AS employee_name, u.employee_id, COUNT(t.id) AS transactions, COALESCE(SUM(t.amount), 0) AS sales_amount', false)
            ->join('users u', 'u.id = t.user_id', 'inner')
            ->whereIn('t.customer_type', ['faculty', 'staff'])
            ->groupBy('t.user_id, u.name, u.employee_id')
            ->orderBy('sales_amount', 'DESC')->get()->getResultArray();
        foreach ($employeePurchases as &$employee) {
            $employee['sales_cents'] = $this->cents($employee['sales_amount']);
            unset($employee['sales_amount']);
        }
        unset($employee);

        $totals = ['transactions' => 0, 'sales_cents' => 0, 'cash_cents' => 0, 'debt_cents' => 0, 'other_cents' => 0];
        foreach ($stores as $store) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $store[$key];
            }
        }
        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'store_id' => $storeId,
            'stores' => array_values($stores),
            'totals' => $totals,
            'charges' => $charges,
            'products' => $products,
            'customer_types' => $customerTypes,
            'employee_purchases' => $employeePurchases,
        ];
    }

    private function scope($builder, string $from, string $to, int $storeId)
    {
        $builder->where('t.status', 'completed')
            ->where('t.created_at >=', $from)
            ->where('t.created_at <=', $to);
        if ($storeId > 0) {
            $builder->where('t.store_id', $storeId);
        }
        return $builder;
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Choose valid dates in YYYY-MM-DD format.');
        }
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
