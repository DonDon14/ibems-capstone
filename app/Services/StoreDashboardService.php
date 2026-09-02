<?php

namespace App\Services;

use App\Models\StoreDaySessionModel;
use App\Models\StoreModel;
use Config\Database;

final class StoreDashboardService
{
    /** @return array<string, mixed> */
    public function data(int $userId, string $role, string $requestedPeriod = 'day'): array
    {
        $period = DashboardPeriod::resolve($requestedPeriod);
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);
        $activeStore = $stores[0] ?? null;
        $storeId = (int) ($activeStore['id'] ?? 0);

        $summary = [
            'store_count' => count($stores),
            'today_sales' => 0.0,
            'today_transactions' => 0,
            'active_products' => 0,
            'low_stock' => 0,
        ];
        $readiness = [
            'is_opened' => false,
            'business_date' => ibems_business_date(),
            'opening_balance' => 0.0,
            'expected_cash_on_hand' => 0.0,
            'expected_ecash_on_hand' => 0.0,
            'cash_sales' => 0.0,
            'ecash_sales' => 0.0,
            'debt_sales' => 0.0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'ecash_in' => 0.0,
            'ecash_out' => 0.0,
        ];
        $lowStockProducts = [];
        $recentTransactions = [];
        $paymentBreakdown = [];

