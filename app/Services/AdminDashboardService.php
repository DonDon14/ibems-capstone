<?php

namespace App\Services;

use Config\Database;

final class AdminDashboardService
{
    public function data(int $requestedAlertsPage, string $requestedPeriod = 'day'): array
    {
        $db = Database::connect();
        $requestedAlertsPage = max(1, $requestedAlertsPage);
        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable(ibems_business_date(), $businessZone);
        $period = DashboardPeriod::resolve($requestedPeriod);
        $rangeStartDate = $period['from'];
        $rangeStartTs = $period['start'];
        $rangeEndTs = $period['end'];

        $storeSummary = $db->table('stores')
            ->select('COUNT(*) AS total_stores, SUM(CASE WHEN is_active = TRUE THEN 1 ELSE 0 END) AS active_stores, SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) AS inactive_stores')
            ->get()
            ->getRowArray();

        $officerSummary = $db->table('user_roles ur')
            ->select('COUNT(DISTINCT ur.user_id) AS active_store_officers')
            ->join('users u', 'u.id = ur.user_id', 'inner')
            ->where('ur.role', 'STORE_SYSTEM')
            ->where('u.is_active', true)
            ->get()
            ->getRowArray();

        $activeStoreOfficers = (int) ($officerSummary['active_store_officers'] ?? 0);
        if ($activeStoreOfficers <= 0) {
            $fallback = $db->table('users')
                ->select('COUNT(*) AS active_store_officers')
                ->where('is_active', true)
                ->where('role', 'STORE_SYSTEM')
                ->get()
                ->getRowArray();
            $activeStoreOfficers = (int) ($fallback['active_store_officers'] ?? 0);
        }

        $debtSummary = $db->table('balances b')
            ->select('SUM(CASE WHEN b.current_debt > 0 THEN 1 ELSE 0 END) AS debt_accounts, COALESCE(SUM(b.current_debt), 0) AS total_debt')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->get()
            ->getRowArray();

        $todayDate = $today->format('Y-m-d');

        $productAlerts = $db->table('products')
            ->select('SUM(CASE WHEN is_active = TRUE AND stock_qty <= 0 THEN 1 ELSE 0 END) AS out_of_stock_products, SUM(CASE WHEN is_active = TRUE AND stock_qty > 0 AND stock_qty <= COALESCE(low_stock_threshold, 10) THEN 1 ELSE 0 END) AS low_stock_products')
            ->get()
            ->getRowArray();

        $creditAlerts = $db->table('balances b')
            ->select('SUM(CASE WHEN b.current_debt > b.credit_limit THEN 1 ELSE 0 END) AS over_credit_accounts')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->get()
            ->getRowArray();

        $storeDaySummary = [
            'open_today' => 0,
            'closed_today' => 0,
            'not_open_today' => 0,
        ];
        $unresolvedReviewCount = 0;
        $hasVarianceCases = false;
        $hasVarianceHandoffs = false;
        if ($db->tableExists('store_day_sessions')) {
            $sessionSummary = $db->table('stores s')
                ->select("SUM(CASE WHEN sds.status = 'open' THEN 1 ELSE 0 END) AS open_today, SUM(CASE WHEN sds.status = 'closed' THEN 1 ELSE 0 END) AS closed_today", false)
                ->join('store_day_sessions sds', 'sds.store_id = s.id AND sds.business_date = ' . $db->escape($todayDate), 'left', false)
                ->where('s.is_active', true)
                ->get()
                ->getRowArray();

            $storeDaySummary['open_today'] = (int) ($sessionSummary['open_today'] ?? 0);
            $storeDaySummary['closed_today'] = (int) ($sessionSummary['closed_today'] ?? 0);
            $storeDaySummary['not_open_today'] = max(
                0,
                (int) ($storeSummary['active_stores'] ?? 0) - $storeDaySummary['open_today'] - $storeDaySummary['closed_today']
            );

            $hasVarianceCases = $db->tableExists('store_day_variance_cases');
            $hasVarianceHandoffs = $hasVarianceCases && $db->tableExists('store_day_variance_case_handoffs');
            $unresolvedReviewCount = $db->table('store_day_sessions')
                ->where('status', 'closed')
                ->whereIn('review_status', ['pending', 'needs_investigation'])
                ->countAllResults();
        }

        $todayStart = $period['start'];
        $todayEnd = $period['end'];
        $todayTxn = $db->table('transactions')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->get()
            ->getRowArray();

        $trendRows = $db->table('transactions')
            ->select('created_at, amount')
            ->where('created_at >=', $rangeStartTs)
            ->where('created_at <=', $rangeEndTs)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        $trendMap = [];
        foreach ($trendRows as $row) {
            $createdAt = trim((string) ($row['created_at'] ?? ''));
            if ($createdAt === '') {
                continue;
            }
            $saleDate = (new \DateTimeImmutable($createdAt, $storageZone))->setTimezone($businessZone)->format('Y-m-d');
            $bucketKey = DashboardPeriod::bucketKey($saleDate, $period);
            $trendMap[$bucketKey] ??= ['transactions' => 0, 'sales' => 0.0];
            $trendMap[$bucketKey]['transactions']++;
            $trendMap[$bucketKey]['sales'] += (float) ($row['amount'] ?? 0);
        }
        $trend = [];
        foreach (DashboardPeriod::trendSeed($period) as $seed) {
            $trend[] = [
                'date' => $seed['date'],
                'transactions' => (int) ($trendMap[$seed['key']]['transactions'] ?? 0),
                'sales' => (float) ($trendMap[$seed['key']]['sales'] ?? 0),
            ];
        }

        $paymentRows = $db->table('transactions')
            ->select('payment_method, COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->where('created_at >=', $rangeStartTs)
            ->where('created_at <=', $rangeEndTs)
            ->groupBy('payment_method')
            ->orderBy('sales_total', 'DESC')
            ->get()
            ->getResultArray();
        $paymentBreakdown = array_map(static function (array $row): array {
            return [
                'payment_method' => strtoupper((string) ($row['payment_method'] ?? 'UNKNOWN')),
                'transactions' => (int) ($row['txn_count'] ?? 0),
                'sales' => (float) ($row['sales_total'] ?? 0),
            ];
        }, $paymentRows);

        $topStoreRows = $db->table('transactions t')
            ->select('s.id AS store_id, s.store_name, COUNT(t.id) AS txn_count, COALESCE(SUM(t.amount), 0) AS sales_total')
            ->join('stores s', 's.id = t.store_id', 'inner')
            ->where('t.created_at >=', $rangeStartTs)
            ->where('t.created_at <=', $rangeEndTs)
            ->groupBy('s.id, s.store_name')
            ->orderBy('sales_total', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();
        $topStores = array_map(static function (array $row): array {
            return [
                'store_id' => (int) ($row['store_id'] ?? 0),
                'store_name' => (string) ($row['store_name'] ?? 'Store'),
                'transactions' => (int) ($row['txn_count'] ?? 0),
                'sales' => (float) ($row['sales_total'] ?? 0),
            ];
        }, $topStoreRows);

        $topSellingItemRows = $db->table('transaction_items ti')
            ->select('ti.product_id, p.name, COALESCE(SUM(ti.qty), 0) AS qty_sold, COALESCE(SUM(ti.line_total), 0) AS sales_total')
            ->join('transactions t', 't.id = ti.transaction_id', 'inner')
            ->join('products p', 'p.id = ti.product_id', 'left')
            ->where('t.created_at >=', $rangeStartTs)
            ->where('t.created_at <=', $rangeEndTs)
            ->groupBy('ti.product_id, p.name')
            ->orderBy('qty_sold', 'DESC')
            ->orderBy('sales_total', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();
        $topSellingItems = array_map(static function (array $row): array {
            $productId = (int) ($row['product_id'] ?? 0);
            return [
                'product_id' => $productId,
                'name' => (string) ($row['name'] ?? ('Product #' . $productId)),
                'qty_sold' => (int) ($row['qty_sold'] ?? 0),
                'sales' => (float) ($row['sales_total'] ?? 0),
            ];
        }, $topSellingItemRows);

        $inactiveStores = (int) ($storeSummary['inactive_stores'] ?? 0);
        $outOfStockProducts = (int) ($productAlerts['out_of_stock_products'] ?? 0);
        $lowStockProducts = (int) ($productAlerts['low_stock_products'] ?? 0);
        $overCreditAccounts = (int) ($creditAlerts['over_credit_accounts'] ?? 0);
        $storesNotOpenToday = (int) ($storeDaySummary['not_open_today'] ?? 0);
        $unresolvedReviews = $unresolvedReviewCount;
        $openAlerts = $inactiveStores + $storesNotOpenToday + $outOfStockProducts + $lowStockProducts + $overCreditAccounts + $unresolvedReviews;

        $healthMessages = [];
        $healthItems = [];
        if ($inactiveStores > 0) {
            $healthMessages[] = $inactiveStores . ' inactive store(s)';
            $healthItems[] = ['icon' => 'bi-shop', 'text' => $inactiveStores . ' inactive store(s)'];
        }
        if ($storesNotOpenToday > 0) {
            $healthMessages[] = $storesNotOpenToday . ' store(s) not opened today';
            $healthItems[] = ['icon' => 'bi-shop-window', 'text' => $storesNotOpenToday . ' store(s) not opened today'];
        }
        if ($outOfStockProducts > 0) {
            $healthMessages[] = $outOfStockProducts . ' out-of-stock product(s)';
            $healthItems[] = ['icon' => 'bi-box-seam', 'text' => $outOfStockProducts . ' out-of-stock product(s)'];
        }
        if ($lowStockProducts > 0) {
            $healthMessages[] = $lowStockProducts . ' low-stock product(s)';
            $healthItems[] = ['icon' => 'bi-box2', 'text' => $lowStockProducts . ' low-stock product(s)'];
        }
        if ($overCreditAccounts > 0) {
            $healthMessages[] = $overCreditAccounts . ' over-credit account(s)';
            $healthItems[] = ['icon' => 'bi-credit-card-2-front', 'text' => $overCreditAccounts . ' over-credit account(s)'];
        }
        if ($unresolvedReviews > 0) {
            $healthMessages[] = $unresolvedReviews . ' unresolved store-day review(s)';
            $healthItems[] = ['icon' => 'bi-clipboard2-pulse', 'text' => $unresolvedReviews . ' unresolved store-day review(s)'];
        }
        $healthText = $openAlerts > 0
            ? 'Attention needed: ' . implode(', ', $healthMessages) . '.'
            : 'All core modules are online. No alerts detected.';

        $storesNotOpenRows = [];
        $unresolvedReviewRows = [];
        if ($db->tableExists('store_day_sessions')) {
            $storesNotOpenRows = $db->table('stores s')
                ->select('s.id, s.store_name, u.name AS officer_name')
                ->join('store_day_sessions sds', 'sds.store_id = s.id AND sds.business_date = ' . $db->escape($todayDate), 'left', false)
                ->join('users u', 'u.id = s.officer_id', 'left')
                ->where('s.is_active', true)
                ->where('sds.id IS NULL', null, false)
                ->orderBy('s.store_name', 'ASC')
                ->get()
                ->getResultArray();

            $reviewSelect = 'sds.id, sds.store_id, sds.business_date, sds.variance_cash, sds.variance_ecash, sds.variance_status, sds.review_status, s.store_name, closer.name AS closed_by_name';
            if ($hasVarianceCases) {
                $reviewSelect .= ', c.case_ref, c.owner_user_id, owner.name AS owner_name';
            } else {
                $reviewSelect .= ', NULL AS case_ref, NULL AS owner_user_id, NULL AS owner_name';
            }
            if ($hasVarianceHandoffs) {
                $reviewSelect .= ', h.status AS handoff_status, h.due_at AS handoff_due_at';
            } else {
                $reviewSelect .= ', NULL AS handoff_status, NULL AS handoff_due_at';
            }
            $unresolvedReviewQuery = $db->table('store_day_sessions sds')
                ->select($reviewSelect, false)
                ->join('stores s', 's.id = sds.store_id', 'inner')
                ->join('users closer', 'closer.id = sds.closed_by', 'left');
            if ($hasVarianceCases) {
                $unresolvedReviewQuery->join('store_day_variance_cases c', 'c.store_day_session_id = sds.id', 'left')
                    ->join('users owner', 'owner.id = c.owner_user_id', 'left');
            }
            if ($hasVarianceHandoffs) {
                $unresolvedReviewQuery->join('store_day_variance_case_handoffs h', 'h.id = (SELECT MAX(h2.id) FROM store_day_variance_case_handoffs h2 WHERE h2.case_id = c.id)', 'left', false);
            }
            $unresolvedReviewRows = $unresolvedReviewQuery
                ->where('sds.status', 'closed')
                ->whereIn('sds.review_status', ['pending', 'needs_investigation'])
                ->orderBy('sds.business_date', 'ASC')
                ->orderBy('sds.id', 'ASC')
                ->get()
                ->getResultArray();
        }

        $lowStockRows = $db->table('products p')
            ->select('p.id, p.name, p.sku, p.stock_qty, COALESCE(p.low_stock_threshold, 10) AS threshold_qty, s.store_name')
            ->join('stores s', 's.id = p.store_id', 'inner')
            ->where('p.is_active', true)
            ->where('p.stock_qty <= COALESCE(p.low_stock_threshold, 10)', null, false)
            ->orderBy('p.stock_qty', 'ASC')
            ->orderBy('p.name', 'ASC')
            ->get()
            ->getResultArray();

        $overCreditRows = $db->table('balances b')
            ->select('u.id, u.employee_id, u.name, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->where('b.current_debt > b.credit_limit', null, false)
            ->orderBy('(b.current_debt - b.credit_limit)', 'DESC', false)
            ->get()
            ->getResultArray();

        $inactiveStoreRows = $db->table('stores s')
            ->select('s.id, s.store_name, u.name AS officer_name')
            ->join('users u', 'u.id = s.officer_id', 'left')
            ->where('s.is_active', false)
            ->orderBy('s.store_name', 'ASC')
            ->get()
            ->getResultArray();

        $alerts = [];
        foreach ($unresolvedReviewRows as $row) {
            $variance = (float) ($row['variance_cash'] ?? 0) + (float) ($row['variance_ecash'] ?? 0);
            $isOverdue = (string) ($row['handoff_status'] ?? '') === 'pending'
                && (string) ($row['handoff_due_at'] ?? '') !== ''
                && strtotime((string) $row['handoff_due_at']) < time();
            $alerts[] = [
                'tone' => 'danger',
                'label' => $isOverdue ? 'Overdue Case' : 'Store-Day Review',
                'title' => (string) ($row['store_name'] ?? 'Store') . ' has an unresolved ' . str_replace('_', ' ', (string) ($row['review_status'] ?? 'review')),
                'detail' => trim(((string) ($row['case_ref'] ?? '') !== '' ? (string) $row['case_ref'] . ' | ' : '') . (string) ($row['business_date'] ?? '-') . ' | Variance PHP ' . number_format($variance, 2) . ' | Owner: ' . ((string) ($row['owner_name'] ?? '') !== '' ? (string) $row['owner_name'] : 'Unassigned') . ($isOverdue ? ' | Acknowledgment overdue' : '')),
                'detail_items' => array_values(array_filter([
                    (string) ($row['case_ref'] ?? '') !== '' ? ['icon' => 'bi-folder2-open', 'text' => (string) $row['case_ref']] : null,
                    ['icon' => 'bi-calendar3', 'text' => (string) ($row['business_date'] ?? '-')],
                    ['icon' => 'bi-cash-stack', 'text' => 'Variance PHP ' . number_format($variance, 2)],
                    ['icon' => 'bi-person', 'text' => 'Owner: ' . ((string) ($row['owner_name'] ?? '') !== '' ? (string) $row['owner_name'] : 'Unassigned')],
                    $isOverdue ? ['icon' => 'bi-clock-history', 'text' => 'Acknowledgment overdue'] : null,
                ])),
                'href' => site_url('admin/stores/' . (int) ($row['store_id'] ?? 0)),
            ];
        }
        foreach ($storesNotOpenRows as $row) {
            $alerts[] = [
                'tone' => 'warning',
                'label' => 'Store Day',
                'title' => (string) ($row['store_name'] ?? 'Store') . ' has not opened today',
                'detail' => 'Officer: ' . ((string) ($row['officer_name'] ?? '') !== '' ? (string) $row['officer_name'] : 'Unassigned'),
                'detail_items' => [
                    ['icon' => 'bi-person-badge', 'text' => 'Officer: ' . ((string) ($row['officer_name'] ?? '') !== '' ? (string) $row['officer_name'] : 'Unassigned')],
                ],
                'href' => site_url('admin/stores/' . (int) ($row['id'] ?? 0)),
            ];
        }
        foreach ($lowStockRows as $row) {
            $stockQty = (int) ($row['stock_qty'] ?? 0);
            $alerts[] = [
                'tone' => $stockQty <= 0 ? 'danger' : 'warning',
                'label' => $stockQty <= 0 ? 'Out of Stock' : 'Low Stock',
                'title' => (string) ($row['name'] ?? 'Product'),
                'detail' => (string) ($row['store_name'] ?? 'Store') . ' | SKU ' . (string) ($row['sku'] ?? '-') . ' | Stock ' . $stockQty . ' / Threshold ' . (int) ($row['threshold_qty'] ?? 10),
                'detail_items' => [
                    ['icon' => 'bi-shop', 'text' => (string) ($row['store_name'] ?? 'Store')],
                    ['icon' => 'bi-upc-scan', 'text' => 'SKU ' . (string) ($row['sku'] ?? '-')],
                    ['icon' => 'bi-box-seam', 'text' => 'Stock ' . $stockQty . ' / Threshold ' . (int) ($row['threshold_qty'] ?? 10)],
                ],
                'href' => site_url('admin/products'),
            ];
        }
        foreach ($overCreditRows as $row) {
            $overAmount = max(0, (float) ($row['current_debt'] ?? 0) - (float) ($row['credit_limit'] ?? 0));
            $alerts[] = [
                'tone' => 'danger',
                'label' => 'Over Credit',
                'title' => (string) ($row['name'] ?? 'User'),
                'detail' => 'Employee ' . (string) ($row['employee_id'] ?? '-') . ' | Over by PHP ' . number_format($overAmount, 2),
                'detail_items' => [
                    ['icon' => 'bi-person-vcard', 'text' => 'Employee ' . (string) ($row['employee_id'] ?? '-')],
                    ['icon' => 'bi-exclamation-triangle', 'text' => 'Over by PHP ' . number_format($overAmount, 2)],
                ],
                'href' => site_url('admin/accounting-debts'),
            ];
        }
        foreach ($inactiveStoreRows as $row) {
            $alerts[] = [
                'tone' => 'warning',
                'label' => 'Inactive Store',
                'title' => (string) ($row['store_name'] ?? 'Store') . ' is inactive',
                'detail' => 'Officer: ' . ((string) ($row['officer_name'] ?? '') !== '' ? (string) $row['officer_name'] : 'Unassigned') . ' | New store operations are unavailable',
                'detail_items' => [
                    ['icon' => 'bi-person-badge', 'text' => 'Officer: ' . ((string) ($row['officer_name'] ?? '') !== '' ? (string) $row['officer_name'] : 'Unassigned')],
                    ['icon' => 'bi-slash-circle', 'text' => 'New store operations are unavailable'],
                ],
                'href' => site_url('admin/stores/' . (int) ($row['id'] ?? 0)),
            ];
        }

        $alertPage = DashboardAlertPagination::paginate($alerts, $requestedAlertsPage, 5);

        return [
            'status' => 'success',
            'status' => 'success',
            'period' => DashboardPeriod::publicMeta($period),
            'summary' => [
                'total_stores' => (int) ($storeSummary['total_stores'] ?? 0),
                'active_store_officers' => $activeStoreOfficers,
                'debt_accounts' => (int) ($debtSummary['debt_accounts'] ?? 0),
                'total_debt' => (float) ($debtSummary['total_debt'] ?? 0),
                'open_alerts' => $openAlerts,
                'inactive_stores' => $inactiveStores,
                'out_of_stock_products' => $outOfStockProducts,
                'low_stock_products' => $lowStockProducts,
                'over_credit_accounts' => $overCreditAccounts,
                'unresolved_store_day_reviews' => $unresolvedReviews,
                'stores_open_today' => (int) ($storeDaySummary['open_today'] ?? 0),
                'stores_closed_today' => (int) ($storeDaySummary['closed_today'] ?? 0),
                'stores_not_open_today' => $storesNotOpenToday,
                'today_transactions' => (int) ($todayTxn['txn_count'] ?? 0),
                'today_sales' => (float) ($todayTxn['sales_total'] ?? 0),
                'period_transactions' => (int) ($todayTxn['txn_count'] ?? 0),
                'period_sales' => (float) ($todayTxn['sales_total'] ?? 0),
            ],
            'health' => [
                'ok' => $openAlerts === 0,
                'message' => $healthText,
                'items' => $healthItems,
            ],
            'analytics' => [
                'range' => [
                    'from' => $rangeStartDate,
                    'to' => $period['to'],
                ],
                'trend' => $trend,
                'payment_breakdown' => $paymentBreakdown,
                'top_stores' => $topStores,
                'top_selling_items' => $topSellingItems,
            ],
            'alerts' => $alertPage['items'],
            'alerts_pagination' => $alertPage['pagination'],
        ];
    }
}
