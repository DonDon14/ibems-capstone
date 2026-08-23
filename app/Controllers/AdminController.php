<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\StoreCategoryModel;
use App\Models\StoreSupervisorModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Models\DebtCashbookEntryModel;
use App\Services\StoreOversightService;
use App\Services\AssetStorageService;
use App\Services\DashboardAlertPagination;
use App\Services\SalaryCreditPolicy;
use App\Services\SalaryScheduleService;
use CodeIgniter\Controller;
use Config\Database;

class AdminController extends Controller
{

    public function dashboard()
    {
        return view('admin/dashboard');
    }

    public function dashboardData()
    {
        $db = Database::connect();
        $requestedAlertsPage = max(1, (int) ($this->request->getGet('alerts_page') ?? 1));
        $today = new \DateTimeImmutable('today');
        $rangeStartDate = $today->modify('-6 days')->format('Y-m-d');
        $rangeStartTs = $rangeStartDate . ' 00:00:00';
        $rangeEndTs = $today->format('Y-m-d') . ' 23:59:59';

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

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todayTxn = $db->table('transactions')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->get()
            ->getRowArray();

        $trendRows = $db->table('transactions')
            ->select('DATE(created_at) AS sale_date, COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->where('created_at >=', $rangeStartTs)
            ->where('created_at <=', $rangeEndTs)
            ->groupBy('DATE(created_at)')
            ->orderBy('sale_date', 'ASC')
            ->get()
            ->getResultArray();

        $trendMap = [];
        foreach ($trendRows as $row) {
            $trendMap[(string) ($row['sale_date'] ?? '')] = [
                'transactions' => (int) ($row['txn_count'] ?? 0),
                'sales' => (float) ($row['sales_total'] ?? 0),
            ];
        }
        $trend = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $today->modify('-' . (6 - $i) . ' days')->format('Y-m-d');
            $trend[] = [
                'date' => $date,
                'transactions' => (int) ($trendMap[$date]['transactions'] ?? 0),
                'sales' => (float) ($trendMap[$date]['sales'] ?? 0),
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

        return $this->response->setJSON([
            'status' => 'success',
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
            ],
            'health' => [
                'ok' => $openAlerts === 0,
                'message' => $healthText,
                'items' => $healthItems,
            ],
            'analytics' => [
                'range' => [
                    'from' => $rangeStartDate,
                    'to' => $today->format('Y-m-d'),
                ],
                'trend' => $trend,
                'payment_breakdown' => $paymentBreakdown,
                'top_stores' => $topStores,
                'top_selling_items' => $topSellingItems,
            ],
            'alerts' => $alertPage['items'],
            'alerts_pagination' => $alertPage['pagination'],
        ]);
    }

    public function storeOps()
    {
        return redirect()->to('/admin/stores');
    }

    public function accountingDebts()
    {
        return view('admin/accounting-debts');
    }

    public function userView()
    {
        return view('admin/user-view');
    }

    public function products()
    {
        return view('admin/products');
    }

    public function salarySchedules()
    {
        $catalog = (new SalaryScheduleService())->catalog(Database::connect());
        if ($catalog === []) {
            return $this->response->setStatusCode(503)->setJSON([
                'status' => 'error',
                'message' => 'Salary schedules are unavailable. Apply the latest development database migration, then refresh this page.',
            ]);
        }
        return $this->response->setJSON([
            'status' => 'success',
            'data' => $catalog,
            'default_credit_percentage' => SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE),
        ]);
    }

    public function audit()
    {
        return view('admin/audit');
    }