        if ($storeId > 0) {
            $db = Database::connect();
            $hasPaymentLines = in_array('transaction_payments', $db->listTables(), true);
            $paymentMethodSql = $hasPaymentLines ? 'COALESCE(tp.payment_method, t.payment_method)' : 't.payment_method';
            $paymentAmountSql = $hasPaymentLines ? 'COALESCE(tp.amount, t.amount)' : 't.amount';
            $businessZone = new \DateTimeZone('Asia/Manila');
            $storageZone = new \DateTimeZone('UTC');
            $businessNow = new \DateTimeImmutable('now', $businessZone);
            $todayDate = $businessNow->format('Y-m-d');
            $todayStart = $businessNow->setTime(0, 0)->setTimezone($storageZone)->format('Y-m-d H:i:s');
            $todayEnd = $businessNow->setTime(23, 59, 59)->setTimezone($storageZone)->format('Y-m-d H:i:s');
            $periodStart = $period['start'];
            $periodEnd = $period['end'];

            $txn = $db->table('transactions')
                ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS total_sales')
                ->where('store_id', $storeId)
                ->where('created_at >=', $periodStart)
                ->where('created_at <=', $periodEnd)
                ->get()
                ->getRowArray();

            $products = $db->table('products')
                ->select('COUNT(*) AS active_products, COALESCE(SUM(CASE WHEN stock_qty <= COALESCE(low_stock_threshold, 10) THEN 1 ELSE 0 END), 0) AS low_stock')
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->get()
                ->getRowArray();

            $summary['today_sales'] = (float) ($txn['total_sales'] ?? 0);
            $summary['today_transactions'] = (int) ($txn['txn_count'] ?? 0);
            $summary['active_products'] = (int) ($products['active_products'] ?? 0);
            $summary['low_stock'] = (int) ($products['low_stock'] ?? 0);

            $sessionModel = new StoreDaySessionModel();
            $daySession = $sessionModel->getByStoreAndDate($storeId, $todayDate);
            $sessionStartTs = $todayStart;

            $asOfPaymentBuilder = $db->table('transactions t')
                ->select($paymentMethodSql . ' AS payment_method, COUNT(DISTINCT t.id) AS txn_count, COALESCE(SUM(' . $paymentAmountSql . '), 0) AS total_sales', false);
            if ($hasPaymentLines) {
                $asOfPaymentBuilder->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left');
            }
            $asOfPaymentRows = $asOfPaymentBuilder
                ->where('t.store_id', $storeId)
                ->where('t.created_at >=', $sessionStartTs)
                ->where('t.created_at <=', $todayEnd)
                ->groupBy($paymentMethodSql, false)
                ->orderBy('total_sales', 'DESC')
                ->get()
                ->getResultArray();

            $cashSales = 0.0;
            $ecashSales = 0.0;
            $debtSales = 0.0;
            foreach ($asOfPaymentRows as $row) {
                $method = strtolower((string) ($row['payment_method'] ?? ''));
                $sales = (float) ($row['total_sales'] ?? 0);
                if ($method === 'cash') {
                    $cashSales += $sales;
                } elseif ($method === 'debt') {
                    $debtSales += $sales;
                } else {
                    $ecashSales += $sales;
                }
            }

            $todayPaymentBuilder = $db->table('transactions t')
                ->select($paymentMethodSql . ' AS payment_method, COUNT(DISTINCT t.id) AS txn_count, COALESCE(SUM(' . $paymentAmountSql . '), 0) AS total_sales', false);
            if ($hasPaymentLines) {
                $todayPaymentBuilder->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left');
            }
            $todayPaymentRows = $todayPaymentBuilder
                ->where('t.store_id', $storeId)
                ->where('t.created_at >=', $periodStart)
                ->where('t.created_at <=', $periodEnd)
                ->groupBy($paymentMethodSql, false)
                ->orderBy('total_sales', 'DESC')
                ->get()
                ->getResultArray();

            $paymentBreakdown = array_map(static function (array $row): array {
                return [
                    'method' => (string) ($row['payment_method'] ?? 'unknown'),
                    'transactions' => (int) ($row['txn_count'] ?? 0),
                    'sales' => (float) ($row['total_sales'] ?? 0),
                ];
            }, $todayPaymentRows);

            $movementRows = $db->table('store_cash_movements')
                ->select('channel, movement_type, COALESCE(SUM(amount), 0) AS total_amount')
                ->where('store_id', $storeId)
                ->where('business_date >=', $todayDate)
                ->where('business_date <=', $todayDate)
                ->groupBy('channel, movement_type')
                ->get()
                ->getResultArray();

            $cashIn = 0.0;
            $cashOut = 0.0;
            $ecashIn = 0.0;
            $ecashOut = 0.0;
            foreach ($movementRows as $row) {
                $channel = strtolower((string) ($row['channel'] ?? 'cash'));
                $type = strtolower((string) ($row['movement_type'] ?? 'cash_in'));
                $amount = (float) ($row['total_amount'] ?? 0);
                if ($channel === 'ecash') {
                    if ($type === 'cash_out') {
                        $ecashOut += $amount;
                    } else {
                        $ecashIn += $amount;
                    }
                    continue;
                }

                if ($type === 'cash_out') {
                    $cashOut += $amount;
                } else {
                    $cashIn += $amount;
                }
            }

            $readiness = [
                'is_opened' => $daySession !== null && (string) ($daySession['status'] ?? '') === 'open',
                'is_closed' => $daySession !== null && (string) ($daySession['status'] ?? '') === 'closed',
                'business_date' => $todayDate,
                'opening_balance' => (float) ($daySession['opening_cash'] ?? 0),
                'opening_cash' => (float) ($daySession['opening_cash'] ?? 0),
                'opening_ecash' => (float) ($daySession['opening_ecash'] ?? 0),
                'expected_cash_on_hand' => (float) ($daySession['opening_cash'] ?? 0) + $cashSales + $cashIn - $cashOut,
                'expected_ecash_on_hand' => (float) ($daySession['opening_ecash'] ?? 0) + $ecashSales + $ecashIn - $ecashOut,
                'cash_sales' => $cashSales,
                'ecash_sales' => $ecashSales,
                'debt_sales' => $debtSales,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'ecash_in' => $ecashIn,
                'ecash_out' => $ecashOut,
            ];

            $lowStockProducts = $db->table('products')
                ->select('id, name, sku, category, stock_qty, low_stock_threshold')
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->where('stock_qty <= COALESCE(low_stock_threshold, 10)', null, false)
                ->orderBy('stock_qty', 'ASC')
                ->orderBy('name', 'ASC')
                ->limit(5)
                ->get()
                ->getResultArray();

            $recentTransactions = $db->table('transactions t')
                ->select('t.id, t.client_txn_id, t.amount, t.payment_method, t.customer_type, t.created_at, u.name AS customer_name, u.profile_image_url AS customer_profile_image_url')
                ->join('users u', 'u.id = t.user_id', 'left')
                ->where('t.store_id', $storeId)
                ->where('t.created_at >=', $periodStart)
                ->where('t.created_at <=', $periodEnd)
                ->orderBy('t.created_at', 'DESC')
                ->orderBy('t.id', 'DESC')
                ->limit(5)
                ->get()
                ->getResultArray();
        }

        return [
            'period' => DashboardPeriod::publicMeta($period),
            'stores' => $stores,
            'activeStore' => $activeStore,
            'summary' => $summary,
            'readiness' => $readiness,
            'lowStockProducts' => $lowStockProducts,
            'recentTransactions' => $recentTransactions,
            'paymentBreakdown' => $paymentBreakdown,
        ];
    }
}
