<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\StoreCategoryModel;
use App\Models\StorePaymentMethodModel;
use App\Models\PaymentDestinationAccountModel;
use App\Models\StoreOpeningBalanceModel;
use App\Models\StoreCashMovementModel;
use App\Models\StoreDaySessionModel;
use App\Models\InventoryMovementModel;
use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Services\StoreAccessService;
use App\Services\StoreDayVarianceCaseService;
use App\Services\StoreDayExpectedService;
use App\Services\AssetStorageService;
use Config\Database;

class StoreController extends BaseController
{
    private function addDebtCashbookEntry(
        int $userId,
        string $entryType,
        float $amount,
        float $debtBefore,
        float $debtAfter,
        float $creditLimit,
        ?int $actorId = null,
        ?string $remarks = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = []
    ): void {
        $cashbookModel = new DebtCashbookEntryModel();
        $cashbookModel->insert([
            'user_id' => $userId,
            'entry_type' => $entryType,
            'direction' => $debtAfter >= $debtBefore ? 'debit' : 'credit',
            'amount' => abs($amount),
            'debt_before' => $debtBefore,
            'debt_after' => $debtAfter,
            'credit_limit_snapshot' => $creditLimit,
            'available_credit_snapshot' => max(0, $creditLimit - $debtAfter),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actorId,
            'remarks' => $remarks,
            'meta_json' => $meta !== [] ? json_encode($meta) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function dashboard()
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
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
            'business_date' => date('Y-m-d'),
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

            $txn = $db->table('transactions')
                ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS total_sales')
                ->where('store_id', $storeId)
                ->where('created_at >=', $todayStart)
                ->where('created_at <=', $todayEnd)
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
                ->where('t.created_at >=', $todayStart)
                ->where('t.created_at <=', $todayEnd)
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
                ->select('t.id, t.client_txn_id, t.amount, t.payment_method, t.customer_type, t.created_at, u.name AS customer_name')
                ->join('users u', 'u.id = t.user_id', 'left')
                ->where('t.store_id', $storeId)
                ->orderBy('t.created_at', 'DESC')
                ->orderBy('t.id', 'DESC')
                ->limit(5)
                ->get()
                ->getResultArray();
        }

        return view('store/dashboard', [
            'stores' => $stores,
            'activeStore' => $activeStore,
            'summary' => $summary,
            'readiness' => $readiness,
            'lowStockProducts' => $lowStockProducts,
            'recentTransactions' => $recentTransactions,
            'paymentBreakdown' => $paymentBreakdown,
        ]);
    }

    public function pos()
    {
        return view('store/pos');
    }

    public function history()
    {
        return view('store/history');
    }

    public function inventory()
    {
        return view('store/inventory');
    }

    public function staffRecords()
    {
        return view('store/staff-records');
    }

    public function reports()
    {
        return view('store/reports');
    }

    public function receiptPage(int $transactionId)
    {
        return view('store/receipt', [
            'transaction_id' => $transactionId,
        ]);
    }

    public function settings()
    {
        return view('store/settings');
    }

    public function reportSummary()
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);