    public function auditData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $action = trim((string) $this->request->getGet('action'));
        $entity = trim((string) $this->request->getGet('entity'));
        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $pageSize = max(25, min(200, (int) ($this->request->getGet('page_size') ?? 100)));
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * $pageSize;
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'date')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'date' => 'al.created_at',
            'action' => 'al.action',
            'actor' => 'u.name',
            'entity' => 'al.entity',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];

        $db = Database::connect();
        $query = $db->table('audit_logs al')
            ->select('al.id, al.actor_id, al.action, al.entity, al.entity_id, al.payload_json, al.created_at, u.name AS actor_name, u.email AS actor_email, u.profile_image_url AS actor_profile_image_url')
            ->join('users u', 'u.id = al.actor_id', 'left');

        if ($action !== '') {
            $query->where('al.action', $action);
        }
        if ($entity !== '') {
            $query->where('al.entity', $entity);
        }
        if ($dateFrom !== '') {
            $query->where('al.created_at >=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $query->where('al.created_at <=', $dateTo . ' 23:59:59');
        }
        if ($q !== '') {
            $query->groupStart()
                ->like('al.action', $q)
                ->orLike('al.entity', $q)
                ->orLike('al.payload_json', $q)
                ->orLike('u.name', $q)
                ->orLike('u.email', $q)
                ->groupEnd();
        }

        $filteredTotal = (clone $query)->countAllResults();
        $totalPages = max(1, (int) ceil($filteredTotal / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('al.id', $sortDir)
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $summary = $db->table('audit_logs')
            ->select('COUNT(*) AS total_events, COUNT(DISTINCT actor_id) AS actor_count, COUNT(DISTINCT action) AS action_count')
            ->get()
            ->getRowArray() ?? [];
        $todayEvents = $db->table('audit_logs')
            ->where('created_at >=', date('Y-m-d 00:00:00'))
            ->where('created_at <=', date('Y-m-d 23:59:59'))
            ->countAllResults();

        $actions = $db->table('audit_logs')
            ->select('action')
            ->groupBy('action')
            ->orderBy('action', 'ASC')
            ->get()
            ->getResultArray();

        $entities = $db->table('audit_logs')
            ->select('entity')
            ->where('entity IS NOT NULL', null, false)
            ->where('entity !=', '')
            ->groupBy('entity')
            ->orderBy('entity', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'total_events' => (int) ($summary['total_events'] ?? 0),
                'today_events' => $todayEvents,
                'actor_count' => (int) ($summary['actor_count'] ?? 0),
                'action_count' => (int) ($summary['action_count'] ?? 0),
                'visible_events' => $filteredTotal,
            ],
            'actions' => array_values(array_map(static fn(array $row): string => (string) ($row['action'] ?? ''), $actions)),
            'entities' => array_values(array_map(static fn(array $row): string => (string) ($row['entity'] ?? ''), $entities)),
            'data' => array_map(function (array $row): array {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                return [
                    'id' => (int) $row['id'],
                    'actor_id' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
                    'actor_name' => (string) ($row['actor_name'] ?? 'System'),
                    'actor_email' => (string) ($row['actor_email'] ?? ''),
                    'actor_profile_image_url' => $row['actor_profile_image_url'] ?? null,
                    'action' => (string) ($row['action'] ?? ''),
                    'action_label' => $this->formatAuditAction((string) ($row['action'] ?? '')),
                    'entity' => (string) ($row['entity'] ?? ''),
                    'entity_id' => isset($row['entity_id']) ? (int) $row['entity_id'] : null,
                    'payload' => $payload,
                    'payload_summary' => $this->summarizeAuditPayload($payload),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $rows),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $filteredTotal,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function productsData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $stockStatus = trim((string) $this->request->getGet('stock_status'));
        $category = trim((string) $this->request->getGet('category'));
        $supplier = trim((string) $this->request->getGet('supplier'));
        $includeInactive = (int) ($this->request->getGet('include_inactive') ?? 0) === 1;
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'store')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pageSize = max(10, min(100, (int) ($this->request->getGet('page_size') ?? 25)));
        $offset = ($page - 1) * $pageSize;
        $sortColumns = [
            'store' => 's.store_name',
            'name' => 'p.name',
            'category' => 'p.category',
            'supplier' => 'p.supplier',
            'price' => 'p.price',
            'stock' => 'p.stock_qty',
            'updated' => 'p.updated_at',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['store'];

        $db = Database::connect();
        $query = $db->table('products p')
            ->select('p.id, p.store_id, p.sku, p.name, p.variant_label, p.category, p.supplier, p.location_bin, p.barcode, p.image_url, p.price, p.stock_qty, COALESCE(p.low_stock_threshold, 10) AS low_stock_threshold, p.is_active, p.updated_at, s.store_name')
            ->join('stores s', 's.id = p.store_id', 'inner');

        if ($storeId > 0) {
            $query->where('p.store_id', $storeId);
        }
        if ($category !== '') {
            $query->where('p.category', $category);
        }
        if ($supplier !== '') {
            $query->where('p.supplier', $supplier);
        }
        if ($stockStatus === 'out') {
            $query->where('p.stock_qty <=', 0);
        } elseif ($stockStatus === 'low') {
            $query->where('p.stock_qty >', 0);
            $query->where('p.stock_qty <= COALESCE(p.low_stock_threshold, 10)', null, false);
        } elseif ($stockStatus === 'healthy') {
            $query->where('p.stock_qty > COALESCE(p.low_stock_threshold, 10)', null, false);
        }
        if (!$includeInactive) {
            $query->where('p.is_active', true);
        }
        if ($q !== '') {
            $query->groupStart()
                ->like('p.name', $q)
                ->orLike('p.sku', $q)
                ->orLike('p.category', $q)
                ->orLike('p.supplier', $q)
                ->orLike('p.location_bin', $q)
                ->orLike('p.barcode', $q)
                ->orLike('s.store_name', $q)
                ->groupEnd();
        }

        $total = (clone $query)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('p.name', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $summaryQuery = $db->table('products p')
            ->select('COUNT(*) AS total_products, SUM(CASE WHEN p.is_active = TRUE THEN 1 ELSE 0 END) AS active_products, SUM(CASE WHEN p.is_active = TRUE AND p.stock_qty <= 0 THEN 1 ELSE 0 END) AS out_of_stock, SUM(CASE WHEN p.is_active = TRUE AND p.stock_qty > 0 AND p.stock_qty <= COALESCE(p.low_stock_threshold, 10) THEN 1 ELSE 0 END) AS low_stock, SUM(CASE WHEN p.is_active = FALSE THEN 1 ELSE 0 END) AS inactive_products')
            ->join('stores s', 's.id = p.store_id', 'inner');
        if ($storeId > 0) {
            $summaryQuery->where('p.store_id', $storeId);
        }
        $summary = $summaryQuery->get()->getRowArray() ?? [];

        $stores = $db->table('stores')
            ->select('id, store_name')
            ->where('is_active', true)
            ->orderBy('store_name', 'ASC')
            ->get()
            ->getResultArray();

        $categories = $db->table('products')
            ->select('category')
            ->where('category IS NOT NULL', null, false)
            ->where('category !=', '')
            ->groupBy('category')
            ->orderBy('category', 'ASC')
            ->get()
            ->getResultArray();

        $suppliers = $db->table('products')
            ->select('supplier')
            ->where('supplier IS NOT NULL', null, false)
            ->where('supplier !=', '')
            ->groupBy('supplier')
            ->orderBy('supplier', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'total_products' => (int) ($summary['total_products'] ?? 0),
                'active_products' => (int) ($summary['active_products'] ?? 0),
                'out_of_stock' => (int) ($summary['out_of_stock'] ?? 0),
                'low_stock' => (int) ($summary['low_stock'] ?? 0),
                'inactive_products' => (int) ($summary['inactive_products'] ?? 0),
                'visible_products' => $total,
            ],
            'stores' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'store_name' => (string) $row['store_name'],
                ];
            }, $stores),
            'categories' => array_values(array_map(static fn(array $row): string => (string) ($row['category'] ?? ''), $categories)),
            'suppliers' => array_values(array_map(static fn(array $row): string => (string) ($row['supplier'] ?? ''), $suppliers)),
            'data' => array_map(static function (array $row): array {
                $stockQty = (int) ($row['stock_qty'] ?? 0);
                $threshold = (int) ($row['low_stock_threshold'] ?? 10);
                $stockStatus = $stockQty <= 0 ? 'out' : ($stockQty <= $threshold ? 'low' : 'healthy');
                return [
                    'id' => (int) $row['id'],
                    'store_id' => (int) $row['store_id'],
                    'store_name' => (string) $row['store_name'],
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'variant_label' => (string) ($row['variant_label'] ?? ''),
                    'category' => (string) ($row['category'] ?? ''),
                    'supplier' => (string) ($row['supplier'] ?? ''),
                    'location_bin' => (string) ($row['location_bin'] ?? ''),
                    'barcode' => (string) ($row['barcode'] ?? ''),
                    'image_url' => (string) ($row['image_url'] ?? ''),
                    'price' => (float) ($row['price'] ?? 0),
                    'stock_qty' => $stockQty,
                    'low_stock_threshold' => $threshold,
                    'stock_status' => $stockStatus,
                    'is_active' => ibems_bool($row['is_active'] ?? false),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }, $rows),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function updateProduct()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');

        $productId = (int) ($request['product_id'] ?? 0);
        $storeId = (int) ($request['store_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $inputImageUrl = trim((string) ($request['image_url'] ?? ''));
        $price = (float) ($request['price'] ?? -1);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;

        if ($productId <= 0 || $storeId <= 0 || $sku === '' || $name === '' || $price < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid product payload.',
            ]);
        }

        $db = Database::connect();
        $productModel = new \App\Models\ProductModel();
        $auditLogModel = new AuditLogModel();

        $product = $productModel->find($productId);
        if (!$product || (int) ($product['store_id'] ?? 0) !== $storeId) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Product not found.',
            ]);
        }

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

        $db->transStart();

        $productModel->update($productId, [
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category !== '' ? $category : 'General',
            'barcode' => $barcode !== '' ? $barcode : null,
            'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
            'price' => $price,
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_UPDATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'before' => [
                    'sku' => $product['sku'] ?? '',
                    'name' => $product['name'] ?? '',
                    'variant_label' => $product['variant_label'] ?? null,
                    'category' => $product['category'] ?? '',
                    'barcode' => $product['barcode'] ?? null,
                    'image_url' => $product['image_url'] ?? null,
                    'price' => (float) ($product['price'] ?? 0),
                    'is_active' => ibems_bool($product['is_active'] ?? false),
                ],
                'after' => [
                    'sku' => $sku,
                    'name' => $name,
                    'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                    'category' => $category !== '' ? $category : 'General',
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
                    'price' => $price,
                    'is_active' => (bool) $isActive,
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

    public function createProduct()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');

        $storeId = (int) ($request['store_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $inputImageUrl = trim((string) ($request['image_url'] ?? ''));
        $price = (float) ($request['price'] ?? -1);
        $stockQty = (int) ($request['stock_qty'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;

        if ($storeId <= 0 || $sku === '' || $name === '' || $price < 0 || $stockQty < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid product payload.',
            ]);
        }

        $storeModel = new StoreModel();
        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        $db = Database::connect();
        $productModel = new ProductModel();
        $categoryModel = new StoreCategoryModel();
        $auditLogModel = new AuditLogModel();

        if ($productModel->where('store_id', $storeId)->where('sku', $sku)->first()) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'SKU already exists in this store.',
            ]);
        }

        if ($barcode !== '') {
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

        $category = $category !== '' ? $category : 'General';
        $categoryModel->ensureCategory($storeId, $category);

        try {
            $resolvedImageUrl = $this->resolveProductImageUrl($inputImageUrl, null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $productId = $productModel->insert([
            'store_id' => $storeId,
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category,
            'barcode' => $barcode !== '' ? $barcode : null,
            'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
            'price' => $price,
            'stock_qty' => $stockQty,
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_CREATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => (int) $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'sku' => $sku,
                'name' => $name,
                'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                'category' => $category,
                'barcode' => $barcode !== '' ? $barcode : null,
                'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
                'price' => $price,
                'stock_qty' => $stockQty,
                'is_active' => (bool) $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus() || !$productId) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to create product.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'product' => $productModel->find($productId),
        ]);
    }

    public function toggleProductStatus()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $productId = (int) ($request['product_id'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? -1);

        if ($productId <= 0 || !in_array($isActive, [0, 1], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid status payload.',
            ]);
        }

        $productModel = new ProductModel();
        $auditLogModel = new AuditLogModel();
        $product = $productModel->find($productId);
        if (!$product) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Product not found.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $productModel->update($productId, [
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_TOGGLE_PRODUCT_STATUS',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => (int) ($product['store_id'] ?? 0),
                'sku' => (string) ($product['sku'] ?? ''),
                'previous_is_active' => (int) ($product['is_active'] ?? 0),
                'new_is_active' => $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update product status.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    public function accountingDebtsData()
    {
        $db = Database::connect();
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $summary = $db->table('balances')
            ->select('COUNT(*) AS account_count, SUM(CASE WHEN current_debt > 0 THEN 1 ELSE 0 END) AS debt_accounts, COALESCE(SUM(current_debt), 0) AS total_debt')
            ->get()
            ->getRowArray();

        $topDebts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(25)
            ->get()
            ->getResultArray();

        $auditRows = $db->table('audit_logs')
            ->select('payload_json')
            ->whereIn('action', ['ACCOUNTING_DEDUCT_DEBT', 'ACCOUNTING_DEDUCT_FULL_DEBT'])
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->get()
            ->getResultArray();

        $todayDeductionCount = 0;
        $todayDeductionAmount = 0.0;
        foreach ($auditRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $amount = (float) ($payload['deducted_amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $todayDeductionCount++;
            $todayDeductionAmount += $amount;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'account_count' => (int) ($summary['account_count'] ?? 0),
                'debt_accounts' => (int) ($summary['debt_accounts'] ?? 0),
                'total_debt' => (float) ($summary['total_debt'] ?? 0),
                'today_deduction_count' => $todayDeductionCount,
                'today_deduction_amount' => $todayDeductionAmount,
            ],
            'top_debts' => array_map(static function (array $row): array {
                return [
                    'user_id' => (int) $row['user_id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'current_debt' => (float) $row['current_debt'],
                    'credit_limit' => (float) $row['credit_limit'],
                ];
            }, $topDebts),
        ]);
    }

    public function accountingDebtUserDetail(int $userId)
    {
        if ($userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user account.',
            ]);
        }

        $historyPage = max(1, (int) ($this->request->getGet('page') ?? 1));
        $historyPageSize = max(5, min(50, (int) ($this->request->getGet('page_size') ?? 10)));
        $historyStoreId = max(0, (int) ($this->request->getGet('store_id') ?? 0));
        $historyDateFrom = trim((string) ($this->request->getGet('date_from') ?? ''));
        $historyDateTo = trim((string) ($this->request->getGet('date_to') ?? ''));
        $historyDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateFrom) ? $historyDateFrom : '';
        $historyDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateTo) ? $historyDateTo : '';
        if ($historyDateFrom !== '' && $historyDateTo !== '' && $historyDateFrom > $historyDateTo) {
            return $this->response->setStatusCode(422)->setJSON([
                'status' => 'error',
                'message' => 'The start date must be on or before the end date.',
            ]);
        }

        $db = Database::connect();
        $optionalUserFields = [];
        foreach (['profile_image_url', 'base_salary', 'employment_type', 'salary_grade', 'salary_step', 'salary_effective_date'] as $field) {
            $optionalUserFields[] = $db->fieldExists($field, 'users') ? 'u.' . $field : 'NULL AS ' . $field;
        }
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances')
            ? 'b.credit_rate'
            : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';

        $user = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.user_type, u.is_active, ' . implode(', ', $optionalUserFields) . ', b.credit_limit, b.current_debt, b.updated_at AS balance_updated_at, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'User account not found.',
            ]);
        }

        $creditLimit = (float) ($user['credit_limit'] ?? 0);
        $currentDebt = (float) ($user['current_debt'] ?? 0);
        $creditPercentage = SalaryCreditPolicy::percentageFromRate((float) ($user['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE));

        $debtHistory = [];
        $debtActivityTotals = ['total_added' => 0.0, 'total_reduced' => 0.0, 'activity_count' => 0];
        $historyTotal = 0;
        if ($db->tableExists('debt_cashbook_entries')) {
            $totalsRow = $db->table('debt_cashbook_entries')
                ->select("COUNT(*) AS activity_count, COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS total_added, COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS total_reduced", false)
                ->where('user_id', $userId)
                ->get()
                ->getRowArray() ?? [];
            $debtActivityTotals = [
                'total_added' => (float) ($totalsRow['total_added'] ?? 0),
                'total_reduced' => (float) ($totalsRow['total_reduced'] ?? 0),
                'activity_count' => (int) ($totalsRow['activity_count'] ?? 0),
            ];

            $historyQuery = $db->table('debt_cashbook_entries dce')
                ->join('users actor', 'actor.id = dce.actor_id', 'left')
                ->join('transactions history_txn', "dce.reference_type = 'transaction' AND history_txn.id = dce.reference_id", 'left', false);
            if ($db->tableExists('store_cash_movements')) {
                $historyQuery->join('store_cash_movements history_movement', "dce.reference_type = 'store_cash_movement' AND history_movement.id = dce.reference_id", 'left', false);
                $historyStoreExpression = 'COALESCE(history_txn.store_id, history_movement.store_id)';
            } else {
                $historyStoreExpression = 'history_txn.store_id';
            }
            $historyQuery->join('stores history_store', 'history_store.id = ' . $historyStoreExpression, 'left', false)
                ->where('dce.user_id', $userId);
            if ($historyStoreId > 0) {
                $historyQuery->where($historyStoreExpression, $historyStoreId, false);
            }
            if ($historyDateFrom !== '') {
                $historyQuery->where('dce.created_at >=', $historyDateFrom . ' 00:00:00');
            }
            if ($historyDateTo !== '') {
                $historyQuery->where('dce.created_at <=', $historyDateTo . ' 23:59:59');
            }

            $historyTotal = (clone $historyQuery)->countAllResults();
            $historyTotalPages = max(1, (int) ceil($historyTotal / $historyPageSize));
            $historyPage = min($historyPage, $historyTotalPages);
            $debtHistory = $historyQuery
                ->select('dce.id, dce.entry_type, dce.direction, dce.amount, dce.debt_before, dce.debt_after, dce.reference_type, dce.reference_id, dce.remarks, dce.created_at, actor.name AS actor_name, history_store.id AS store_id, history_store.store_name')
                ->orderBy('dce.id', 'DESC')
                ->limit($historyPageSize, ($historyPage - 1) * $historyPageSize)
                ->get()
                ->getResultArray();
        }

        $storesById = [];
        if ($db->tableExists('debt_cashbook_entries') && $db->tableExists('transactions') && $db->tableExists('stores')) {
            $debtByStore = $db->table('debt_cashbook_entries dce')
                ->select('s.id AS store_id, s.store_name, COUNT(dce.id) AS debt_transaction_count, COALESCE(SUM(dce.amount), 0) AS debt_added, MAX(dce.created_at) AS last_activity_at')
                ->join('transactions t', "dce.reference_type = 'transaction' AND t.id = dce.reference_id", 'inner', false)
                ->join('stores s', 's.id = t.store_id', 'inner')
                ->where('dce.user_id', $userId)
                ->where('dce.direction', 'debit')
                ->groupBy('s.id, s.store_name')
                ->get()
                ->getResultArray();
            foreach ($debtByStore as $row) {
                $storeId = (int) ($row['store_id'] ?? 0);
                $storesById[$storeId] = [
                    'store_id' => $storeId,
                    'store_name' => $row['store_name'] ?: 'Unknown store',
                    'debt_transaction_count' => (int) ($row['debt_transaction_count'] ?? 0),
                    'debt_added' => (float) ($row['debt_added'] ?? 0),
                    'store_repayments' => 0.0,
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                ];
            }

            if ($db->tableExists('store_cash_movements')) {
                $repaymentsByStore = $db->table('debt_cashbook_entries dce')
                    ->select('s.id AS store_id, s.store_name, COALESCE(SUM(dce.amount), 0) AS store_repayments, MAX(dce.created_at) AS last_activity_at')
                    ->join('store_cash_movements scm', "dce.reference_type = 'store_cash_movement' AND scm.id = dce.reference_id", 'inner', false)
                    ->join('stores s', 's.id = scm.store_id', 'inner')
                    ->where('dce.user_id', $userId)
                    ->where('dce.direction', 'credit')
                    ->groupBy('s.id, s.store_name')
                    ->get()
                    ->getResultArray();
                foreach ($repaymentsByStore as $row) {
                    $storeId = (int) ($row['store_id'] ?? 0);
                    $storesById[$storeId] ??= [
                        'store_id' => $storeId,
                        'store_name' => $row['store_name'] ?: 'Unknown store',
                        'debt_transaction_count' => 0,
                        'debt_added' => 0.0,
                        'store_repayments' => 0.0,
                        'last_activity_at' => null,
                    ];
                    $storesById[$storeId]['store_repayments'] = (float) ($row['store_repayments'] ?? 0);
                    if ((string) ($row['last_activity_at'] ?? '') > (string) ($storesById[$storeId]['last_activity_at'] ?? '')) {
                        $storesById[$storeId]['last_activity_at'] = $row['last_activity_at'];
                    }
                }
            }
        }
        $stores = array_values($storesById);
        usort($stores, static fn(array $left, array $right): int => strcmp((string) ($right['last_activity_at'] ?? ''), (string) ($left['last_activity_at'] ?? '')));

        return $this->response->setJSON([
            'status' => 'success',
            'data' => [
                'general' => [
                    'user_id' => (int) $user['id'],
                    'employee_id' => $user['employee_id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'profile_image_url' => $user['profile_image_url'] ?? null,
                    'user_type' => $user['user_type'],
                    'is_active' => ibems_bool($user['is_active'] ?? false),
                    'base_salary' => (float) ($user['base_salary'] ?? 0),
                    'employment_type' => $user['employment_type'] ?? null,
                    'salary_grade' => $user['salary_grade'] ?? null,
                    'salary_step' => $user['salary_step'] ?? null,
                    'salary_effective_date' => $user['salary_effective_date'] ?? null,
                ],
                'debt' => [
                    'current_debt' => $currentDebt,
                    'credit_limit' => $creditLimit,
                    'available_credit' => max(0, $creditLimit - $currentDebt),
                    'credit_percentage' => $creditPercentage,
                    'usage_percentage' => $creditLimit > 0 ? min(100, ($currentDebt / $creditLimit) * 100) : 0,
                    'total_added' => $debtActivityTotals['total_added'],
                    'total_reduced' => $debtActivityTotals['total_reduced'],
                    'activity_count' => $debtActivityTotals['activity_count'],
                    'updated_at' => $user['balance_updated_at'] ?? null,
                    'history' => array_map(static fn(array $row): array => [
                        'id' => (int) $row['id'],
                        'entry_type' => $row['entry_type'],
                        'direction' => $row['direction'],
                        'amount' => (float) $row['amount'],
                        'debt_before' => (float) $row['debt_before'],
                        'debt_after' => (float) $row['debt_after'],
                        'reference_type' => $row['reference_type'],
                        'reference_id' => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
                        'remarks' => $row['remarks'],
                        'actor_name' => $row['actor_name'] ?: 'System',
                        'store_id' => isset($row['store_id']) ? (int) $row['store_id'] : null,
                        'store_name' => $row['store_name'] ?? null,
                        'created_at' => $row['created_at'],
                    ], $debtHistory),
                    'pagination' => [
                        'page' => $historyPage,
                        'page_size' => $historyPageSize,
                        'total' => $historyTotal,
                        'total_pages' => max(1, (int) ceil($historyTotal / $historyPageSize)),
                        'store_id' => $historyStoreId > 0 ? $historyStoreId : null,
                        'date_from' => $historyDateFrom !== '' ? $historyDateFrom : null,
                        'date_to' => $historyDateTo !== '' ? $historyDateTo : null,
                    ],
                ],
                'stores' => array_map(static fn(array $row): array => [
                    'store_id' => (int) ($row['store_id'] ?? 0),
                    'store_name' => $row['store_name'] ?: 'Unknown store',
                    'debt_transaction_count' => (int) ($row['debt_transaction_count'] ?? 0),
                    'debt_added' => (float) ($row['debt_added'] ?? 0),
                    'store_repayments' => (float) ($row['store_repayments'] ?? 0),
                    'net_store_activity' => max(0, (float) ($row['debt_added'] ?? 0) - (float) ($row['store_repayments'] ?? 0)),
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                ], $stores),
            ],
        ]);
    }

    public function userViewData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $db = Database::connect();
        $salaryProfileSelect = [];
        foreach (['employment_type', 'salary_grade', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            $salaryProfileSelect[] = $db->fieldExists($field, 'users') ? 'u.' . $field : 'NULL AS ' . $field;
        }
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances') ? 'b.credit_rate' : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';
        $query = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.profile_image_url, u.role, u.user_type, u.is_active, ' . implode(', ', $salaryProfileSelect) . ', b.user_id AS balance_user_id, b.current_debt, b.credit_limit, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left');

        if ($q !== '') {
            $query->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        $rows = $query->orderBy('u.name', 'ASC')->limit(200)->get()->getResultArray();
        $rolesMap = $this->buildRolesMap($rows);
        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row) use ($rolesMap): array {
                $userId = (int) $row['id'];
                return [
                    'id' => $userId,
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'role' => $row['role'],
                    'roles' => $rolesMap[$userId] ?? [strtoupper((string) ($row['role'] ?? 'USER'))],
                    'user_type' => $row['user_type'],
                    'is_active' => ibems_bool($row['is_active']),
                    'financial_profile_configured' => in_array(strtolower((string) ($row['user_type'] ?? '')), ['faculty', 'staff'], true)
                        && $row['balance_user_id'] !== null
                        && trim((string) ($row['employment_type'] ?? '')) !== ''
                        && trim((string) ($row['salary_grade'] ?? '')) !== ''
                        && trim((string) ($row['salary_effective_date'] ?? '')) !== ''
                        && (int) ($row['salary_schedule_id'] ?? 0) > 0,
                    'current_debt' => (float) ($row['current_debt'] ?? 0),
                    'credit_limit' => (float) ($row['credit_limit'] ?? 0),
                    'credit_percentage' => SalaryCreditPolicy::percentageFromRate((float) ($row['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE)),
                ];
            }, $rows),
        ]);
    }

    public function userViewDetail(int $userId)
    {
        if ($userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user id.',
            ]);
        }

        $db = Database::connect();
        $salaryProfileSelect = [];
        foreach (['employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            $salaryProfileSelect[] = $db->fieldExists($field, 'users') ? 'u.' . $field : 'NULL AS ' . $field;
        }
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances') ? 'b.credit_rate' : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';
        $row = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.profile_image_url, u.role, u.user_type, u.base_salary, ' . implode(', ', $salaryProfileSelect) . ', u.is_active, u.created_at, b.user_id AS balance_user_id, b.current_debt, b.credit_limit, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'User not found.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'data' => [
                'id' => (int) $row['id'],
                'employee_id' => $row['employee_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'profile_image_url' => $row['profile_image_url'] ?? null,
                'role' => $row['role'],
                'roles' => $this->resolveUserRoles((int) $row['id'], (string) ($row['role'] ?? 'USER')),
                'user_type' => $row['user_type'],
                'base_salary' => (float) ($row['base_salary'] ?? 0),
                'employment_type' => $row['employment_type'] ?? null,
                'salary_grade' => $row['salary_grade'] ?? null,
                'salary_step' => isset($row['salary_step']) ? (int) $row['salary_step'] : null,
                'salary_effective_date' => $row['salary_effective_date'] ?? null,
                'salary_schedule_id' => isset($row['salary_schedule_id']) ? (int) $row['salary_schedule_id'] : null,
                'is_active' => ibems_bool($row['is_active']),
                'created_at' => $row['created_at'],
                'financial_profile_configured' => $row['balance_user_id'] !== null
                    && trim((string) ($row['employment_type'] ?? '')) !== ''
                    && trim((string) ($row['salary_grade'] ?? '')) !== ''
                    && trim((string) ($row['salary_effective_date'] ?? '')) !== ''
                    && (int) ($row['salary_schedule_id'] ?? 0) > 0,
                'current_debt' => (float) ($row['current_debt'] ?? 0),
                'credit_limit' => (float) ($row['credit_limit'] ?? 0),
                'credit_percentage' => SalaryCreditPolicy::percentageFromRate((float) ($row['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE)),
            ],
        ]);
    }

    public function createUser()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');

        $employeeId = trim((string) ($request['employee_id'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $userType = strtolower(trim((string) ($request['user_type'] ?? 'staff')));
        $roles = $this->extractRolesFromRequest($request);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;
        $password = (string) ($request['password'] ?? '');

        if ($name === '' || $email === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Name and email are required.',
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid email address.',
            ]);
        }

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        $roles = $this->sanitizeRoles($roles, ['USER']);
        foreach ($roles as $selectedRole) {
            if (!in_array($selectedRole, $allowedRoles, true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid role.',
                ]);
            }
        }
        if (!in_array($userType, $allowedTypes, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user type.',
            ]);
        }

        $db = Database::connect();
        $userModel = new UserModel();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();

        $financialProfile = null;
        if (in_array($userType, ['faculty', 'staff'], true)) {
            try {
                $financialProfile = $this->resolveEmployeeFinancialProfile($db, $request);
            } catch (\InvalidArgumentException $exception) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($userModel->where('email', $email)->first()) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Email already exists.',
            ]);
        }
        if ($employeeId !== '' && $userModel->where('employee_id', $employeeId)->first()) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Employee ID already exists.',
            ]);
        }

        $primaryRole = $this->pickPrimaryRole($roles);
        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : password_hash('123456', PASSWORD_BCRYPT);
        $initialCreditLimit = (float) ($financialProfile['credit_limit'] ?? 0);

        try {
            $profileImageUrl = $this->storeUserProfileImage();
        } catch (\Throwable $exception) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $exception->getMessage()]);
        }

        $db->transStart();

        $userPayload = [
            'employee_id' => $employeeId !== '' ? $employeeId : null,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
            'profile_image_url' => $profileImageUrl,
            'role' => $primaryRole,
            'user_type' => $userType,
            'qr_token' => bin2hex(random_bytes(16)),
            'base_salary' => (float) ($financialProfile['monthly_salary'] ?? 0),
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if ($financialProfile !== null) {
            $userPayload += [
                'employment_type' => $financialProfile['employment_type'],
                'salary_grade' => $financialProfile['salary_grade_label'],
                'salary_step' => $financialProfile['salary_step'],
                'salary_effective_date' => $financialProfile['effective_date'],
                'salary_schedule_id' => $financialProfile['schedule_id'],
            ];
        }
        $db->table('users')->insert($userPayload);
        $createdUser = $db->table('users')->select('id')->where('email', $email)->get()->getRowArray();
        $userId = (int) ($createdUser['id'] ?? 0);

        if ($userId) {
            $this->syncUserRoles((int) $userId, $roles);

            $balancePayload = [
                'user_id' => (int) $userId,
                'credit_limit' => $initialCreditLimit,
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($db->fieldExists('credit_rate', 'balances')) {
                $balancePayload['credit_rate'] = (float) ($financialProfile['credit_rate'] ?? 0);
            }
            $balanceModel->insert($balancePayload);
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_CREATE_USER',
            'entity' => 'users',
            'entity_id' => (int) $userId,
            'payload_json' => json_encode([
                'email' => $email,
                'role' => $primaryRole,
                'roles' => $roles,
                'user_type' => $userType,
                'initial_credit_limit' => $initialCreditLimit,
                'salary_profile' => $financialProfile,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus() || !$userId) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to create user.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'user_id' => (int) $userId,
            'initial_credit_limit' => $initialCreditLimit,
            'credit_percentage' => (float) ($financialProfile['credit_percentage'] ?? 0),
        ]);
    }

    public function updateUser()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $userId = (int) ($request['user_id'] ?? 0);

        $employeeId = trim((string) ($request['employee_id'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $userType = strtolower(trim((string) ($request['user_type'] ?? 'staff')));
        $roles = $this->extractRolesFromRequest($request);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;

        if ($userId <= 0 || $name === '' || $email === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user update payload.',
            ]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid email address.',
            ]);
        }

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        $roles = $this->sanitizeRoles($roles, ['USER']);
        foreach ($roles as $selectedRole) {
            if (!in_array($selectedRole, $allowedRoles, true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid role or user type.',
                ]);
            }
        }
        if (!in_array($userType, $allowedTypes, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid role or user type.',
            ]);
        }

        $db = Database::connect();
        $userModel = new UserModel();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();

        $user = $userModel->find($userId);
        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'User not found.',
            ]);
        }
        $existingBalance = $balanceModel->find($userId);
        $financialProfile = null;
        if (in_array($userType, ['faculty', 'staff'], true)) {
            try {
                $financialProfile = $this->resolveEmployeeFinancialProfile($db, $request, array_merge($user, $existingBalance ?? []));
            } catch (\InvalidArgumentException $exception) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $sameEmail = $db->table('users')->where('email', $email)->where('id !=', $userId)->get()->getRowArray();
        if ($sameEmail) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Email already exists.',
            ]);
        }
        if ($employeeId !== '') {
            $sameEmp = $db->table('users')->where('employee_id', $employeeId)->where('id !=', $userId)->get()->getRowArray();
            if ($sameEmp) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Employee ID already exists.',
                ]);
            }
        }

        $primaryRole = $this->pickPrimaryRole($roles);
        try {
            $profileImageUrl = $this->storeUserProfileImage((string) ($user['profile_image_url'] ?? ''));
        } catch (\Throwable $exception) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $exception->getMessage()]);
        }
        $db->transStart();

        $userPayload = [
            'employee_id' => $employeeId !== '' ? $employeeId : null,
            'name' => $name,
            'email' => $email,
            'role' => $primaryRole,
            'user_type' => $userType,
            'is_active' => $isActive,
        ];
        if ($profileImageUrl !== null) {
            $userPayload['profile_image_url'] = $profileImageUrl;
        }
        if ($financialProfile !== null) {
            $userPayload += [
                'base_salary' => $financialProfile['monthly_salary'],
                'employment_type' => $financialProfile['employment_type'],
                'salary_grade' => $financialProfile['salary_grade_label'],
                'salary_step' => $financialProfile['salary_step'],
                'salary_effective_date' => $financialProfile['effective_date'],
                'salary_schedule_id' => $financialProfile['schedule_id'],
            ];
        }
        $db->table('users')->where('id', $userId)->update($userPayload);
        $this->syncUserRoles($userId, $roles);

        $balance = $existingBalance;
        if ($balance) {
            $balancePayload = [
                'credit_limit' => (float) ($financialProfile['credit_limit'] ?? 0),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($db->fieldExists('credit_rate', 'balances')) {
                $balancePayload['credit_rate'] = (float) ($financialProfile['credit_rate'] ?? 0);
            }
            $balanceModel->update($userId, $balancePayload);
        } else {
            $balancePayload = [
                'user_id' => $userId,
                'credit_limit' => (float) ($financialProfile['credit_limit'] ?? 0),
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($db->fieldExists('credit_rate', 'balances')) {
                $balancePayload['credit_rate'] = (float) ($financialProfile['credit_rate'] ?? 0);
            }
            $balanceModel->insert($balancePayload);
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_UPDATE_USER',
            'entity' => 'users',
            'entity_id' => $userId,
            'payload_json' => json_encode([
                'email' => $email,
                'role' => $primaryRole,
                'roles' => $roles,
                'user_type' => $userType,
                'is_active' => $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update user.',
            ]);
        }

        if ($userId === (int) session()->get('user_id')) {
            session()->set([
                'name' => $name,
                'email' => $email,
                'profile_image_url' => $profileImageUrl ?? ($user['profile_image_url'] ?? null),
            ]);
            ibems_refresh_session_roles(true);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    public function importUsersCsv()
    {
        $actorId = (int) session()->get('user_id');
        $file = $this->request->getFile('csv_file');
        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Please upload a valid CSV file.',
            ]);
        }

        $handle = fopen($file->getTempName(), 'rb');
        if ($handle === false) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Unable to read CSV.',
            ]);
        }

        $headersRaw = fgetcsv($handle, null, ',', '"', '');
        $headers = is_array($headersRaw) ? array_map(static fn($h): string => strtolower(trim((string) $h)), $headersRaw) : [];
        $required = ['name', 'email', 'user_type'];
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                fclose($handle);
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => "Missing required column: {$field}",
                ]);
            }
        }

        $db = Database::connect();
        $userModel = new UserModel();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();
        $standardProfile = (new SalaryScheduleService())->standardProfile($db);

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        $total = 0;
        $created = 0;
        $updated = 0;
        $invalid = 0;

        $db->transException(true)->transStart();
        try {
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $total++;
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = trim((string) ($values[$i] ?? ''));
            }

            $name = $row['name'] ?? '';
            $email = strtolower($row['email'] ?? '');
            $role = strtoupper($row['role'] ?? 'USER');
            if ($role === '') {
                $role = 'USER';
            }
            $roles = $this->parseRoleList($role);
            $userType = strtolower($row['user_type'] ?? '');
            $employeeId = $row['employee_id'] ?? '';
            $isActive = isset($row['is_active']) ? (int) $row['is_active'] === 1 : true;

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($roles) || !in_array($userType, $allowedTypes, true)) {
                $invalid++;
                continue;
            }
            foreach ($roles as $selectedRole) {
                if (!in_array($selectedRole, $allowedRoles, true)) {
                    $invalid++;
                    continue 2;
                }
            }
            $primaryRole = $this->pickPrimaryRole($roles);

            $existing = $db->table('users')->where('email', $email)->get()->getRowArray();
            if (!$existing && $employeeId !== '') {
                $existing = $db->table('users')->where('employee_id', $employeeId)->get()->getRowArray();
            }

            $needsStandardProfile = in_array($userType, ['faculty', 'staff'], true)
                && (!$existing || empty($existing['salary_schedule_id']));
            if ($needsStandardProfile && $standardProfile === null) {
                throw new \RuntimeException('The standard salary schedule is unavailable. Apply the latest database migration before importing employees.');
            }
            $standardCreditRate = SalaryCreditPolicy::DEFAULT_CREDIT_RATE;
            $standardCreditLimit = $needsStandardProfile
                ? SalaryCreditPolicy::creditLimit((float) $standardProfile['monthly_salary'], $standardCreditRate)
                : 0.0;

            if ($existing) {
                $updatePayload = [
                    'employee_id' => $employeeId !== '' ? $employeeId : $existing['employee_id'],
                    'name' => $name,
                    'email' => $email,
                    'role' => $primaryRole,
                    'user_type' => $userType,
                    'is_active' => $isActive,
                ];
                if ($needsStandardProfile) {
                    $updatePayload += [
                        'base_salary' => $standardProfile['monthly_salary'],
                        'employment_type' => 'plantilla',
                        'salary_grade' => $standardProfile['salary_grade_label'],
                        'salary_step' => $standardProfile['salary_step'],
                        'salary_effective_date' => $standardProfile['effective_from'],
                        'salary_schedule_id' => $standardProfile['schedule_id'],
                    ];
                }
                $db->table('users')->where('id', (int) $existing['id'])->update($updatePayload);
                $userId = (int) $existing['id'];
                $updated++;
            } else {
                $newUserPayload = [
                    'employee_id' => $employeeId !== '' ? $employeeId : null,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
                    'role' => $primaryRole,
                    'user_type' => $userType,
                    'qr_token' => bin2hex(random_bytes(16)),
                    'base_salary' => $needsStandardProfile ? $standardProfile['monthly_salary'] : 0,
                    'is_active' => $isActive,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                if ($needsStandardProfile) {
                    $newUserPayload += [
                        'employment_type' => 'plantilla',
                        'salary_grade' => $standardProfile['salary_grade_label'],
                        'salary_step' => $standardProfile['salary_step'],
                        'salary_effective_date' => $standardProfile['effective_from'],
                        'salary_schedule_id' => $standardProfile['schedule_id'],
                    ];
                }
                $db->table('users')->insert($newUserPayload);
                $createdUser = $db->table('users')->select('id')->where('email', $email)->get()->getRowArray();
                $userId = (int) ($createdUser['id'] ?? 0);
                if ($userId <= 0) {
                    $invalid++;
                    continue;
                }
                $created++;
            }
            $this->syncUserRoles($userId, $roles);

            $balance = $balanceModel->find($userId);
            if ($balance) {
                $balanceUpdate = [
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($needsStandardProfile) {
                    $balanceUpdate['credit_limit'] = $standardCreditLimit;
                    $balanceUpdate['credit_rate'] = $standardCreditRate;
                }
                $balanceModel->update($userId, $balanceUpdate);
            } else {
                $balanceModel->insert([
                    'user_id' => $userId,
                    'credit_limit' => $standardCreditLimit,
                    'credit_rate' => $needsStandardProfile ? $standardCreditRate : 0,
                    'current_debt' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        fclose($handle);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_IMPORT_USERS_CSV',
            'entity' => 'users',
            'entity_id' => null,
            'payload_json' => json_encode([
                'total' => $total,
                'created' => $created,
                'updated' => $updated,
                'invalid' => $invalid,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $db->transRollback();
            log_message('error', 'User CSV import rolled back: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Import failed and no employee records were applied. Please try again or contact the administrator.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'invalid' => $invalid,
        ]);
    }

    public function stores()
    {
        return view('admin/stores', [
            'canManageStores' => ibems_current_role() === 'ADMIN',
        ]);
    }

    private function resolveEmployeeFinancialProfile($db, array $request, ?array $current = null): array
    {
        foreach (['employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            if (!$db->fieldExists($field, 'users')) {
                throw new \InvalidArgumentException('Salary schedule setup is unavailable until the latest database migration is applied.');
            }
        }
        if (!$db->fieldExists('credit_rate', 'balances')) {
            throw new \InvalidArgumentException('Dynamic credit percentage is unavailable until the latest database migration is applied.');
        }

        $scheduleId = (int) ($request['salary_schedule_id'] ?? $current['salary_schedule_id'] ?? 0);
        $grade = (string) ($request['salary_grade'] ?? $current['salary_grade'] ?? '');
        $step = (int) ($request['salary_step'] ?? $current['salary_step'] ?? 0);
        $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($request['employment_type'] ?? $current['employment_type'] ?? 'plantilla'));
        $effectiveDate = trim((string) ($request['salary_effective_date'] ?? $current['salary_effective_date'] ?? ''));
        $creditPercentage = (float) ($request['credit_percentage'] ?? SalaryCreditPolicy::percentageFromRate((float) ($current['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE)));

        if (!in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)) {
            throw new \InvalidArgumentException('Select a valid employment type.');
        }
        if (!SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)) {
            throw new \InvalidArgumentException('Select a valid salary effective date.');
        }
        if (!SalaryCreditPolicy::isValidCreditPercentage($creditPercentage)) {
            throw new \InvalidArgumentException('Credit percentage must be from 0% to 100%.');
        }

        $rate = (new SalaryScheduleService())->resolveRate($db, $scheduleId, $grade, $step);
        if ($rate === null) {
            throw new \InvalidArgumentException('Select a valid salary schedule, grade, and step.');
        }
        if ($effectiveDate < $rate['effective_from'] || ($rate['effective_to'] !== null && $effectiveDate > $rate['effective_to'])) {
            throw new \InvalidArgumentException('The salary effective date must fall within the selected schedule.');
        }

        $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage);
        return $rate + [
            'employment_type' => $employmentType,
            'effective_date' => $effectiveDate,
            'credit_percentage' => $creditPercentage,
            'credit_rate' => $creditRate,
            'credit_limit' => SalaryCreditPolicy::creditLimit((float) $rate['monthly_salary'], $creditRate),
        ];
    }

    public function storesData()
    {
        return (new StoreOversightService())->storesData($this->request, $this->response);
    }

    public function storeDetails(int $storeId)
    {
        if ($storeId <= 0) {
            return redirect()->to('/admin/stores');
        }
        if (!$this->canAccessStoreForAdminArea($storeId)) {
            return redirect()->to('/admin/stores');
        }

        return view('admin/store-details', ['storeId' => $storeId]);
    }

    public function storeDetailsData(int $storeId)
    {
        return (new StoreOversightService())->storeDetailsData($this->request, $this->response, $storeId);
    }

    public function reviewStoreDayVariance(int $sessionId)
    {
        return (new StoreOversightService())->reviewStoreDayVariance($this->request, $this->response, $sessionId);
    }

    public function resolveStaleStoreDay(int $sessionId)
    {
        return (new StoreOversightService())->resolveStaleStoreDay($this->request, $this->response, $sessionId);
    }

    public function uploadVarianceCaseAttachment(int $caseId) { return (new StoreOversightService())->uploadVarianceCaseAttachment($this->request, $this->response, $caseId); }
    public function downloadVarianceCaseAttachment(int $attachmentId) { return (new StoreOversightService())->downloadVarianceCaseAttachment($this->response, $attachmentId); }
    public function handoffVarianceCase(int $caseId) { return (new StoreOversightService())->handoffVarianceCase($this->request, $this->response, $caseId); }
    public function acknowledgeVarianceCase(int $caseId) { return (new StoreOversightService())->acknowledgeVarianceCase($this->response, $caseId); }

    public function officers()
    {
        $q = trim((string) $this->request->getGet('q'));
        $db = Database::connect();
        $query = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.profile_image_url, u.role, u.user_type, s.id AS assigned_store_id, s.store_name AS assigned_store_name')
            ->join('stores s', 's.officer_id = u.id', 'left')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff']);

        if ($q !== '') {
            $query->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        $rows = $query->orderBy('u.name', 'ASC')->limit(300)->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'role' => $row['role'],
                    'user_type' => $row['user_type'],
                    'assigned_store_id' => isset($row['assigned_store_id']) ? (int) $row['assigned_store_id'] : null,
                    'assigned_store_name' => $row['assigned_store_name'] ?? null,
                ];
            }, $rows),
        ]);
    }

    public function createStore()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeName = trim((string) ($request['store_name'] ?? ''));
        $officerId = (int) ($request['officer_id'] ?? 0);
        $supervisorIds = $this->extractIntegerList($request['supervisor_ids'] ?? []);
        $logoUrl = trim((string) ($request['logo_url'] ?? ''));
        $isActive = (int) ($request['is_active'] ?? 0) === 1;

        if ($storeName === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Store name is required.',
            ]);
        }

        $db = Database::connect();
        $storeModel = new StoreModel();
        $auditLogModel = new AuditLogModel();
        $userModel = new UserModel();

        if ($officerId > 0) {
            $officer = $userModel->find($officerId);
            if (!$officer || !ibems_bool($officer['is_active']) || !in_array($officer['user_type'], ['faculty', 'staff'], true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid store officer. Only active faculty/staff can be assigned.',
                ]);
            }

            $existingOfficerStore = $storeModel->where('officer_id', $officerId)->first();
            if ($existingOfficerStore) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Officer is already assigned to another store.',
                ]);
            }
        }
        try {
            $supervisorIds = $this->validateStoreSupervisors($supervisorIds, $userModel);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
        if ($isActive && ($officerId <= 0 || $supervisorIds === [])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'An active store requires a primary officer and at least one supervisor.',
            ]);
        }

        try {
            $logoUrl = $this->resolveStoreLogoUrl($logoUrl, null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $storeId = $storeModel->insert([
            'store_name' => $storeName,
            'officer_id' => $officerId > 0 ? $officerId : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($storeId && $officerId > 0) {
            $this->addRoleToUser($officerId, 'STORE_SYSTEM', $userModel);
        }
        if ($storeId) {
            (new StoreSupervisorModel())->syncStoreSupervisors((int) $storeId, $supervisorIds, $actorId);
            foreach ($supervisorIds as $supervisorId) {
                $this->addRoleToUser($supervisorId, 'STORE_SUPERVISOR', $userModel);
            }
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_CREATE_STORE',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'store_name' => $storeName,
                'officer_id' => $officerId > 0 ? $officerId : null,
                'supervisor_ids' => $supervisorIds,
                'logo_url' => $logoUrl,
                'is_active' => $isActive,
                'salary_profile' => $financialProfile,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to create store.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $storeId,
        ]);
    }

    public function updateStore()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeId = (int) ($request['store_id'] ?? 0);
        $storeName = trim((string) ($request['store_name'] ?? ''));
        $officerId = (int) ($request['officer_id'] ?? 0);
        $supervisorIds = $this->extractIntegerList($request['supervisor_ids'] ?? []);
        $logoUrl = trim((string) ($request['logo_url'] ?? ''));

        $isActive = isset($request['is_active']) ? (int) $request['is_active'] : null;
        $deactivationReason = trim((string) ($request['deactivation_reason'] ?? ''));

        if ($storeId <= 0 || $storeName === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store update payload.',
            ]);
        }
        if ($isActive !== null && !in_array($isActive, [0, 1], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store status.',
            ]);
        }

        $db = Database::connect();
        $storeModel = new StoreModel();
        $userModel = new UserModel();
        $auditLogModel = new AuditLogModel();

        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        if ($officerId > 0) {
            $officer = $userModel->find($officerId);
            if (!$officer || !ibems_bool($officer['is_active']) || !in_array($officer['user_type'], ['faculty', 'staff'], true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid store officer. Only active faculty/staff can be assigned.',
                ]);
            }

            $existingOfficerStore = $storeModel->where('officer_id', $officerId)->first();
            if ($existingOfficerStore && (int) $existingOfficerStore['id'] !== $storeId) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Officer is already assigned to another store.',
                ]);
            }
        }
        $previousSupervisorIds = (new StoreSupervisorModel())->getSupervisorIdsByStore($storeId);
        try {
            $supervisorIds = $this->validateStoreSupervisors($supervisorIds, $userModel);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
        $willBeActive = $isActive !== null ? $isActive === 1 : ibems_bool($store['is_active'] ?? false);
        if ($willBeActive && ($officerId <= 0 || $supervisorIds === [])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'An active store requires a primary officer and at least one supervisor.',
            ]);
        }
        $statusIsChanging = $isActive !== null && $isActive !== (int) ibems_bool($store['is_active'] ?? false);
        if ($statusIsChanging && $isActive === 0) {
            $lifecycleErrors = (new \App\Services\StoreLifecycleService($db))->validateDeactivation($storeId, $deactivationReason);
            if ($lifecycleErrors !== []) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => implode(' ', $lifecycleErrors),
                ]);
            }
        }

        try {
            $logoUrl = $this->resolveStoreLogoUrl($logoUrl, $store['logo_url'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $previousOfficerId = (int) $store['officer_id'];

        $storePayload = [
            'store_name' => $storeName,
            'officer_id' => $officerId > 0 ? $officerId : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
        ];
        if ($isActive !== null) {
            $storePayload['is_active'] = $isActive === 1;
            if ($statusIsChanging) {
                $storePayload = array_merge(
                    $storePayload,
                    (new \App\Services\StoreLifecycleService($db))->lifecyclePayload($isActive === 1, $actorId, $deactivationReason)
                );
            }
        }

        $storeModel->update($storeId, $storePayload);

        if ($officerId > 0) {
            $this->addRoleToUser($officerId, 'STORE_SYSTEM', $userModel);
        }
        (new StoreSupervisorModel())->syncStoreSupervisors($storeId, $supervisorIds, $actorId);
        foreach ($supervisorIds as $supervisorId) {
            $this->addRoleToUser($supervisorId, 'STORE_SUPERVISOR', $userModel);
        }
        foreach (array_diff($previousSupervisorIds, $supervisorIds) as $previousSupervisorId) {
            $assignedCount = $db->table('store_supervisors')->where('user_id', (int) $previousSupervisorId)->countAllResults();
            if ($assignedCount === 0) {
                $this->removeRoleFromUser((int) $previousSupervisorId, 'STORE_SUPERVISOR', $userModel);
            }
        }

        if ($previousOfficerId > 0 && $previousOfficerId !== $officerId) {
            $assignedCount = $db->table('stores')->where('officer_id', $previousOfficerId)->countAllResults();
            if ($assignedCount === 0) {
                $this->removeRoleFromUser($previousOfficerId, 'STORE_SYSTEM', $userModel);
            }
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_UPDATE_STORE',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'before' => [
                    'store_name' => $store['store_name'],
                    'officer_id' => (int) $store['officer_id'],
                    'supervisor_ids' => $previousSupervisorIds,
                    'logo_url' => $store['logo_url'] ?? null,
                    'is_active' => (int) ($store['is_active'] ?? 0),
                ],
                'after' => [
                    'store_name' => $storeName,
                    'officer_id' => $officerId > 0 ? $officerId : null,
                    'supervisor_ids' => $supervisorIds,
                    'logo_url' => $logoUrl !== '' ? $logoUrl : null,
                    'is_active' => $isActive !== null ? $isActive : (int) ($store['is_active'] ?? 0),
                    'deactivation_reason' => $isActive === 0 ? $deactivationReason : null,
                ],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update store.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    private function resolveStoreLogoUrl(string $inputUrl, ?string $currentUrl): ?string
    {
        $logoFile = $this->request->getFile('logo_file');
        $hasFile = $logoFile && $logoFile->getError() !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            if (!$logoFile->isValid()) {
                throw new \RuntimeException('Invalid uploaded logo file.');
            }

            return (new AssetStorageService())->storeImage($logoFile, 'store-logos', $currentUrl);
        }

        if ($inputUrl !== '') {
            return $inputUrl;
        }

        return $currentUrl;
    }

    private function storeUserProfileImage(?string $currentUrl = null): ?string
    {
        $file = $this->request->getFile('profile_image');
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return (new AssetStorageService())->storeImage($file, 'profile-images', $currentUrl);
    }

    public function toggleStoreStatus()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeId = (int) ($request['store_id'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? -1);
        $reason = trim((string) ($request['deactivation_reason'] ?? ''));

        if ($storeId <= 0 || !in_array($isActive, [0, 1], true)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid status payload.']);
        }

        $storeModel = new StoreModel();
        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Store not found.']);
        }
        if ($isActive === (int) ibems_bool($store['is_active'] ?? false)) {
            return $this->response->setJSON(['status' => 'success']);
        }

        $lifecycle = new \App\Services\StoreLifecycleService();
        if ($isActive === 1 && ($errors = $lifecycle->validateReactivation($store)) !== []) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => implode(' ', $errors)]);
        }
        if ($isActive === 0 && ($errors = $lifecycle->validateDeactivation($storeId, $reason)) !== []) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => implode(' ', $errors)]);
        }

        $db = Database::connect();
        $db->transStart();
        $storeModel->update($storeId, $lifecycle->lifecyclePayload($isActive === 1, $actorId, $reason));
        (new AuditLogModel())->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_TOGGLE_STORE_STATUS',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'previous_is_active' => (int) $store['is_active'],
                'new_is_active' => $isActive,
                'deactivation_reason' => $isActive === 0 ? $reason : null,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Failed to update store status.']);
        }

        return $this->response->setJSON(['status' => 'success']);
    }

    private function getRequestData(): array
    {
        $contentType = strtolower((string) $this->request->getHeaderLine('Content-Type'));
        if (strpos($contentType, 'application/json') !== false) {
            try {
                return $this->request->getJSON(true) ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        return $this->request->getPost();
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

    private function extractRolesFromRequest(array $request): array
    {
        $rawRoles = $request['roles'] ?? null;
        if (is_string($rawRoles)) {
            return $this->parseRoleList($rawRoles);
        }

        if (is_array($rawRoles)) {
            $roles = [];
            foreach ($rawRoles as $role) {
                $value = strtoupper(trim((string) $role));
                if ($value !== '') {
                    $roles[] = $value;
                }
            }
            return array_values(array_unique($roles));
        }

        $fallbackRole = strtoupper(trim((string) ($request['role'] ?? '')));
        return $fallbackRole !== '' ? [$fallbackRole] : [];
    }

    private function parseRoleList(string $value): array
    {
        $parts = preg_split('/[\s,|;]+/', strtoupper(trim($value))) ?: [];
        $roles = [];
        foreach ($parts as $part) {
            $role = trim((string) $part);
            if ($role !== '') {
                $roles[] = $role;
            }
        }
        return array_values(array_unique($roles));
    }

    private function sanitizeRoles(array $roles, array $fallback = ['USER']): array
    {
        $roles = array_values(array_unique(array_map(static fn($role): string => strtoupper(trim((string) $role)), $roles)));
        $roles = array_values(array_filter($roles, static fn($role): bool => $role !== ''));

        if (!empty($roles)) {
            return $roles;
        }

        return array_values(array_unique(array_map(static fn($role): string => strtoupper(trim((string) $role)), $fallback)));
    }

    private function extractIntegerList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s|;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0)));
    }

    private function validateStoreSupervisors(array $supervisorIds, UserModel $userModel): array
    {
        $validIds = [];
        foreach ($supervisorIds as $supervisorId) {
            $supervisor = $userModel->find((int) $supervisorId);
            if (!$supervisor || !ibems_bool($supervisor['is_active'] ?? false) || !in_array($supervisor['user_type'], ['faculty', 'staff'], true)) {
                throw new \RuntimeException('Invalid store supervisor. Only active faculty/staff can be assigned.');
            }
            $validIds[] = (int) $supervisorId;
        }

        return array_values(array_unique($validIds));
    }

    private function canAccessStoreForAdminArea(int $storeId): bool
    {
        $role = ibems_current_role();
        if ($role === 'ADMIN') {
            return true;
        }
        if ($role !== 'STORE_SUPERVISOR') {
            return false;
        }

        return (new StoreModel())->canUserAccessStore((int) session()->get('user_id'), $role, $storeId);
    }

    private function formatAuditAction(string $action): string
    {
        $labels = [
            'ACCOUNTING_PREPARE_DEDUCTION_BATCH' => 'Prepared deduction batch',
            'ACCOUNTING_SUBMIT_DEDUCTION_BATCH' => 'Submitted deduction batch',
            'ACCOUNTING_CONFIRM_DEDUCTION_RESULT' => 'Confirmed payroll deduction result',
            'ACCOUNTING_RECONCILE_DEDUCTION_BATCH' => 'Reconciled deduction batch',
            'ACCOUNTING_FINALIZE_DEDUCTION_BATCH' => 'Finalized deduction period',
            'OPEN_DEBT_INVESTIGATION' => 'Opened debt investigation',
            'RECOMMEND_DEBT_INVESTIGATION' => 'Recommended debt correction',
            'APPROVE_AND_POST_DEBT_REVERSAL' => 'Approved and posted debt correction',
            'DEBT_PIN_AUTHORIZED' => 'Authorized debt purchase PIN',
            'FAILED_DEBT_PIN' => 'Failed debt purchase PIN',
            'DEBT_PIN_LOCKED' => 'Locked debt purchase PIN',
            'BLOCKED_DEBT_PIN' => 'Blocked locked debt purchase PIN attempt',
        ];
        if (isset($labels[$action])) {
            return $labels[$action];
        }

        $value = str_replace('_', ' ', trim($action));
        $value = strtolower($value);

        return ucwords($value);
    }

    private function summarizeAuditPayload(array $payload): string
    {
        $parts = [];
        foreach (['store_name', 'name', 'email', 'sku', 'payment_method', 'business_date', 'month', 'run_id'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $parts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . (string) $payload[$key];
            }
        }

        foreach (['amount', 'total', 'created', 'updated', 'invalid', 'stock_qty', 'qty'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $parts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . (string) $payload[$key];
            }
        }

        if (isset($payload['before']) || isset($payload['after'])) {
            $parts[] = 'Changed fields recorded';
        }

        return implode(' | ', array_slice($parts, 0, 4));
    }

    private function pickPrimaryRole(array $roles): string
    {
        $priority = ['ADMIN', 'ACCOUNTING_OFFICE', 'STORE_SUPERVISOR', 'STORE_SYSTEM', 'USER'];
        foreach ($priority as $preferred) {
            if (in_array($preferred, $roles, true)) {
                return $preferred;
            }
        }
        return $roles[0] ?? 'USER';
    }

    private function syncUserRoles(int $userId, array $roles): void
    {
        $roles = array_values(array_unique(array_map(static fn($role): string => strtoupper(trim((string) $role)), $roles)));
        $roles = array_values(array_filter($roles, static fn($role): bool => $role !== ''));
        if (empty($roles)) {
            $roles = ['USER'];
        }

        $userRoleModel = new UserRoleModel();
        $existingRows = $userRoleModel->where('user_id', $userId)->findAll();
        $existingRoles = [];
        foreach ($existingRows as $row) {
            $existingRoles[] = strtoupper((string) ($row['role'] ?? ''));
        }

        $toDelete = array_diff($existingRoles, $roles);
        $toInsert = array_diff($roles, $existingRoles);

        if (!empty($toDelete)) {
            $userRoleModel->where('user_id', $userId)->whereIn('role', array_values($toDelete))->delete();
        }

        $now = date('Y-m-d H:i:s');
        foreach ($toInsert as $role) {
            $userRoleModel->insert([
                'user_id' => $userId,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveUserRoles(int $userId, string $fallbackRole): array
    {
        $roles = (new UserRoleModel())->getRolesByUserId($userId);
        if (!empty($roles)) {
            return $roles;
        }

        $fallback = strtoupper(trim($fallbackRole));
        return $fallback !== '' ? [$fallback] : ['USER'];
    }

    private function buildRolesMap(array $rows): array
    {
        $userIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $userIds[] = $id;
            }
        }
        $userIds = array_values(array_unique($userIds));
        if (empty($userIds)) {
            return [];
        }

        $roleRows = (new UserRoleModel())
            ->whereIn('user_id', $userIds)
            ->orderBy('role', 'ASC')
            ->findAll();

        $map = [];
        foreach ($roleRows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $role = strtoupper(trim((string) ($row['role'] ?? '')));
            if ($userId <= 0 || $role === '') {
                continue;
            }
            if (!isset($map[$userId])) {
                $map[$userId] = [];
            }
            if (!in_array($role, $map[$userId], true)) {
                $map[$userId][] = $role;
            }
        }

        return $map;
    }

    private function addRoleToUser(int $userId, string $role, UserModel $userModel): void
    {
        $user = $userModel->find($userId);
        if (!$user) {
            return;
        }

        $role = strtoupper(trim($role));
        if ($role === '') {
            return;
        }

        $roles = $this->resolveUserRoles($userId, (string) ($user['role'] ?? 'USER'));
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
        }

        $this->syncUserRoles($userId, $roles);
        $primaryRole = $this->pickPrimaryRole($roles);
        if (strtoupper((string) ($user['role'] ?? '')) !== $primaryRole) {
            $userModel->update($userId, ['role' => $primaryRole]);
        }
    }

    private function removeRoleFromUser(int $userId, string $role, UserModel $userModel): void
    {
        $user = $userModel->find($userId);
        if (!$user) {
            return;
        }

        $role = strtoupper(trim($role));
        if ($role === '') {
            return;
        }

        $roles = $this->resolveUserRoles($userId, (string) ($user['role'] ?? 'USER'));
        $roles = array_values(array_filter($roles, static fn(string $selectedRole): bool => $selectedRole !== $role));
        if (empty($roles)) {
            $roles = ['USER'];
        }

        $this->syncUserRoles($userId, $roles);
        $primaryRole = $this->pickPrimaryRole($roles);
        if (strtoupper((string) ($user['role'] ?? '')) !== $primaryRole) {
            $userModel->update($userId, ['role' => $primaryRole]);
        }
    }
}
