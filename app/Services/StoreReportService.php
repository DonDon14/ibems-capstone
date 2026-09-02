<?php

namespace App\Services;

use App\Models\StoreCashMovementModel;
use App\Models\StoreDaySessionModel;
use App\Models\StoreOpeningBalanceModel;
use Config\Database;

final class StoreReportService
{
    /** @return array<string, mixed> */
    public function summary(int $storeId, string $storeName, string $period, string $fromDate, string $toDate): array
    {
        $db = Database::connect();
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $fromTs = ibems_business_day_utc_bounds($fromDate)['start'];
        $toTs = ibems_business_day_utc_bounds($toDate)['end'];
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
            ->select('t.created_at, t.amount')
            ->where('t.store_id', $storeId)
            ->where('t.created_at >=', $fromTs)
            ->where('t.created_at <=', $toTs)
            ->orderBy('t.created_at', 'ASC')
            ->get()
            ->getResultArray();

        $trendMap = [];
        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        foreach ($trendRows as $row) {
            $createdAt = trim((string) ($row['created_at'] ?? ''));
            if ($createdAt === '') {
                continue;
            }
            $saleDate = (new \DateTimeImmutable($createdAt, $storageZone))->setTimezone($businessZone)->format('Y-m-d');
            $trendMap[$saleDate] ??= ['date' => $saleDate, 'transactions' => 0, 'sales' => 0.0];
            $trendMap[$saleDate]['transactions']++;
            $trendMap[$saleDate]['sales'] += (float) ($row['amount'] ?? 0);
        }
        $trend = array_values($trendMap);

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
        $openingFromTs = ibems_business_day_utc_bounds($openingBusinessDate)['start'];
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

        $asOfToTs = ibems_business_day_utc_bounds($toDate)['end'];
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

        return [
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
        ];
    }
}