        if ($stores === []) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'No accessible store found.',
            ]);
        }

        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }

        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $period = strtolower(trim((string) ($this->request->getGet('period') ?? 'month')));
        if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
            $period = 'month';
        }

        $dateFromInput = trim((string) $this->request->getGet('date_from'));
        $dateToInput = trim((string) $this->request->getGet('date_to'));
        $today = new \DateTimeImmutable('now');

        switch ($period) {
            case 'today':
                $fromDate = $today->format('Y-m-d');
                $toDate = $today->format('Y-m-d');
                break;
            case 'week':
                $fromDate = $today->modify('monday this week')->format('Y-m-d');
                $toDate = $today->modify('sunday this week')->format('Y-m-d');
                break;
            case 'custom':
                if (
                    !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateFromInput) ||
                    !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateToInput)
                ) {
                    return $this->response->setStatusCode(400)->setJSON([
                        'status' => 'error',
                        'message' => 'Custom range requires date_from and date_to in YYYY-MM-DD format.',
                    ]);
                }
                $fromDate = $dateFromInput;
                $toDate = $dateToInput;
                break;
            case 'month':
            default:
                $fromDate = $today->modify('first day of this month')->format('Y-m-d');
                $toDate = $today->modify('last day of this month')->format('Y-m-d');
                break;
        }

        if ($fromDate > $toDate) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'date_from cannot be later than date_to.',
            ]);
        }

        $fromTs = $fromDate . ' 00:00:00';
        $toTs = $toDate . ' 23:59:59';

        $storeName = 'Store';
        foreach ($stores as $store) {
            if ((int) ($store['id'] ?? 0) === $storeId) {
                $storeName = (string) ($store['store_name'] ?? $storeName);
                break;
            }
        }

        $db = Database::connect();
        $hasPaymentLines = in_array('transaction_payments', $db->listTables(), true);
        $paymentMethodSql = $hasPaymentLines ? 'COALESCE(tp.payment_method, t.payment_method)' : 't.payment_method';
        $paymentAmountSql = $hasPaymentLines ? 'COALESCE(tp.amount, t.amount)' : 't.amount';

        $txnAgg = $db->table('transactions t')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(t.amount), 0) AS total_sales')
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $fromTs)
            ->where('t.created_at <=', $toTs)
            ->get()
            ->getRowArray();

        $itemsAgg = $db->table('transaction_items ti')
            ->select('COALESCE(SUM(ti.qty), 0) AS items_sold')
            ->join('transactions t', 't.id = ti.transaction_id', 'inner')
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $fromTs)
            ->where('t.created_at <=', $toTs)
            ->get()
            ->getRowArray();

        $paymentBuilder = $db->table('transactions t')
            ->select($paymentMethodSql . ' AS payment_method, COUNT(DISTINCT t.id) AS txn_count, COALESCE(SUM(' . $paymentAmountSql . '), 0) AS total_sales', false);
        if ($hasPaymentLines) {
            $paymentBuilder->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left');
        }
        $paymentRows = $paymentBuilder
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $fromTs)
            ->where('t.created_at <=', $toTs)
            ->groupBy($paymentMethodSql, false)
            ->orderBy('total_sales', 'DESC')
            ->get()
            ->getResultArray();

        $costRows = $db->table('inventory_movements im')
            ->select('im.product_id, COALESCE(SUM(im.total_cost), 0) AS total_cost, COALESCE(SUM(im.qty), 0) AS total_qty')
            ->where('im.store_id', $storeId)
            ->where('im.type', 'restock')
            ->where('im.qty >', 0)
            ->where('im.created_at <=', $toTs)
            ->groupBy('im.product_id')
            ->get()
            ->getResultArray();

        $avgCostByProduct = [];
        foreach ($costRows as $row) {
            $qty = (float) ($row['total_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $avgCostByProduct[(int) $row['product_id']] = (float) $row['total_cost'] / $qty;
        }

        $productRows = $db->table('transaction_items ti')
            ->select('ti.product_id, p.name AS product_name, p.sku, p.category, COALESCE(SUM(ti.qty), 0) AS qty_sold, COALESCE(SUM(ti.line_total), 0) AS revenue')
            ->join('transactions t', 't.id = ti.transaction_id', 'inner')
            ->join('products p', 'p.id = ti.product_id', 'left')
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $fromTs)
            ->where('t.created_at <=', $toTs)
            ->groupBy('ti.product_id, p.name, p.sku, p.category')
            ->orderBy('revenue', 'DESC')
            ->get()
            ->getResultArray();

        $topProducts = [];
        $estimatedCost = 0.0;
        foreach ($productRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qtySold = (float) ($row['qty_sold'] ?? 0);
            $revenue = (float) ($row['revenue'] ?? 0);
            $avgCost = (float) ($avgCostByProduct[$productId] ?? 0);
            $productCost = $qtySold * $avgCost;
            $productProfit = $revenue - $productCost;
            $estimatedCost += $productCost;

            $topProducts[] = [
                'product_id' => $productId,
                'name' => $row['product_name'] ?: ('Product #' . $productId),
                'sku' => $row['sku'] ?? null,
                'category' => $row['category'] ?? null,
                'qty_sold' => (int) $qtySold,
                'revenue' => $revenue,
                'estimated_cost' => $productCost,
                'estimated_profit' => $productProfit,
                'avg_unit_cost' => $avgCost,
            ];
        }

        $topProducts = array_slice($topProducts, 0, 10);

        $trendRows = $db->table('transactions t')
            ->select('DATE(t.created_at) AS sale_date, COUNT(*) AS txn_count, COALESCE(SUM(t.amount), 0) AS total_sales')
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $fromTs)
            ->where('t.created_at <=', $toTs)
            ->groupBy('DATE(t.created_at)')
            ->orderBy('sale_date', 'ASC')
            ->get()
            ->getResultArray();

        $trend = array_map(static function (array $row): array {
            return [
                'date' => (string) ($row['sale_date'] ?? ''),
                'transactions' => (int) ($row['txn_count'] ?? 0),
                'sales' => (float) ($row['total_sales'] ?? 0),
            ];
        }, $trendRows);

        $stockInAgg = $db->table('inventory_movements im')
            ->select('COALESCE(SUM(im.qty), 0) AS stock_in_units, COALESCE(SUM(im.total_cost), 0) AS stock_in_cost, COALESCE(SUM(im.expected_profit), 0) AS projected_profit')
            ->where('im.store_id', $storeId)
            ->where('im.type', 'restock')
            ->where('im.created_at >=', $fromTs)
            ->where('im.created_at <=', $toTs)
            ->get()
            ->getRowArray();

        $totalSales = (float) ($txnAgg['total_sales'] ?? 0);
        $txnCount = (int) ($txnAgg['txn_count'] ?? 0);
        $itemsSold = (int) ($itemsAgg['items_sold'] ?? 0);
        $estimatedProfit = $totalSales - $estimatedCost;
        $profitMarginPercent = $totalSales > 0 ? ($estimatedProfit / $totalSales) * 100 : 0.0;

        $paymentBreakdown = array_map(static function (array $row): array {
            return [
                'payment_method' => (string) ($row['payment_method'] ?? 'unknown'),
                'transactions' => (int) ($row['txn_count'] ?? 0),
                'sales' => (float) ($row['total_sales'] ?? 0),
            ];
        }, $paymentRows);

        $paymentAccountBreakdown = [];
        if ($hasPaymentLines && $db->tableExists('payment_destination_accounts')) {
            $accountRows = $db->table('transaction_payments tp')
                ->select('tp.destination_account_id, tp.destination_account_name, tp.destination_account_number, tp.payment_method, COUNT(DISTINCT tp.transaction_id) AS txn_count, COALESCE(SUM(tp.amount), 0) AS total_sales', false)
                ->join('transactions t', 't.id = tp.transaction_id')->where('t.store_id', $storeId)
                ->where('t.created_at >=', $fromTs)->where('t.created_at <=', $toTs)->where('tp.destination_account_id IS NOT NULL', null, false)
                ->groupBy('tp.destination_account_id, tp.destination_account_name, tp.destination_account_number, tp.payment_method')->orderBy('total_sales', 'DESC')->get()->getResultArray();
            $paymentAccountBreakdown = array_map(static fn(array $row): array => [
                'destination_account_id' => (int) $row['destination_account_id'], 'payment_method' => (string) $row['payment_method'],
                'account_name' => (string) $row['destination_account_name'], 'account_number' => (string) $row['destination_account_number'],
                'transactions' => (int) $row['txn_count'], 'sales' => (float) $row['total_sales'],
            ], $accountRows);
        }

        $cashMovementModel = new StoreCashMovementModel();
        $todayDate = $today->format('Y-m-d');
        $sessionModel = new StoreDaySessionModel();
        $reportSession = $sessionModel->getByStoreAndDate($storeId, $toDate);
        $openingModel = new StoreOpeningBalanceModel();
        $initialOpening = $openingModel->getInitialByStore($storeId);
        $openingBalance = $reportSession ? (float) ($reportSession['opening_cash'] ?? 0) : (float) ($initialOpening['opening_balance'] ?? 0);
        $openingEcash = $reportSession ? (float) ($reportSession['opening_ecash'] ?? 0) : 0.0;
        $openingBusinessDate = $reportSession ? (string) ($reportSession['business_date'] ?? $toDate) : (string) ($initialOpening['business_date'] ?? $todayDate);
        $openingFromTs = $openingBusinessDate . ' 00:00:00';
        $cashSalesPeriod = 0.0;
        $eCashSalesPeriod = 0.0;
        foreach ($paymentBreakdown as $row) {
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            $sales = (float) ($row['sales'] ?? 0);
            if ($method === 'cash') {
                $cashSalesPeriod += $sales;
                continue;
            }
            if ($method === 'debt') {
                continue;
            }
            $eCashSalesPeriod += $sales;
        }

        $asOfToTs = $toDate . ' 23:59:59';
        $asOfPaymentBuilder = $db->table('transactions t')
            ->select($paymentMethodSql . ' AS payment_method, COALESCE(SUM(' . $paymentAmountSql . '), 0) AS total_sales', false);
        if ($hasPaymentLines) {
            $asOfPaymentBuilder->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left');
        }
        $asOfPaymentRows = $asOfPaymentBuilder
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $openingFromTs)
            ->where('t.created_at <=', $asOfToTs)
            ->groupBy($paymentMethodSql, false)
            ->get()
            ->getResultArray();

        $cashSalesAsOf = 0.0;
        $eCashSalesAsOf = 0.0;
        foreach ($asOfPaymentRows as $row) {
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            $sales = (float) ($row['total_sales'] ?? 0);
            if ($method === 'cash') {
                $cashSalesAsOf += $sales;
                continue;
            }
            if ($method === 'debt') {
                continue;
            }
            $eCashSalesAsOf += $sales;
        }

        $movementAggRows = $db->table('store_cash_movements scm')
            ->select('scm.channel, scm.movement_type, COALESCE(SUM(scm.amount), 0) AS total_amount')
            ->where('scm.store_id', $storeId)
            ->where('scm.business_date >=', $fromDate)
            ->where('scm.business_date <=', $toDate)
            ->groupBy('scm.channel, scm.movement_type')
            ->get()
            ->getResultArray();

        $periodCashIn = 0.0;
        $periodCashOut = 0.0;
        $periodEcashIn = 0.0;
        $periodEcashOut = 0.0;
        foreach ($movementAggRows as $row) {
            $channel = strtolower((string) ($row['channel'] ?? 'cash'));
            $type = strtolower((string) ($row['movement_type'] ?? 'cash_in'));
            $amount = (float) ($row['total_amount'] ?? 0);
            if ($channel === 'ecash') {
                if ($type === 'cash_out') {
                    $periodEcashOut += $amount;
                } else {
                    $periodEcashIn += $amount;
                }
            } else {
                if ($type === 'cash_out') {
                    $periodCashOut += $amount;
                } else {
                    $periodCashIn += $amount;
                }
            }
        }

        $asOfMovementAggRows = $db->table('store_cash_movements scm')
            ->select('scm.channel, scm.movement_type, COALESCE(SUM(scm.amount), 0) AS total_amount')
            ->where('scm.store_id', $storeId)
            ->where('scm.business_date >=', $openingBusinessDate)
            ->where('scm.business_date <=', $toDate)
            ->groupBy('scm.channel, scm.movement_type')
            ->get()
            ->getResultArray();

        $asOfCashIn = 0.0;
        $asOfCashOut = 0.0;
        $asOfEcashIn = 0.0;
        $asOfEcashOut = 0.0;
        foreach ($asOfMovementAggRows as $row) {
            $channel = strtolower((string) ($row['channel'] ?? 'cash'));
            $type = strtolower((string) ($row['movement_type'] ?? 'cash_in'));
            $amount = (float) ($row['total_amount'] ?? 0);
            if ($channel === 'ecash') {
                if ($type === 'cash_out') {
                    $asOfEcashOut += $amount;
                } else {
                    $asOfEcashIn += $amount;
                }
            } else {
                if ($type === 'cash_out') {
                    $asOfCashOut += $amount;
                } else {
                    $asOfCashIn += $amount;
                }
            }
        }

        $cashOnHandAsOf = $openingBalance + $cashSalesAsOf + $asOfCashIn - $asOfCashOut;
        $ecashOnHandAsOf = $openingEcash + $eCashSalesAsOf + $asOfEcashIn - $asOfEcashOut;
        $combinedOnHandAsOf = $cashOnHandAsOf + $ecashOnHandAsOf;

        $cashMovements = $cashMovementModel->getByStoreAndRange($storeId, $fromDate, $toDate, 200);

        return $this->response->setJSON([
            'status' => 'success',
            'store' => [
                'id' => $storeId,
                'name' => $storeName,
            ],
            'range' => [
                'period' => $period,
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'summary' => [
                'transactions' => $txnCount,
                'items_sold' => $itemsSold,
                'total_sales' => $totalSales,
                'estimated_cost' => $estimatedCost,
                'estimated_profit' => $estimatedProfit,
                'profit_margin_percent' => $profitMarginPercent,
                'average_ticket' => $txnCount > 0 ? $totalSales / $txnCount : 0.0,
            ],
            'stock_in' => [
                'units' => (int) ($stockInAgg['stock_in_units'] ?? 0),
                'total_cost' => (float) ($stockInAgg['stock_in_cost'] ?? 0),
                'projected_profit' => (float) ($stockInAgg['projected_profit'] ?? 0),
            ],
            'payment_breakdown' => $paymentBreakdown,
            'payment_account_breakdown' => $paymentAccountBreakdown,
            'cash_drawer' => [
                'business_date' => $toDate,
                'opening_business_date' => $openingBusinessDate,
                'opening_balance' => $openingBalance,
                'opening_cash' => $openingBalance,
                'opening_ecash' => $openingEcash,
                'session_status' => (string) ($reportSession['status'] ?? ''),
                'cash_sales' => $cashSalesAsOf,
                'ecash_sales' => $eCashSalesAsOf,
                'cash_in' => $asOfCashIn,
                'cash_out' => $asOfCashOut,
                'ecash_in' => $asOfEcashIn,
                'ecash_out' => $asOfEcashOut,
                'expected_cash_on_hand' => $cashOnHandAsOf,
                'expected_ecash_on_hand' => $ecashOnHandAsOf,
                'expected_total_on_hand' => $combinedOnHandAsOf,
            ],
            'cash_movement_summary' => [
                'cash_in' => $periodCashIn,
                'cash_out' => $periodCashOut,
                'ecash_in' => $periodEcashIn,
                'ecash_out' => $periodEcashOut,
                'cash_sales' => $cashSalesPeriod,
                'ecash_sales' => $eCashSalesPeriod,
            ],
            'cash_movements' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'business_date' => (string) ($row['business_date'] ?? ''),
                    'channel' => (string) ($row['channel'] ?? 'cash'),
                    'movement_type' => (string) ($row['movement_type'] ?? 'cash_in'),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'reason' => (string) ($row['reason'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $cashMovements),
            'cash_channels' => [
                'cash' => [
                    'sales' => $cashSalesAsOf,
                    'on_hand' => $cashOnHandAsOf,
                ],
                'ecash' => [
                    'sales' => $eCashSalesAsOf,
                    'on_hand' => $ecashOnHandAsOf,
                ],
                'combined' => [
                    'on_hand' => $combinedOnHandAsOf,
                ],
            ],
            'top_products' => $topProducts,
            'trend' => $trend,
            'notes' => [
                'profit_basis' => 'Estimated using weighted-average restock unit cost per product up to selected period end.',
            ],
        ]);
    }

    public function daySessionStatus()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $businessDate = date('Y-m-d');
        $model = new StoreDaySessionModel();
        $todaySession = $model->getByStoreAndDate((int) $store['id'], $businessDate);
        $openSession = $model->getOpenByStore((int) $store['id']);
        $session = $todaySession ?: $openSession;
        $expected = $session ? $this->calculateStoreSessionExpected((int) $store['id'], $session) : null;
        $isCurrentBusinessDate = $session !== null && (string) ($session['business_date'] ?? '') === $businessDate;
        $isStaleOpen = $session !== null && (string) ($session['status'] ?? '') === 'open' && !$isCurrentBusinessDate;
        $serializedSession = $session ? $this->serializeStoreDaySession($session, $expected) : null;
        if ($serializedSession) {
            $serializedSession['is_current_business_date'] = $isCurrentBusinessDate;
            $serializedSession['is_stale_open'] = $isStaleOpen;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'business_date' => $businessDate,
            'is_opened' => $session !== null && (string) ($session['status'] ?? '') === 'open' && $isCurrentBusinessDate,
            'is_closed' => $todaySession !== null && (string) ($todaySession['status'] ?? '') === 'closed',
            'is_stale_open' => $isStaleOpen,
            'session' => $serializedSession,
        ]);
    }

    public function openDaySession()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $openingCash = (float) ($request['opening_cash'] ?? $request['opening_balance'] ?? 0);
        $openingEcash = (float) ($request['opening_ecash'] ?? 0);
        $legacyOpeningEcash = $openingEcash;
        $paymentAccountOpenings = is_array($request['payment_account_openings'] ?? null) ? $request['payment_account_openings'] : [];
        $note = trim((string) ($request['note'] ?? ''));
        $businessDate = date('Y-m-d');

        if ($openingCash < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Opening cash must be 0 or greater.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $db = Database::connect();
        $validatedAccountOpenings = [];
        $openingEcash = 0.0;
        if ($db->tableExists('payment_destination_accounts')) {
            foreach ($paymentAccountOpenings as $opening) {
                $accountId = (int) ($opening['destination_account_id'] ?? 0);
                $amount = round((float) ($opening['opening_balance'] ?? 0), 2);
                $account = $accountId > 0 ? (new PaymentDestinationAccountModel())->find($accountId) : null;
                if (!$account || (int) $account['store_id'] !== (int) $store['id'] || !ibems_bool($account['is_active'] ?? false) || $amount < 0) {
                    return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Every receiving-account opening balance must be valid and 0 or greater.']);
                }
                $validatedAccountOpenings[] = ['destination_account_id' => $accountId, 'opening_balance' => $amount];
                $openingEcash += $amount;
            }
        }
        if ($validatedAccountOpenings === []) $openingEcash = max(0, $legacyOpeningEcash);
        $paymentAccountOpenings = $validatedAccountOpenings;

        $model = new StoreDaySessionModel();
        $openSession = $model->getOpenByStore((int) $store['id']);
        if ($openSession && (string) ($openSession['business_date'] ?? '') !== $businessDate) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Another store day is still open. Close it before opening today.',
            ]);
        }

        $existing = $model->getByStoreAndDate((int) $store['id'], $businessDate);
        if ($existing && (string) ($existing['status'] ?? '') === 'open') {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Today is already open for this store.',
            ]);
        }

        if ($existing && (string) ($existing['status'] ?? '') === 'closed' && (string) session()->get('role') !== 'ADMIN') {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'Today is already closed. Only admin can reopen it.',
            ]);
        }

        $actorId = (int) session()->get('user_id');
        $isReopen = $existing && (string) ($existing['status'] ?? '') === 'closed';
        $previousClose = null;
        if ($isReopen) {
            if ($note === '') {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'A reopen reason is required.']);
            }
            $previousClose = [
                'expected_cash' => ($existing['expected_cash'] ?? null) !== null ? (float) $existing['expected_cash'] : null,
                'expected_ecash' => ($existing['expected_ecash'] ?? null) !== null ? (float) $existing['expected_ecash'] : null,
                'counted_cash' => ($existing['counted_cash'] ?? null) !== null ? (float) $existing['counted_cash'] : null,
                'counted_ecash' => ($existing['counted_ecash'] ?? null) !== null ? (float) $existing['counted_ecash'] : null,
                'variance_cash' => ($existing['variance_cash'] ?? null) !== null ? (float) $existing['variance_cash'] : null,
                'variance_ecash' => ($existing['variance_ecash'] ?? null) !== null ? (float) $existing['variance_ecash'] : null,
                'variance_status' => (string) ($existing['variance_status'] ?? ''),
                'review_status' => (string) ($existing['review_status'] ?? ''),
                'closing_note' => (string) ($existing['closing_note'] ?? ''),
                'closed_by' => ($existing['closed_by'] ?? null) !== null ? (int) $existing['closed_by'] : null,
                'closed_at' => (string) ($existing['closed_at'] ?? ''),
            ];
            $openingCash = (float) ($existing['opening_cash'] ?? 0);
            $openingEcash = (float) ($existing['opening_ecash'] ?? 0);
            $session = $model->reopenDay((int) $existing['id'], $actorId);
        } else {
            $session = $model->openDay((int) $store['id'], $businessDate, $openingCash, $openingEcash, $actorId, $note);
        }
        if (!$isReopen && $db->tableExists('store_day_payment_account_balances')) {
            foreach ($paymentAccountOpenings as $opening) {
                $accountId = (int) ($opening['destination_account_id'] ?? 0);
                $account = $accountId > 0 ? (new PaymentDestinationAccountModel())->find($accountId) : null;
                if (!$account || (int) $account['store_id'] !== (int) $store['id'] || !ibems_bool($account['is_active'] ?? false)) continue;
                $amount = max(0, round((float) ($opening['opening_balance'] ?? 0), 2));
                $payload = ['store_day_session_id' => (int) $session['id'], 'destination_account_id' => $accountId,
                    'account_name_snapshot' => (string) $account['account_name'], 'account_number_snapshot' => (string) $account['account_number'],
                    'opening_balance' => $amount, 'updated_at' => date('Y-m-d H:i:s')];
                $existingBalance = $db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) $session['id'])->where('destination_account_id', $accountId)->get()->getRowArray();
                if ($existingBalance) $db->table('store_day_payment_account_balances')->where('id', (int) $existingBalance['id'])->update($payload);
                else { $payload['created_at'] = date('Y-m-d H:i:s'); $db->table('store_day_payment_account_balances')->insert($payload); }
            }
        }
        $expected = $this->calculateStoreSessionExpected((int) $store['id'], $session);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $isReopen ? 'REOPEN_STORE_DAY_SESSION' : 'OPEN_STORE_DAY_SESSION',
            'entity' => 'store_day_sessions',
            'entity_id' => (int) ($session['id'] ?? 0),
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'opening_cash' => $openingCash,
                'opening_ecash' => $openingEcash,
                'reason' => $isReopen ? $note : null,
                'previous_close' => $previousClose,
                'original_opening_balances_preserved' => $isReopen,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'session' => $this->serializeStoreDaySession($session, $expected),
        ]);
    }

    public function closeDaySession()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $countedCash = (float) ($request['counted_cash'] ?? 0);
        $countedEcash = (float) ($request['counted_ecash'] ?? 0);
        $legacyCountedEcash = $countedEcash;
        $paymentAccountCounts = is_array($request['payment_account_counts'] ?? null) ? $request['payment_account_counts'] : [];
        $unassignedPaymentCounts = is_array($request['unassigned_payment_counts'] ?? null) ? $request['unassigned_payment_counts'] : [];
        $hasStructuredElectronicCounts = array_key_exists('payment_account_counts', $request) || array_key_exists('unassigned_payment_counts', $request);
        $note = trim((string) ($request['note'] ?? ''));

        if ($countedCash < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Counted cash must be 0 or greater.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StoreDaySessionModel();
        $session = $model->getOpenByStore((int) $store['id']);
        if (!$session || (string) ($session['status'] ?? '') !== 'open') {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'There is no open day session to close for this store.',
            ]);
        }
        $businessDate = (string) ($session['business_date'] ?? date('Y-m-d'));
        if ($businessDate !== date('Y-m-d')) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'A stale store day must be resolved by another assigned supervisor or an Administrator.',
            ]);
        }

        $expected = $this->calculateStoreSessionExpected((int) $store['id'], $session);
        $expectedCash = (float) ($expected['expected_cash_on_hand'] ?? 0);
        $expectedEcash = (float) ($expected['expected_ecash_on_hand'] ?? 0);
        $expectedAccounts = [];
        foreach (($expected['payment_account_balances'] ?? []) as $account) $expectedAccounts[(int) $account['id']] = $account;
        $validatedAccountCounts = [];
        $countedEcash = 0.0;
        foreach ($paymentAccountCounts as $count) {
            $accountId = (int) ($count['destination_account_id'] ?? 0);
            $counted = round((float) ($count['counted_balance'] ?? 0), 2);
            if (!isset($expectedAccounts[$accountId]) || $counted < 0) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Every receiving-account ending balance must be valid and 0 or greater.']);
            }
            $validatedAccountCounts[] = ['destination_account_id' => $accountId, 'counted_balance' => $counted];
            $countedEcash += $counted;
        }
        $expectedUnassigned = [];
        foreach (($expected['unassigned_payment_balances'] ?? []) as $row) $expectedUnassigned[(string) $row['key']] = $row;
        $validatedUnassignedCounts = [];
        foreach ($unassignedPaymentCounts as $count) {
            $key = (string) ($count['key'] ?? '');
            $counted = round((float) ($count['counted_balance'] ?? 0), 2);
            if (!isset($expectedUnassigned[$key]) || $counted < 0) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Every legacy payment ending balance must be valid and 0 or greater.']);
            }
            $validatedUnassignedCounts[] = ['key' => $key, 'counted_balance' => $counted];
            $countedEcash += $counted;
        }
        if ($hasStructuredElectronicCounts && count($validatedUnassignedCounts) !== count($expectedUnassigned)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Count every legacy payment bucket before closing the store day.']);
        }
        $unassignedPaymentCounts = $validatedUnassignedCounts;
        if (!$hasStructuredElectronicCounts || ($expectedAccounts === [] && $expectedUnassigned === [])) $countedEcash = max(0, $legacyCountedEcash);
        if ($hasStructuredElectronicCounts && count($validatedAccountCounts) !== count($expectedAccounts)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Count every receiving account before closing the store day.']);
        }
        $paymentAccountCounts = $validatedAccountCounts;
        $varianceCash = round($countedCash - $expectedCash, 2);
        $varianceEcash = round($countedEcash - $expectedEcash, 2);
        $totalVariance = round($varianceCash + $varianceEcash, 2);
        $varianceStatus = 'balanced';
        if ($totalVariance < 0) {
            $varianceStatus = 'shortage';
        } elseif ($totalVariance > 0) {
            $varianceStatus = 'overage';
        }
        $reviewStatus = $varianceStatus === 'balanced' ? 'not_required' : 'pending';

        if ($reviewStatus === 'pending' && $note === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Closing note is required when there is a cash or e-cash variance.',
            ]);
        }

        $actorId = (int) session()->get('user_id');
        $now = date('Y-m-d H:i:s');

        $model->update((int) $session['id'], [
            'status' => 'closed',
            'expected_cash' => $expectedCash,
            'expected_ecash' => $expectedEcash,
            'counted_cash' => $countedCash,
            'counted_ecash' => $countedEcash,
            'variance_cash' => $varianceCash,
            'variance_ecash' => $varianceEcash,
            'variance_status' => $varianceStatus,
            'review_status' => $reviewStatus,
            'closing_note' => $note !== '' ? $note : null,
            'closed_by' => $actorId > 0 ? $actorId : null,
            'closed_at' => $now,
            'updated_at' => $now,
        ]);

        $db = Database::connect();
        if ($db->tableExists('store_day_payment_account_balances')) {
            foreach ($paymentAccountCounts as $count) {
                $accountId = (int) ($count['destination_account_id'] ?? 0);
                if (!isset($expectedAccounts[$accountId])) continue;
                $account = $expectedAccounts[$accountId];
                $counted = max(0, round((float) ($count['counted_balance'] ?? 0), 2));
                $expectedBalance = round((float) ($account['expected_balance'] ?? 0), 2);
                $payload = ['store_day_session_id' => (int) $session['id'], 'destination_account_id' => $accountId,
                    'account_name_snapshot' => (string) $account['account_name'], 'account_number_snapshot' => (string) $account['account_number'],
                    'opening_balance' => 0, 'expected_balance' => $expectedBalance, 'counted_balance' => $counted,
                    'variance' => round($counted - $expectedBalance, 2), 'updated_at' => $now];
                $existingAccountBalance = $db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) $session['id'])->where('destination_account_id', $accountId)->get()->getRowArray();
                if ($existingAccountBalance) $db->table('store_day_payment_account_balances')->where('id', (int) $existingAccountBalance['id'])->update($payload);
                else { $payload['created_at'] = $now; $db->table('store_day_payment_account_balances')->insert($payload); }
            }
        }

        $closed = $model->find((int) $session['id']) ?? $session;
        if ($reviewStatus === 'pending') {
            (new StoreDayVarianceCaseService())->ensureCase(
                Database::connect(),
                $closed,
                $actorId > 0 ? $actorId : null,
                $now
            );
        }
        $closedExpected = $this->calculateStoreSessionExpected((int) $store['id'], $closed);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'CLOSE_STORE_DAY_SESSION',
            'entity' => 'store_day_sessions',
            'entity_id' => (int) $session['id'],
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'expected_cash' => $expectedCash,
                'expected_ecash' => $expectedEcash,
                'counted_cash' => $countedCash,
                'counted_ecash' => $countedEcash,
                'variance_cash' => $varianceCash,
                'variance_ecash' => $varianceEcash,
                'variance_status' => $varianceStatus,
                'review_status' => $reviewStatus,
                'unassigned_payment_counts' => $unassignedPaymentCounts,
                'note' => $note,
            ]),
            'created_at' => $now,
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'session' => $this->serializeStoreDaySession($closed, $closedExpected),
        ]);
    }

    public function openingBalanceStatus()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StoreOpeningBalanceModel();
        $row = $model->getInitialByStore((int) $store['id']);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'business_date' => (string) ($row['business_date'] ?? date('Y-m-d')),
            'is_opened' => (bool) $row,
            'opening' => $row ? [
                'id' => (int) $row['id'],
                'opening_balance' => (float) $row['opening_balance'],
                'note' => (string) ($row['note'] ?? ''),
                'opened_by' => $row['opened_by'] !== null ? (int) $row['opened_by'] : null,
                'opened_at' => (string) ($row['opened_at'] ?? ''),
            ] : null,
        ]);
    }

    public function setOpeningBalance()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $openingBalance = (float) ($request['opening_balance'] ?? 0);
        $note = trim((string) ($request['note'] ?? ''));

        if ($openingBalance < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Opening balance must be 0 or greater.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $openedBy = (int) session()->get('user_id');
        $model = new StoreOpeningBalanceModel();
        $existing = $model->getInitialByStore((int) $store['id']);
        if ($existing) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Initial opening balance is already set. Use cash in/out for adjustments.',
            ]);
        }

        $row = $model->createInitialOpening((int) $store['id'], $openingBalance, $openedBy, $note);

        return $this->response->setJSON([
            'status' => 'success',
            'opening' => [
                'id' => (int) ($row['id'] ?? 0),
                'business_date' => (string) ($row['business_date'] ?? date('Y-m-d')),
                'opening_balance' => (float) ($row['opening_balance'] ?? $openingBalance),
                'note' => (string) ($row['note'] ?? ''),
                'opened_by' => $row['opened_by'] !== null ? (int) $row['opened_by'] : $openedBy,
                'opened_at' => (string) ($row['opened_at'] ?? ''),
            ],
        ]);
    }

    public function resetOpeningBalance()
    {
        $role = (string) session()->get('role');
        if ($role !== 'ADMIN') {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'Only admin can reset initial opening balance.',
            ]);
        }

        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $openingBalance = (float) ($request['opening_balance'] ?? 0);
        $note = trim((string) ($request['note'] ?? ''));

        if ($note === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'status' => 'error',
                'message' => 'An override reason is required to reset the initial opening balance.',
            ]);
        }

        if ($openingBalance < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Initial opening balance must be 0 or greater.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StoreOpeningBalanceModel();
        $existing = $model->getInitialByStore((int) $store['id']);
        if (!$existing) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'No initial opening balance found. Set it first.',
            ]);
        }

        $actorId = (int) session()->get('user_id');
        $now = date('Y-m-d H:i:s');

        $model->update((int) $existing['id'], [
            'opening_balance' => $openingBalance,
            'note' => $note !== '' ? $note : null,
            'opened_by' => $actorId > 0 ? $actorId : null,
            'opened_at' => $now,
            'updated_at' => $now,
        ]);

        $updated = $model->find((int) $existing['id']) ?? $existing;

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'RESET_INITIAL_OPENING_BALANCE',
            'entity' => 'store_opening_balances',
            'entity_id' => (int) $existing['id'],
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'before' => [
                    'opening_balance' => (float) ($existing['opening_balance'] ?? 0),
                    'note' => (string) ($existing['note'] ?? ''),
                    'opened_by' => $existing['opened_by'] !== null ? (int) $existing['opened_by'] : null,
                    'opened_at' => (string) ($existing['opened_at'] ?? ''),
                ],
                'after' => [
                    'opening_balance' => (float) ($updated['opening_balance'] ?? $openingBalance),
                    'note' => (string) ($updated['note'] ?? ''),
                    'opened_by' => $updated['opened_by'] !== null ? (int) $updated['opened_by'] : null,
                    'opened_at' => (string) ($updated['opened_at'] ?? $now),
                ],
            ]),
            'created_at' => $now,
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'opening' => [
                'id' => (int) ($updated['id'] ?? $existing['id']),
                'business_date' => (string) ($updated['business_date'] ?? $existing['business_date']),
                'opening_balance' => (float) ($updated['opening_balance'] ?? $openingBalance),
                'note' => (string) ($updated['note'] ?? ''),
                'opened_by' => $updated['opened_by'] !== null ? (int) $updated['opened_by'] : ($actorId > 0 ? $actorId : null),
                'opened_at' => (string) ($updated['opened_at'] ?? $now),
            ],
        ]);
    }

    public function cashMovements()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $fromDate = trim((string) $this->request->getGet('date_from'));
        $toDate = trim((string) $this->request->getGet('date_to'));
        $todayDate = date('Y-m-d');
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $fromDate)) {
            $fromDate = $todayDate;
        }
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $toDate)) {
            $toDate = $todayDate;
        }
        if ($fromDate > $toDate) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'date_from cannot be later than date_to.',
            ]);
        }

        $limit = (int) ($this->request->getGet('limit') ?? 200);
        $model = new StoreCashMovementModel();
        $rows = $model->getByStoreAndRange((int) $store['id'], $fromDate, $toDate, $limit);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'range' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'movements' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'business_date' => (string) ($row['business_date'] ?? ''),
                    'channel' => (string) ($row['channel'] ?? 'cash'),
                    'movement_type' => (string) ($row['movement_type'] ?? 'cash_in'),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'reason' => (string) ($row['reason'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $rows),
        ]);
    }

    public function createCashMovement()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $amount = (float) ($request['amount'] ?? 0);
        $reason = trim((string) ($request['reason'] ?? ''));
        $channel = strtolower(trim((string) ($request['channel'] ?? 'cash')));
        $movementType = strtolower(trim((string) ($request['movement_type'] ?? 'cash_in')));
        $businessDate = trim((string) ($request['business_date'] ?? date('Y-m-d')));

        if (!in_array($channel, ['cash', 'ecash'], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid channel. Use cash or ecash.',
            ]);
        }

        if (!in_array($movementType, ['cash_in', 'cash_out'], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid movement type. Use cash_in or cash_out.',
            ]);
        }

        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $businessDate)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'business_date must be YYYY-MM-DD.',
            ]);
        }

        if ($amount <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Amount must be greater than 0.',
            ]);
        }

        if ($reason === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Reason is required.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $sessionModel = new StoreDaySessionModel();
        $daySession = $sessionModel->getByStoreAndDate((int) $store['id'], $businessDate);
        if (!$daySession || (string) ($daySession['status'] ?? '') !== 'open') {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Cash movements require an open store day for the selected business date.',
            ]);
        }

        $actorId = (int) session()->get('user_id');
        $model = new StoreCashMovementModel();
        $movementId = $model->insert([
            'store_id' => (int) $store['id'],
            'business_date' => $businessDate,
            'channel' => $channel,
            'movement_type' => $movementType,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $actorId > 0 ? $actorId : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$movementId) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to save cash movement.',
            ]);
        }

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'CREATE_CASH_MOVEMENT',
            'entity' => 'store_cash_movements',
            'entity_id' => $movementId,
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'channel' => $channel,
                'movement_type' => $movementType,
                'amount' => $amount,
                'reason' => $reason,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'movement' => [
                'id' => (int) $movementId,
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'channel' => $channel,
                'movement_type' => $movementType,
                'amount' => $amount,
                'reason' => $reason,
            ],
        ]);
    }

    public function createDebtRepayment()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $debtorId = (int) ($request['user_id'] ?? 0);
        $amount = round((float) ($request['amount'] ?? 0), 2);
        $hasExplicitPaymentMethod = array_key_exists('payment_method', $request);
        $paymentMethod = strtolower(trim((string) ($request['payment_method'] ?? $request['channel'] ?? 'cash')));
        $channel = $paymentMethod === 'cash' ? 'cash' : 'ecash';
        $destinationAccountId = (int) ($request['destination_account_id'] ?? 0);
        $referenceNo = trim((string) ($request['reference_no'] ?? ''));
        $remarks = trim((string) ($request['remarks'] ?? ''));
        $businessDate = date('Y-m-d');

        if ($debtorId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Select a debtor before recording payment.',
            ]);
        }

        if ($amount <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Payment amount must be greater than 0.',
            ]);
        }

        if ($paymentMethod === 'debt' || $paymentMethod === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Select a valid collection payment method.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $methodRow = $hasExplicitPaymentMethod
            ? (new StorePaymentMethodModel())->where('store_id', (int) $store['id'])->where('code', $paymentMethod)->where('is_active', true)->first()
            : ['code' => $paymentMethod];
        if (!$methodRow || $paymentMethod === 'debt') {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Select an active cash or electronic payment method.']);
        }
        if ($channel === 'ecash' && $hasExplicitPaymentMethod) {
            $destination = $destinationAccountId > 0 ? (new PaymentDestinationAccountModel())->find($destinationAccountId) : null;
            if (!$destination || (int) ($destination['store_id'] ?? 0) !== (int) $store['id'] || (int) ($destination['payment_method_id'] ?? 0) !== (int) ($methodRow['id'] ?? 0) || !ibems_bool($destination['is_active'] ?? false)) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Select an active receiving account for this electronic collection.']);
            }
        }

        $sessionModel = new StoreDaySessionModel();
        $daySession = $sessionModel->getByStoreAndDate((int) $store['id'], $businessDate);
        if (!$daySession || (string) ($daySession['status'] ?? '') !== 'open') {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Open today\'s store day before recording debt payments.',
            ]);
        }

        $db = Database::connect();
        $debtor = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.user_type, u.is_active, b.credit_limit, b.current_debt')
            ->join('balances b', 'b.user_id = u.id', 'inner')
            ->where('u.id', $debtorId)
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->get()
            ->getRowArray();

        if (!$debtor) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Active faculty/staff debtor not found.',
            ]);
        }

        $currentDebt = round((float) ($debtor['current_debt'] ?? 0), 2);
        $creditLimit = round((float) ($debtor['credit_limit'] ?? 0), 2);
        if ($currentDebt <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'This debtor has no outstanding debt.',
            ]);
        }

        if ($amount > $currentDebt) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Payment cannot exceed the current debt.',
            ]);
        }

        $newDebt = round($currentDebt - $amount, 2);
        $actorId = (int) session()->get('user_id');
        $now = date('Y-m-d H:i:s');
        $reasonParts = [
            'Debt repayment',
            'Method: ' . ($paymentMethod === 'ecash' ? 'electronic' : $paymentMethod),
            $destinationAccountId > 0 ? 'Account: ' . $destinationAccountId : '',
            (string) ($debtor['employee_id'] ?? ''),
            (string) ($debtor['name'] ?? ''),
        ];
        if ($referenceNo !== '') {
            $reasonParts[] = 'Ref: ' . $referenceNo;
        }
        $reason = trim(implode(' | ', array_filter($reasonParts, static fn ($part) => trim($part) !== '')));

        $balanceModel = new BalanceModel();
        $cashMovementModel = new StoreCashMovementModel();
        $auditLogModel = new AuditLogModel();

        $db->transStart();

        $balanceModel->update($debtorId, [
            'current_debt' => $newDebt,
            'updated_at' => $now,
        ]);

        $movementId = $cashMovementModel->insert([
            'store_id' => (int) $store['id'],
            'business_date' => $businessDate,
            'channel' => $channel,
            'movement_type' => 'cash_in',
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $actorId > 0 ? $actorId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->addDebtCashbookEntry(
            $debtorId,
            'store_repayment',
            $amount,
            $currentDebt,
            $newDebt,
            $creditLimit,
            $actorId > 0 ? $actorId : null,
            $remarks !== '' ? $remarks : $reason,
            'store_cash_movement',
            $movementId ? (int) $movementId : null,
            [
                'source' => 'store_direct_repayment',
                'store_id' => (int) $store['id'],
                'store_name' => (string) ($store['store_name'] ?? 'Store'),
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
                'reference_no' => $referenceNo,
            ]
        );

        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'STORE_DEBT_REPAYMENT',
            'entity' => 'balances',
            'entity_id' => $debtorId,
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'user_id' => $debtorId,
                'previous_debt' => $currentDebt,
                'paid_amount' => $amount,
                'new_debt' => $newDebt,
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
                'reference_no' => $referenceNo,
                'cash_movement_id' => $movementId ? (int) $movementId : null,
            ]),
            'created_at' => $now,
        ]);

        $db->transComplete();

        if (!$db->transStatus() || !$movementId) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to record debt payment.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'payment' => [
                'movement_id' => (int) $movementId,
                'store_id' => (int) $store['id'],
                'user_id' => $debtorId,
                'debtor_name' => (string) ($debtor['name'] ?? ''),
                'employee_id' => (string) ($debtor['employee_id'] ?? ''),
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
                'amount' => $amount,
                'previous_debt' => $currentDebt,
                'new_debt' => $newDebt,
                'reference_no' => $referenceNo,
                'created_at' => $now,
            ],
        ]);
    }

    public function products()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 1);
        $userId = (int) session()->get('user_id');
        $role = (string) session()->get('role');

        if ($storeId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store_id.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $productModel = new ProductModel();
        $products = $productModel->getActiveProductsByStore($storeId);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => $storeId,
            'products' => $products,
        ]);
    }
    public function myStores()
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);

        if ($role !== 'STORE_SYSTEM' && $role !== 'ADMIN') {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'Forbidden',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'stores' => $stores,
            'default_store_id' => $stores[0]['id'] ?? null,
        ]);
    }

    public function categories()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $categories = $categoryModel->getActiveByStore((int) $store['id']);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'categories' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ];
            }, $categories),
        ]);
    }

    public function createCategory()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));

        if ($name === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Category name is required.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $categoryId = $categoryModel->ensureCategory((int) $store['id'], $name);
        $created = $categoryModel->find($categoryId);

        return $this->response->setJSON([
            'status' => 'success',
            'category' => [
                'id' => (int) $created['id'],
                'name' => (string) $created['name'],
                'sort_order' => (int) ($created['sort_order'] ?? 0),
            ],
        ]);
    }

    public function updateCategory()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $categoryId = (int) ($request['category_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));

        if ($categoryId <= 0 || $name === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid category update payload.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $category = $categoryModel->find($categoryId);
        if (!$category || (int) $category['store_id'] !== (int) $store['id']) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Category not found.',
            ]);
        }

        $duplicate = $categoryModel->where('store_id', (int) $store['id'])
            ->where('name', $name)
            ->where('id !=', $categoryId)
            ->first();
        if ($duplicate) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Category name already exists.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $categoryModel->update($categoryId, [
            'name' => $name,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $db->table('products')
            ->where('store_id', (int) $store['id'])
            ->where('category', (string) $category['name'])
            ->update([
                'category' => $name,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update category.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    public function deleteCategory()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $categoryId = (int) ($request['category_id'] ?? 0);

        if ($categoryId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Category id is required.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $category = $categoryModel->find($categoryId);
        if (!$category || (int) $category['store_id'] !== (int) $store['id']) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Category not found.',
            ]);
        }

        if (strtolower((string) $category['name']) === 'general') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'General category cannot be deleted.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $db->table('products')
            ->where('store_id', (int) $store['id'])
            ->where('category', (string) $category['name'])
            ->update([
                'category' => 'General',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $categoryModel->delete($categoryId);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to delete category.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    public function paymentMethods()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $model->ensureDefaults((int) $store['id']);
        $methods = $model->getActiveByStore((int) $store['id']);
        $accountsByMethod = [];
        $db = Database::connect();
        if ($db->tableExists('payment_destination_accounts')) {
            foreach ($db->table('payment_destination_accounts')->where('store_id', (int) $store['id'])->where('is_active', true)
                ->orderBy('sort_order', 'ASC')->orderBy('account_name', 'ASC')->get()->getResultArray() as $account) {
                $accountsByMethod[(int) $account['payment_method_id']][] = [
                    'id' => (int) $account['id'], 'account_name' => (string) $account['account_name'],
                    'masked_number' => self::maskPaymentAccount((string) $account['account_number']),
                    'image_url' => (string) ($account['image_url'] ?? ''),
                ];
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'supports_split_payment' => in_array('transaction_payments', Database::connect()->listTables(), true),
            'methods' => array_map(static function (array $row) use ($accountsByMethod): array {
                return [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['code'],
                    'label' => (string) $row['label'],
                    'icon_class' => (string) ($row['icon_class'] ?? ''),
                    'image_url' => (string) ($row['image_url'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_system_reserved' => ibems_bool($row['is_system_reserved'] ?? false),
                    'requires_destination_account' => !in_array((string) $row['code'], ['cash', 'debt'], true),
                    'destination_accounts' => $accountsByMethod[(int) $row['id']] ?? [],
                ];
            }, $methods),
        ]);
    }

    public function createPaymentMethod()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $label = trim((string) ($request['label'] ?? ''));
        $iconClass = trim((string) ($request['icon_class'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));

        if ($label === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Payment method label is required.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $model->ensureDefaults((int) $store['id']);

        $requestedCode = trim((string) ($request['code'] ?? ''));
        $code = $model->normalizeCode($requestedCode !== '' ? $requestedCode : $label);
        if ($code === '' || $code === 'debt') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid payment method code.',
            ]);
        }

        $existing = $model->where('store_id', (int) $store['id'])
            ->where('code', $code)
            ->first();

        if ($existing) {
            if (ibems_bool($existing['is_system_reserved'] ?? false)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'System payment method cannot be recreated.',
                ]);
            }

            $model->update((int) $existing['id'], [
                'label' => $label,
                'icon_class' => $iconClass !== '' ? $iconClass : null,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $maxSort = $model->where('store_id', (int) $store['id'])->selectMax('sort_order')->first();
            $nextSort = (int) ($maxSort['sort_order'] ?? 0) + 10;

            $model->insert([
                'store_id' => (int) $store['id'],
                'code' => $code,
                'label' => $label,
                'icon_class' => $iconClass !== '' ? $iconClass : null,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'sort_order' => $nextSort,
                'is_active' => true,
                'is_system_reserved' => false,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $saved = $model->where('store_id', (int) $store['id'])->where('code', $code)->first();
        return $this->response->setJSON([
            'status' => 'success',
            'method' => $saved ? ['id' => (int) $saved['id'], 'code' => (string) $saved['code'], 'label' => (string) $saved['label']] : null,
        ]);
    }

    public function updatePaymentMethod()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $methodId = (int) ($request['method_id'] ?? 0);
        $label = trim((string) ($request['label'] ?? ''));
        $iconClass = trim((string) ($request['icon_class'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));

        if ($methodId <= 0 || $label === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid payment method update payload.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $method = $model->find($methodId);
        if (!$method || (int) $method['store_id'] !== (int) $store['id']) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Payment method not found.',
            ]);
        }

        if ((string) $method['code'] === 'debt') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Debt payment method is protected.',
            ]);
        }

        $model->update($methodId, [
            'label' => $label,
            'icon_class' => $iconClass !== '' ? $iconClass : null,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    public function deletePaymentMethod()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $storeId = (int) ($request['store_id'] ?? 0);
        $methodId = (int) ($request['method_id'] ?? 0);

        if ($methodId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Payment method id is required.',
            ]);
        }

        $store = $this->resolveAccessibleStore($storeId);
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $method = $model->find($methodId);
        if (!$method || (int) $method['store_id'] !== (int) $store['id']) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Payment method not found.',
            ]);
        }

        if (ibems_bool($method['is_system_reserved'] ?? false) || (string) $method['code'] === 'debt') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'This payment method is protected.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();
        $model->update($methodId, [
            'is_active' => false,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($db->tableExists('payment_destination_accounts')) {
            $db->table('payment_destination_accounts')
                ->where('store_id', (int) $store['id'])
                ->where('payment_method_id', $methodId)
                ->update(['is_active' => false, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Unable to delete payment method.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'method_id' => $methodId,
        ]);
    }

    public function paymentAccounts()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getGet('store_id') ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $db = Database::connect();
        if (!$db->tableExists('payment_destination_accounts')) {
            return $this->response->setJSON(['status' => 'success', 'accounts' => [], 'migration_required' => true]);
        }
        $rows = $db->table('payment_destination_accounts a')
            ->select('a.*, m.code AS payment_method, m.label AS payment_method_label, m.icon_class')
            ->join('store_payment_methods m', 'm.id = a.payment_method_id')
            ->where('a.store_id', (int) $store['id'])->where('a.is_active', true)
            ->orderBy('m.sort_order', 'ASC')->orderBy('a.sort_order', 'ASC')->orderBy('a.account_name', 'ASC')->get()->getResultArray();
        return $this->response->setJSON(['status' => 'success', 'accounts' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'payment_method_id' => (int) $row['payment_method_id'],
            'payment_method' => (string) $row['payment_method'], 'payment_method_label' => (string) $row['payment_method_label'],
            'icon_class' => (string) ($row['icon_class'] ?? ''), 'account_name' => (string) $row['account_name'],
            'account_number' => (string) $row['account_number'], 'masked_number' => self::maskPaymentAccount((string) $row['account_number']),
            'image_url' => (string) ($row['image_url'] ?? ''),
        ], $rows)]);
    }

    public function savePaymentAccount()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $store = $this->resolveAccessibleStore((int) ($request['store_id'] ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $methodId = (int) ($request['payment_method_id'] ?? 0);
        $name = trim((string) ($request['account_name'] ?? ''));
        $number = trim((string) ($request['account_number'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));
        if ($methodId <= 0 || $name === '' || $number === '') return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Payment method, account name, and account number are required.']);
        $method = (new StorePaymentMethodModel())->find($methodId);
        if (!$method || (int) $method['store_id'] !== (int) $store['id'] || in_array((string) $method['code'], ['cash', 'debt'], true)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Select a non-cash receiving method.']);
        }
        if ($imageUrl !== '' && !filter_var($imageUrl, FILTER_VALIDATE_URL) && !str_starts_with($imageUrl, '/')) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'QR image must be a valid HTTPS or application URL.']);
        }
        $model = new PaymentDestinationAccountModel();
        $accountId = (int) ($request['account_id'] ?? 0);
        $existing = $accountId > 0 ? $model->find($accountId) : null;
        if ($existing && (int) $existing['store_id'] !== (int) $store['id']) return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Payment account not found.']);
        $payload = ['store_id' => (int) $store['id'], 'payment_method_id' => $methodId, 'account_name' => $name,
            'account_number' => $number, 'image_url' => $imageUrl !== '' ? $imageUrl : null, 'is_active' => true, 'updated_at' => date('Y-m-d H:i:s')];
        if ($existing) $model->update($accountId, $payload); else { $payload['created_at'] = date('Y-m-d H:i:s'); $model->insert($payload); }
        return $this->response->setJSON(['status' => 'success']);
    }

    public function deactivatePaymentAccount()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $store = $this->resolveAccessibleStore((int) ($request['store_id'] ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $model = new PaymentDestinationAccountModel();
        $row = $model->find((int) ($request['account_id'] ?? 0));
        if (!$row || (int) $row['store_id'] !== (int) $store['id']) return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Payment account not found.']);
        $model->update((int) $row['id'], ['is_active' => false, 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->response->setJSON(['status' => 'success']);
    }

    public function uploadPaymentAccountQr()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getPost('store_id') ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $file = $this->request->getFile('qr_image');
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Choose a QR image first.']);
        try {
            $url = (new AssetStorageService())->storeImage($file, 'payment-account-qr');
            return $this->response->setJSON(['status' => 'success', 'image_url' => $url]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function uploadPaymentMethodImage()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getPost('store_id') ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $file = $this->request->getFile('method_image');
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Choose a payment method image first.']);
        try {
            return $this->response->setJSON(['status' => 'success', 'image_url' => (new AssetStorageService())->storeImage($file, 'payment-method-images')]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private static function maskPaymentAccount(string $number): string
    {
        $visible = mb_substr($number, -4);
        return mb_strlen($number) <= 4 ? $number : str_repeat('•', max(4, mb_strlen($number) - 4)) . $visible;
    }

    public function debtCustomers()
    {
        $q = trim((string) $this->request->getGet('q'));
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pageSize = max(10, min(100, (int) ($this->request->getGet('page_size') ?? 20)));
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'name')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $sortColumns = [
            'name' => 'u.name',
            'debt' => 'b.current_debt',
            'credit' => 'b.credit_limit',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['name'];
        $db = Database::connect();

        $builder = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.user_type, u.is_active, u.debt_pin_hash, b.credit_limit, b.current_debt')
            ->join('balances b', 'b.user_id = u.id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff']);

        if ($q !== '') {
            $builder->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $rows = $builder
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('u.id', 'ASC')
            ->limit($pageSize, ($page - 1) * $pageSize)
            ->get()
            ->getResultArray();

        $customers = array_map(static function (array $row): array {
            $creditLimit = (float) $row['credit_limit'];
            $currentDebt = (float) $row['current_debt'];
            $row['available_credit'] = max(0, $creditLimit - $currentDebt);
            $row['is_active'] = in_array($row['is_active'] ?? false, [true, 1, '1', 't', 'true'], true);
            $row['has_debt_pin'] = trim((string) ($row['debt_pin_hash'] ?? '')) !== '';
            unset($row['debt_pin_hash']);
            return $row;
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'customers' => $customers,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function transactions()
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);

        if ($stores === []) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'No accessible store found.',
            ]);
        }

        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }

        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pageSize = max(10, min(100, (int) ($this->request->getGet('page_size') ?? $this->request->getGet('limit') ?? 25)));
        $offset = ($page - 1) * $pageSize;
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'date')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'date' => 't.created_at',
            'customer' => 'u.name',
            'payment' => 't.payment_method',
            'amount' => 't.amount',
            'reference' => 't.client_txn_id',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];
        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $paymentMethod = trim((string) $this->request->getGet('payment_method'));

        $db = Database::connect();
        $allowedPaymentMethods = array_column($db->table('store_payment_methods')->select('code')->where('store_id', $storeId)->get()->getResultArray(), 'code');
        $allowedPaymentMethods[] = 'split';
        $hasPaymentLines = in_array('transaction_payments', $db->listTables(), true);
        $query = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.customer_type, t.user_id, u.name AS customer_name')
            ->join('users u', 'u.id = t.user_id', 'left')
            ->where('t.store_id', $storeId);

        if ($dateFrom !== '') {
            $query->where('t.created_at >=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('t.created_at <=', $dateTo . ' 23:59:59');
        }

        if ($paymentMethod !== '' && in_array($paymentMethod, $allowedPaymentMethods, true)) {
            if ($hasPaymentLines) {
                $query->groupStart()
                    ->where('t.payment_method', $paymentMethod)
                    ->orWhere("EXISTS (SELECT 1 FROM transaction_payments tp_filter WHERE tp_filter.transaction_id = t.id AND tp_filter.payment_method = " . $db->escape($paymentMethod) . ")", null, false)
                    ->groupEnd();
            } else {
                $query->where('t.payment_method', $paymentMethod);
            }
        }

        $total = (clone $query)->countAllResults();
        $summaryQuery = $db->table('transactions t')
            ->select('COUNT(t.id) AS transaction_count, COALESCE(SUM(t.amount), 0) AS total_amount')
            ->where('t.store_id', $storeId);
        if ($dateFrom !== '') {
            $summaryQuery->where('t.created_at >=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $summaryQuery->where('t.created_at <=', $dateTo . ' 23:59:59');
        }
        if ($paymentMethod !== '' && in_array($paymentMethod, $allowedPaymentMethods, true)) {
            if ($hasPaymentLines) {
                $summaryQuery->groupStart()
                    ->where('t.payment_method', $paymentMethod)
                    ->orWhere("EXISTS (SELECT 1 FROM transaction_payments tp_filter WHERE tp_filter.transaction_id = t.id AND tp_filter.payment_method = " . $db->escape($paymentMethod) . ")", null, false)
                    ->groupEnd();
            } else {
                $summaryQuery->where('t.payment_method', $paymentMethod);
            }
        }
        $filteredSummary = $summaryQuery->get()->getRowArray() ?? [];
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('t.id', $sortDir)
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $paymentRowsByTransaction = [];
        $transactionIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($hasPaymentLines && $transactionIds !== []) {
            $paymentLineSelect = $db->fieldExists('destination_account_id', 'transaction_payments')
                ? 'transaction_id, payment_method, destination_account_id, destination_account_name, destination_account_number, amount, cash_received, change_due'
                : 'transaction_id, payment_method, amount, cash_received, change_due';
            $paymentRows = $db->table('transaction_payments')
                ->select($paymentLineSelect)
                ->whereIn('transaction_id', $transactionIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($paymentRows as $paymentRow) {
                $paymentRowsByTransaction[(int) $paymentRow['transaction_id']][] = [
                    'payment_method' => (string) $paymentRow['payment_method'],
                    'destination_account_id' => isset($paymentRow['destination_account_id']) ? (int) $paymentRow['destination_account_id'] : null,
                    'destination_account_name' => (string) ($paymentRow['destination_account_name'] ?? ''),
                    'destination_account_number' => (string) ($paymentRow['destination_account_number'] ?? ''),
                    'amount' => (float) $paymentRow['amount'],
                    'cash_received' => $paymentRow['cash_received'] !== null ? (float) $paymentRow['cash_received'] : null,
                    'change_due' => $paymentRow['change_due'] !== null ? (float) $paymentRow['change_due'] : null,
                ];
            }
        }

        $transactions = array_map(static function (array $row) use ($paymentRowsByTransaction): array {
            $customerName = $row['customer_name'] ?: 'Walk-in';
            if (($row['customer_type'] ?? '') !== 'walk_in' && !$row['customer_name']) {
                $customerName = ucfirst((string) $row['customer_type']);
            }

            return [
                'id' => (int) $row['id'],
                'client_txn_id' => $row['client_txn_id'],
                'created_at' => $row['created_at'],
                'payment_method' => $row['payment_method'],
                'amount' => (float) $row['amount'],
                'customer_type' => $row['customer_type'],
                'customer_name' => $customerName,
                'payments' => $paymentRowsByTransaction[(int) $row['id']] ?? [],
            ];
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => $storeId,
            'transactions' => $transactions,
            'summary' => [
                'transaction_count' => (int) ($filteredSummary['transaction_count'] ?? 0),
                'total_amount' => (float) ($filteredSummary['total_amount'] ?? 0),
            ],
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function transactionDetails(int $transactionId)
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
        $storeModel = new StoreModel();
        $db = Database::connect();

        $txn = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.customer_type, t.store_id, t.user_id, u.name AS customer_name, s.store_name')
            ->join('users u', 'u.id = t.user_id', 'left')
            ->join('stores s', 's.id = t.store_id', 'inner')
            ->where('t.id', $transactionId)
            ->get()
            ->getRowArray();

        if (!$txn) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Transaction not found.',
            ]);
        }

        $storeId = (int) $txn['store_id'];
        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this transaction.',
            ]);
        }

        $itemsRaw = $db->table('transaction_items ti')
            ->select('ti.product_id, ti.qty, ti.unit_price, ti.line_total, p.name AS product_name')
            ->join('products p', 'p.id = ti.product_id', 'left')
            ->where('ti.transaction_id', $transactionId)
            ->orderBy('ti.id', 'ASC')
            ->get()
            ->getResultArray();

        $items = array_map(static function (array $row): array {
            $name = $row['product_name'] ?: 'Product #' . $row['product_id'];
            return [
                'name' => $name,
                'qty' => (int) $row['qty'],
                'unit_price' => (float) $row['unit_price'],
                'line_total' => (float) $row['line_total'],
            ];
        }, $itemsRaw);

        $detailPaymentSelect = $db->fieldExists('destination_account_id', 'transaction_payments')
            ? 'payment_method, destination_account_id, destination_account_name, destination_account_number, amount, cash_received, change_due'
            : 'payment_method, amount, cash_received, change_due';
        $paymentRows = in_array('transaction_payments', $db->listTables(), true)
            ? $db->table('transaction_payments')
                ->select($detailPaymentSelect)
                ->where('transaction_id', $transactionId)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray()
            : [];
        $payments = array_map(static fn (array $row): array => [
            'payment_method' => (string) $row['payment_method'],
            'destination_account_id' => isset($row['destination_account_id']) ? (int) $row['destination_account_id'] : null,
            'destination_account_name' => (string) ($row['destination_account_name'] ?? ''),
            'destination_account_number' => (string) ($row['destination_account_number'] ?? ''),
            'amount' => (float) $row['amount'],
            'cash_received' => $row['cash_received'] !== null ? (float) $row['cash_received'] : null,
            'change_due' => $row['change_due'] !== null ? (float) $row['change_due'] : null,
        ], $paymentRows);
        if ($payments === []) {
            $payments[] = [
                'payment_method' => (string) $txn['payment_method'],
                'amount' => (float) $txn['amount'],
                'cash_received' => null,
                'change_due' => null,
            ];
        }

        $customerName = $txn['customer_name'] ?: 'Walk-in';
        if (($txn['customer_type'] ?? '') !== 'walk_in' && !$txn['customer_name']) {
            $customerName = ucfirst((string) $txn['customer_type']);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'transaction' => [
                'id' => (int) $txn['id'],
                'client_txn_id' => $txn['client_txn_id'],
                'created_at' => $txn['created_at'],
                'payment_method' => $txn['payment_method'],
                'amount' => (float) $txn['amount'],
                'customer_name' => $customerName,
                'store_name' => $txn['store_name'],
                'items' => $items,
                'payments' => $payments,
            ],
        ]);
    }

    public function inventoryMovements()
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);

        if ($stores === []) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'No accessible store found.',
            ]);
        }

        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }

        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $type = trim((string) $this->request->getGet('type'));
        $productId = (int) ($this->request->getGet('product_id') ?? 0);
        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $limit = max(1, min(100, (int) ($this->request->getGet('limit') ?? 8)));

        $allowedTypes = ['sale', 'restock', 'adjustment'];

        $db = Database::connect();
        $query = $db->table('inventory_movements im')
            ->select('im.id, im.created_at, im.type, im.qty, im.unit_cost, im.total_cost, im.expected_profit, im.reason, p.id AS product_id, p.name AS product_name, p.sku, p.price')
            ->join('products p', 'p.id = im.product_id', 'inner')
            ->where('im.store_id', $storeId);

        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $query->where('im.type', $type);
        }

        if ($productId > 0) {
            $query->where('im.product_id', $productId);
        }

        if ($dateFrom !== '') {
            $query->where('im.created_at >=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('im.created_at <=', $dateTo . ' 23:59:59');
        }

        $rows = $query->orderBy('im.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $movements = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'type' => $row['type'],
                'qty' => (int) $row['qty'],
                'unit_cost' => $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null,
                'total_cost' => $row['total_cost'] !== null ? (float) $row['total_cost'] : null,
                'expected_profit' => $row['expected_profit'] !== null ? (float) $row['expected_profit'] : null,
                'reason' => $row['reason'],
                'product' => [
                    'id' => (int) $row['product_id'],
                    'name' => $row['product_name'],
                    'sku' => $row['sku'],
                    'price' => (float) $row['price'],
                ],
            ];
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => $storeId,
            'movements' => $movements,
        ]);
    }

    public function restock()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');
        $storeId = (int) ($request['store_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $qty = (int) ($request['qty'] ?? 0);
        $unitCost = (float) ($request['unit_cost'] ?? 0);
        $sellPrice = (float) ($request['sell_price'] ?? 0);
        $reason = trim((string) ($request['reason'] ?? 'Stock in'));

        if ($storeId <= 0 || $productId <= 0 || $qty <= 0 || $unitCost < 0 || $sellPrice < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid restock payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot restock this store.',
            ]);
        }

        $productModel = new ProductModel();
        $product = $productModel->find($productId);
        if (!$product || (int) $product['store_id'] !== $storeId) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Product does not belong to the selected store.',
            ]);
        }

        $totalCost = $unitCost * $qty;
        $profitPerPiece = $sellPrice - $unitCost;
        $expectedProfit = $profitPerPiece * $qty;
        $db = Database::connect();
        $db->transStart();

        $productModel->update($productId, ['price' => $sellPrice]);
        $productModel->addStock($productId, $qty);

        $movementModel = new InventoryMovementModel();
        $movementId = $movementModel->insert([
            'product_id' => $productId,
            'store_id' => $storeId,
            'type' => 'restock',
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'expected_profit' => $expectedProfit,
            'reason' => $reason !== '' ? $reason : 'Stock in',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'RESTOCK_PRODUCT',
            'entity' => 'inventory_movements',
            'entity_id' => $movementId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'product_id' => $productId,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'expected_profit' => $expectedProfit,
                'sell_price' => $sellPrice,
                'profit_per_piece' => $profitPerPiece,
                'reason' => $reason,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Restock failed.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'movement_id' => $movementId,
            'sell_price' => $sellPrice,
            'profit_per_piece' => $profitPerPiece,
            'expected_profit' => $expectedProfit,
            'total_cost' => $totalCost,
        ]);
    }

    public function adjustStock()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');
        $storeId = (int) ($request['store_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $actualQty = (int) ($request['actual_qty'] ?? -1);
        $reason = trim((string) ($request['reason'] ?? 'Physical count adjustment'));

        if ($storeId <= 0 || $productId <= 0 || $actualQty < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid stock adjustment payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot adjust this store stock.',
            ]);
        }

        $productModel = new ProductModel();
        $product = $productModel->find($productId);
        if (!$product || (int) $product['store_id'] !== $storeId) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Product does not belong to the selected store.',
            ]);
        }

        $previousQty = (int) $product['stock_qty'];
        $diffQty = $actualQty - $previousQty;

        if ($diffQty === 0) {
            return $this->response->setJSON([
                'status' => 'success',
                'product_id' => $productId,
                'previous_qty' => $previousQty,
                'actual_qty' => $actualQty,
                'diff_qty' => 0,
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $productModel->update($productId, [
            'stock_qty' => $actualQty,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $movementModel = new InventoryMovementModel();
        $movementId = $movementModel->insert([
            'product_id' => $productId,
            'store_id' => $storeId,
            'type' => 'adjustment',
            'qty' => $diffQty,
            'reason' => $reason !== '' ? $reason : 'Physical count adjustment',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADJUST_PRODUCT_STOCK',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'product_id' => $productId,
                'previous_qty' => $previousQty,
                'actual_qty' => $actualQty,
                'diff_qty' => $diffQty,
                'reason' => $reason,
                'movement_id' => $movementId,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Stock adjustment failed.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'product_id' => $productId,
            'previous_qty' => $previousQty,
            'actual_qty' => $actualQty,
            'diff_qty' => $diffQty,
        ]);
    }

    public function addProduct()
    {
        $request = $this->request->getPost();
        if ($request === []) {
            $request = $this->request->getJSON(true) ?? [];
        }
        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');

        $storeId = (int) ($request['store_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $supplier = trim((string) ($request['supplier'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));
        $sellPrice = (float) ($request['sell_price'] ?? 0);
        $initialStock = (int) ($request['initial_stock'] ?? 0);
        $unitCost = (float) ($request['unit_cost'] ?? 0);
        $lowStockThreshold = max(0, (int) ($request['low_stock_threshold'] ?? 10));
        $locationBin = trim((string) ($request['location_bin'] ?? ($request['location'] ?? '')));
        $reason = trim((string) ($request['reason'] ?? 'Initial stock'));

        if ($storeId <= 0 || $sku === '' || $name === '' || $sellPrice < 0 || $initialStock < 0 || $unitCost < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid product payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot add products to this store.',
            ]);
        }

        $productModel = new ProductModel();
        $categoryModel = new StoreCategoryModel();
        if ($productModel->getBySku($storeId, $sku)) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'SKU already exists in this store.',
            ]);
        }

        $category = $category !== '' ? $category : 'General';
        $categoryModel->ensureCategory($storeId, $category);

        if ($barcode !== '') {
            $db = Database::connect();
            $sameBarcode = $db->table('products')
                ->where('store_id', $storeId)
                ->where('barcode', $barcode)
                ->get()
                ->getRowArray();
            if ($sameBarcode) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Barcode already exists in this store.',
                ]);
            }
        }

        try {
            $imageUrl = $this->resolveProductImageUrl($imageUrl, null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $productId = $productModel->insert([
            'store_id' => $storeId,
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category,
            'supplier' => $supplier !== '' ? $supplier : null,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'price' => $sellPrice,
            'stock_qty' => $initialStock,
            'low_stock_threshold' => $lowStockThreshold,
            'location_bin' => $locationBin !== '' ? $locationBin : null,
            'is_active' => true,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $movementId = null;
        if ($initialStock > 0) {
            $totalCost = $unitCost * $initialStock;
            $profitPerPiece = $sellPrice - $unitCost;
            $expectedProfit = $profitPerPiece * $initialStock;

            $movementModel = new InventoryMovementModel();
            $movementId = $movementModel->insert([
                'product_id' => $productId,
                'store_id' => $storeId,
                'type' => 'restock',
                'qty' => $initialStock,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'expected_profit' => $expectedProfit,
                'reason' => $reason !== '' ? $reason : 'Initial stock',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'CREATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'sku' => $sku,
                'name' => $name,
                'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                'category' => $category,
                'supplier' => $supplier !== '' ? $supplier : null,
                'image_url' => $imageUrl,
                'barcode' => $barcode !== '' ? $barcode : null,
                'sell_price' => $sellPrice,
                'initial_stock' => $initialStock,
                'low_stock_threshold' => $lowStockThreshold,
                'location_bin' => $locationBin !== '' ? $locationBin : null,
                'unit_cost' => $unitCost,
                'movement_id' => $movementId,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to create product.',
            ]);
        }

        $created = $productModel->find($productId);

        return $this->response->setJSON([
            'status' => 'success',
            'product' => $created,
        ]);
    }

    public function updateProduct()
    {
        $request = $this->request->getPost();
        if ($request === []) {
            $request = $this->request->getJSON(true) ?? [];
        }

        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');

        $storeId = (int) ($request['store_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $supplier = trim((string) ($request['supplier'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $sellPrice = (float) ($request['sell_price'] ?? 0);
        $lowStockThreshold = max(0, (int) ($request['low_stock_threshold'] ?? ($request['reorder_level'] ?? 10)));
        $locationBin = trim((string) ($request['location_bin'] ?? ($request['location'] ?? '')));
        $inputImageUrl = trim((string) ($request['image_url'] ?? ''));

        if ($storeId <= 0 || $productId <= 0 || $sku === '' || $name === '' || $sellPrice < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid product update payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot update products in this store.',
            ]);
        }

        $productModel = new ProductModel();
        $categoryModel = new StoreCategoryModel();
        $product = $productModel->find($productId);
        if (!$product || (int) $product['store_id'] !== $storeId) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Product not found in selected store.',
            ]);
        }

        $db = Database::connect();
        $sameSku = $db->table('products')
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->where('id !=', $productId)
            ->get()
            ->getRowArray();
        if ($sameSku) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'SKU already exists in this store.',
            ]);
        }

        if ($barcode !== '') {
            $sameBarcode = $db->table('products')
                ->where('store_id', $storeId)
                ->where('barcode', $barcode)
                ->where('id !=', $productId)
                ->get()
                ->getRowArray();
            if ($sameBarcode) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Barcode already exists in this store.',
                ]);
            }
        }

        try {
            $resolvedImageUrl = $this->resolveProductImageUrl($inputImageUrl, $product['image_url'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $category = $category !== '' ? $category : 'General';
        $categoryModel->ensureCategory($storeId, $category);

        $db->transStart();

        $productModel->update($productId, [
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category,
            'supplier' => $supplier !== '' ? $supplier : null,
            'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'price' => $sellPrice,
            'low_stock_threshold' => $lowStockThreshold,
            'location_bin' => $locationBin !== '' ? $locationBin : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'UPDATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'before' => [
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'variant_label' => $product['variant_label'] ?? null,
                    'category' => $product['category'],
                    'supplier' => $product['supplier'] ?? null,
                    'image_url' => $product['image_url'],
                    'barcode' => $product['barcode'] ?? null,
                    'price' => (float) $product['price'],
                    'low_stock_threshold' => (int) ($product['low_stock_threshold'] ?? 10),
                    'location_bin' => $product['location_bin'] ?? null,
                ],
                'after' => [
                    'sku' => $sku,
                    'name' => $name,
                    'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                    'category' => $category,
                    'supplier' => $supplier !== '' ? $supplier : null,
                    'image_url' => $resolvedImageUrl,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'price' => $sellPrice,
                    'low_stock_threshold' => $lowStockThreshold,
                    'location_bin' => $locationBin !== '' ? $locationBin : null,
                ],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update product.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'product' => $productModel->find($productId),
        ]);
    }

    private function serializeStoreDaySession(array $session, ?array $expected = null): array
    {
        $expected ??= $this->calculateStoreSessionExpected((int) ($session['store_id'] ?? 0), $session);
        $openedBy = $session['opened_by'] ?? null;
        $closedBy = $session['closed_by'] ?? null;

        return [
            'id' => (int) ($session['id'] ?? 0),
            'store_id' => (int) ($session['store_id'] ?? 0),
            'business_date' => (string) ($session['business_date'] ?? date('Y-m-d')),
            'status' => (string) ($session['status'] ?? 'open'),
            'opening_cash' => (float) ($session['opening_cash'] ?? 0),
            'opening_ecash' => (float) ($session['opening_ecash'] ?? 0),
            'opening_note' => (string) ($session['opening_note'] ?? ''),
            'opened_by' => $openedBy !== null ? (int) $openedBy : null,
            'opened_at' => (string) ($session['opened_at'] ?? ''),
            'expected_cash' => ($session['expected_cash'] ?? null) !== null ? (float) $session['expected_cash'] : (float) ($expected['expected_cash_on_hand'] ?? 0),
            'expected_ecash' => ($session['expected_ecash'] ?? null) !== null ? (float) $session['expected_ecash'] : (float) ($expected['expected_ecash_on_hand'] ?? 0),
            'counted_cash' => ($session['counted_cash'] ?? null) !== null ? (float) $session['counted_cash'] : null,
            'counted_ecash' => ($session['counted_ecash'] ?? null) !== null ? (float) $session['counted_ecash'] : null,
            'variance_cash' => ($session['variance_cash'] ?? null) !== null ? (float) $session['variance_cash'] : null,
            'variance_ecash' => ($session['variance_ecash'] ?? null) !== null ? (float) $session['variance_ecash'] : null,
            'variance_status' => (string) ($session['variance_status'] ?? 'balanced'),
            'review_status' => (string) ($session['review_status'] ?? 'not_required'),
            'closing_note' => (string) ($session['closing_note'] ?? ''),
            'closed_by' => $closedBy !== null ? (int) $closedBy : null,
            'closed_at' => (string) ($session['closed_at'] ?? ''),
            'cash_sales' => (float) ($expected['cash_sales'] ?? 0),
            'ecash_sales' => (float) ($expected['ecash_sales'] ?? 0),
            'debt_sales' => (float) ($expected['debt_sales'] ?? 0),
            'cash_debt_payments' => (float) ($expected['cash_debt_payments'] ?? 0),
            'ecash_debt_payments' => (float) ($expected['ecash_debt_payments'] ?? 0),
            'cash_in' => (float) ($expected['cash_in'] ?? 0),
            'cash_out' => (float) ($expected['cash_out'] ?? 0),
            'ecash_in' => (float) ($expected['ecash_in'] ?? 0),
            'ecash_out' => (float) ($expected['ecash_out'] ?? 0),
            'expected_cash_on_hand' => (float) ($expected['expected_cash_on_hand'] ?? 0),
            'expected_ecash_on_hand' => (float) ($expected['expected_ecash_on_hand'] ?? 0),
            'expected_total_on_hand' => (float) ($expected['expected_total_on_hand'] ?? 0),
            'payment_account_balances' => array_values($expected['payment_account_balances'] ?? []),
            'unassigned_payment_balances' => array_values($expected['unassigned_payment_balances'] ?? []),
            'payment_method_sales' => array_values($expected['payment_method_sales'] ?? []),
            'payment_method_collections' => array_values($expected['payment_method_collections'] ?? []),
        ];
    }

    public function addProductFamily()
    {
        $request = $this->request->getPost();
        $variants = json_decode((string) ($request['variants'] ?? '[]'), true);
        $storeId = (int) ($request['store_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));
        $category = trim((string) ($request['category'] ?? 'General')) ?: 'General';
        $supplier = trim((string) ($request['supplier'] ?? ''));
        $location = trim((string) ($request['location_bin'] ?? ''));
        $reason = trim((string) ($request['reason'] ?? 'Initial stock')) ?: 'Initial stock';
        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');

        if ($storeId <= 0 || $name === '' || !is_array($variants) || $variants === []) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Product name and at least one variant are required.']);
        }
        if (!(new StoreModel())->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot add products to this store.']);
        }

        $skus = []; $barcodes = [];
        foreach ($variants as $index => $variant) {
            $sku = trim((string) ($variant['sku'] ?? '')); $label = trim((string) ($variant['label'] ?? ''));
            $barcode = trim((string) ($variant['barcode'] ?? ''));
            if ($sku === '' || $label === '' || (float) ($variant['price'] ?? -1) < 0 || (int) ($variant['stock'] ?? -1) < 0 || (float) ($variant['cost'] ?? -1) < 0) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid variant at row ' . ($index + 1) . '.']);
            }
            $skuKey = strtolower($sku); $barcodeKey = strtolower($barcode);
            if (isset($skus[$skuKey]) || ($barcode !== '' && isset($barcodes[$barcodeKey]))) {
                return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'Variant SKUs and barcodes must be unique.']);
            }
            $skus[$skuKey] = true; if ($barcode !== '') $barcodes[$barcodeKey] = true;
        }

        $db = Database::connect();
        if ($db->table('products')->where('store_id', $storeId)->whereIn('sku', array_column($variants, 'sku'))->countAllResults() > 0) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'One or more SKUs already exist in this store.']);
        }
        $nonEmptyBarcodes = array_values(array_filter(array_column($variants, 'barcode')));
        if ($nonEmptyBarcodes !== [] && $db->table('products')->where('store_id', $storeId)->whereIn('barcode', $nonEmptyBarcodes)->countAllResults() > 0) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'One or more barcodes already exist in this store.']);
        }

        try { $sharedImage = $this->resolveProductImageUrl(trim((string) ($request['image_url'] ?? '')), null); }
        catch (\RuntimeException $e) { return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]); }

        $db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $db->table('product_families')->insert(['store_id'=>$storeId,'name'=>$name,'category'=>$category,'supplier'=>$supplier ?: null,'image_url'=>$sharedImage,'option_name'=>'Size / Variant','created_at'=>$now,'updated_at'=>$now]);
            $familyId = (int) $db->insertID(); $productIds = [];
            foreach ($variants as $variantIndex => $variant) {
                $variantImage = $sharedImage;
                if ($variantIndex > 0) {
                    $override = $this->request->getFile('variant_image_' . $variantIndex);
                    if ($override && $override->getError() !== UPLOAD_ERR_NO_FILE) $variantImage = (new AssetStorageService())->storeImage($override, 'product-images', null);
                }
                $db->table('products')->insert(['store_id'=>$storeId,'family_id'=>$familyId,'sku'=>trim((string)$variant['sku']),'name'=>$name,'variant_label'=>trim((string)$variant['label']),'category'=>$category,'supplier'=>$supplier ?: null,'image_url'=>$variantImage,'barcode'=>trim((string)($variant['barcode']??'')) ?: null,'price'=>(float)$variant['price'],'stock_qty'=>(int)$variant['stock'],'low_stock_threshold'=>max(0,(int)($variant['low']??0)),'location_bin'=>$location ?: null,'is_active'=>true,'updated_at'=>$now]);
                $productId = (int) $db->insertID(); $productIds[] = $productId;
                if ((int)$variant['stock'] > 0) $db->table('inventory_movements')->insert(['product_id'=>$productId,'store_id'=>$storeId,'type'=>'restock','qty'=>(int)$variant['stock'],'unit_cost'=>(float)$variant['cost'],'total_cost'=>(float)$variant['cost']*(int)$variant['stock'],'expected_profit'=>((float)$variant['price']-(float)$variant['cost'])*(int)$variant['stock'],'reason'=>$reason,'created_at'=>$now]);
            }
            $db->table('audit_logs')->insert(['actor_id'=>$actorId,'action'=>'CREATE_PRODUCT_FAMILY','entity'=>'product_families','entity_id'=>$familyId,'payload_json'=>json_encode(['store_id'=>$storeId,'name'=>$name,'variant_count'=>count($variants),'product_ids'=>$productIds]),'created_at'=>$now]);
            if (!$db->transStatus()) throw new \RuntimeException('Failed to create product family.');
            $db->transCommit();
            return $this->response->setJSON(['status'=>'success','family_id'=>$familyId,'product_ids'=>$productIds,'variant_count'=>count($variants)]);
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON(['status'=>'error','message'=>$e->getMessage() ?: 'Failed to create product family.']);
        }
    }

    private function calculateStoreSessionExpected(int $storeId, array $session): array
    {
        return (new StoreDayExpectedService())->calculate($storeId, $session);
    }

    private function resolveAccessibleStore(int $requestedStoreId = 0): ?array
    {
        return (new StoreAccessService())->resolve(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            $requestedStoreId
        );
    }

    private function resolveProductImageUrl(string $inputUrl, ?string $currentUrl): ?string
    {
        $imageFile = $this->request->getFile('image_file');
        $hasFile = $imageFile && $imageFile->getError() !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            if (!$imageFile->isValid()) {
                throw new \RuntimeException('Invalid uploaded image file.');
            }

            return (new AssetStorageService())->storeImage($imageFile, 'product-images', $currentUrl);
        }

        if ($inputUrl !== '') {
            return $inputUrl;
        }

        return $currentUrl;
    }

    public function staffTransactions()
    {
        $role = (string) session()->get('role');
        $userId = (int) session()->get('user_id');
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);

        if ($stores === []) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'No accessible store found.',
            ]);
        }

        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }

        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $q = trim((string) $this->request->getGet('q'));
        $employeeUserId = (int) ($this->request->getGet('user_id') ?? 0);
        $debtOnly = (int) ($this->request->getGet('debt_only') ?? 0) === 1;
        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $limit = (int) ($this->request->getGet('limit') ?? 100);
        $limit = max(1, min(200, $limit));

        $db = Database::connect();
        $query = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, b.current_debt')
            ->join('users u', 'u.id = t.user_id', 'inner')
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('t.store_id', $storeId)
            ->whereIn('u.user_type', ['faculty', 'staff']);

        if ($debtOnly) {
            if (in_array('transaction_payments', $db->listTables(), true)) {
                $query->groupStart()
                    ->where('t.payment_method', 'debt')
                    ->orWhere('EXISTS (SELECT 1 FROM transaction_payments tp_debt WHERE tp_debt.transaction_id = t.id AND tp_debt.payment_method = ' . $db->escape('debt') . ')', null, false)
                    ->groupEnd();
            } else {
                $query->where('t.payment_method', 'debt');
            }
        }

        if ($dateFrom !== '') {
            $query->where('t.created_at >=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('t.created_at <=', $dateTo . ' 23:59:59');
        }

        if ($q !== '') {
            $query->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        if ($employeeUserId > 0) {
            $query->where('u.id', $employeeUserId);
        }

        $total = (clone $query)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('t.id', $sortDir)
            ->limit($pageSize, ($page - 1) * $pageSize)
            ->get()
            ->getResultArray();

        $transactions = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'client_txn_id' => $row['client_txn_id'],
                'created_at' => $row['created_at'],
                'payment_method' => $row['payment_method'],
                'amount' => (float) $row['amount'],
                'staff' => [
                    'id' => (int) $row['user_id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'user_type' => $row['user_type'],
                    'current_debt' => $row['current_debt'] !== null ? (float) $row['current_debt'] : 0.0,
                ],
            ];
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => $storeId,
            'transactions' => $transactions,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

}
