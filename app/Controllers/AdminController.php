<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\StoreCategoryModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
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
        $today = new \DateTimeImmutable('today');
        $rangeStartDate = $today->modify('-6 days')->format('Y-m-d');
        $rangeStartTs = $rangeStartDate . ' 00:00:00';
        $rangeEndTs = $today->format('Y-m-d') . ' 23:59:59';

        $storeSummary = $db->table('stores')
            ->select('COUNT(*) AS total_stores, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_stores, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_stores')
            ->get()
            ->getRowArray();

        $officerSummary = $db->table('user_roles ur')
            ->select('COUNT(DISTINCT ur.user_id) AS active_store_officers')
            ->join('users u', 'u.id = ur.user_id', 'inner')
            ->where('ur.role', 'STORE_SYSTEM')
            ->where('u.is_active', 1)
            ->get()
            ->getRowArray();

        $activeStoreOfficers = (int) ($officerSummary['active_store_officers'] ?? 0);
        if ($activeStoreOfficers <= 0) {
            $fallback = $db->table('users')
                ->select('COUNT(*) AS active_store_officers')
                ->where('is_active', 1)
                ->where('role', 'STORE_SYSTEM')
                ->get()
                ->getRowArray();
            $activeStoreOfficers = (int) ($fallback['active_store_officers'] ?? 0);
        }

        $debtSummary = $db->table('balances b')
            ->select('SUM(CASE WHEN b.current_debt > 0 THEN 1 ELSE 0 END) AS debt_accounts, COALESCE(SUM(b.current_debt), 0) AS total_debt')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
            ->get()
            ->getRowArray();

        $productAlerts = $db->table('products')
            ->select('SUM(CASE WHEN is_active = 1 AND stock_qty <= 0 THEN 1 ELSE 0 END) AS out_of_stock_products')
            ->get()
            ->getRowArray();

        $creditAlerts = $db->table('balances b')
            ->select('SUM(CASE WHEN b.current_debt > b.credit_limit THEN 1 ELSE 0 END) AS over_credit_accounts')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
            ->get()
            ->getRowArray();

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
        $overCreditAccounts = (int) ($creditAlerts['over_credit_accounts'] ?? 0);
        $openAlerts = $inactiveStores + $outOfStockProducts + $overCreditAccounts;

        $healthMessages = [];
        if ($inactiveStores > 0) {
            $healthMessages[] = $inactiveStores . ' inactive store(s)';
        }
        if ($outOfStockProducts > 0) {
            $healthMessages[] = $outOfStockProducts . ' out-of-stock product(s)';
        }
        if ($overCreditAccounts > 0) {
            $healthMessages[] = $overCreditAccounts . ' over-credit account(s)';
        }
        $healthText = $openAlerts > 0
            ? 'Attention needed: ' . implode(' | ', $healthMessages) . '.'
            : 'All core modules are online. No alerts detected.';

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
                'over_credit_accounts' => $overCreditAccounts,
                'today_transactions' => (int) ($todayTxn['txn_count'] ?? 0),
                'today_sales' => (float) ($todayTxn['sales_total'] ?? 0),
            ],
            'health' => [
                'ok' => $openAlerts === 0,
                'message' => $healthText,
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

    public function productsData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $includeInactive = (int) ($this->request->getGet('include_inactive') ?? 0) === 1;

        $db = Database::connect();
        $query = $db->table('products p')
            ->select('p.id, p.store_id, p.sku, p.name, p.variant_label, p.category, p.barcode, p.image_url, p.price, p.stock_qty, p.is_active, p.updated_at, s.store_name')
            ->join('stores s', 's.id = p.store_id', 'inner');

        if ($storeId > 0) {
            $query->where('p.store_id', $storeId);
        }
        if (!$includeInactive) {
            $query->where('p.is_active', 1);
        }
        if ($q !== '') {
            $query->groupStart()
                ->like('p.name', $q)
                ->orLike('p.sku', $q)
                ->orLike('p.category', $q)
                ->orLike('s.store_name', $q)
                ->groupEnd();
        }

        $rows = $query->orderBy('s.store_name', 'ASC')
            ->orderBy('p.name', 'ASC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $stores = $db->table('stores')
            ->select('id, store_name')
            ->where('is_active', 1)
            ->orderBy('store_name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'stores' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'store_name' => (string) $row['store_name'],
                ];
            }, $stores),
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'store_id' => (int) $row['store_id'],
                    'store_name' => (string) $row['store_name'],
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'variant_label' => (string) ($row['variant_label'] ?? ''),
                    'category' => (string) ($row['category'] ?? ''),
                    'barcode' => (string) ($row['barcode'] ?? ''),
                    'image_url' => (string) ($row['image_url'] ?? ''),
                    'price' => (float) ($row['price'] ?? 0),
                    'stock_qty' => (int) ($row['stock_qty'] ?? 0),
                    'is_active' => (bool) ($row['is_active'] ?? false),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }, $rows),
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
        $isActive = (int) ($request['is_active'] ?? 1) === 1 ? 1 : 0;

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
                    'is_active' => (bool) ($product['is_active'] ?? false),
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
        $isActive = (int) ($request['is_active'] ?? 1) === 1 ? 1 : 0;

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
            ->select('u.employee_id, u.name, u.email, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
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
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'current_debt' => (float) $row['current_debt'],
                    'credit_limit' => (float) $row['credit_limit'],
                ];
            }, $topDebts),
        ]);
    }

    public function userViewData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $db = Database::connect();
        $query = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.role, u.user_type, u.is_active, b.current_debt, b.credit_limit')
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
                    'role' => $row['role'],
                    'roles' => $rolesMap[$userId] ?? [strtoupper((string) ($row['role'] ?? 'USER'))],
                    'user_type' => $row['user_type'],
                    'is_active' => (bool) $row['is_active'],
                    'current_debt' => (float) ($row['current_debt'] ?? 0),
                    'credit_limit' => (float) ($row['credit_limit'] ?? 0),
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
        $row = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.role, u.user_type, u.base_salary, u.is_active, u.created_at, b.current_debt, b.credit_limit')
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
                'role' => $row['role'],
                'roles' => $this->resolveUserRoles((int) $row['id'], (string) ($row['role'] ?? 'USER')),
                'user_type' => $row['user_type'],
                'base_salary' => (float) ($row['base_salary'] ?? 0),
                'is_active' => (bool) $row['is_active'],
                'created_at' => $row['created_at'],
                'current_debt' => (float) ($row['current_debt'] ?? 0),
                'credit_limit' => (float) ($row['credit_limit'] ?? 0),
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
        $role = strtoupper(trim((string) ($request['role'] ?? 'USER')));
        $userType = strtolower(trim((string) ($request['user_type'] ?? 'staff')));
        $roles = $this->extractRolesFromRequest($request);
        $baseSalary = (float) ($request['base_salary'] ?? 0);
        $creditLimit = (float) ($request['credit_limit'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? 1) === 1 ? 1 : 0;
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

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        if (empty($roles)) {
            $roles = [$role];
        }
        foreach ($roles as $selectedRole) {
            if (!in_array($selectedRole, $allowedRoles, true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid role.',
                ]);
            }
        }
        if (!in_array($role, $allowedRoles, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid role.',
            ]);
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

        $db->transStart();

        $userId = $userModel->insert([
            'employee_id' => $employeeId !== '' ? $employeeId : null,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $primaryRole,
            'user_type' => $userType,
            'qr_token' => bin2hex(random_bytes(16)),
            'base_salary' => max(0, $baseSalary),
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($userId) {
            $this->syncUserRoles((int) $userId, $roles);

            $balanceModel->insert([
                'user_id' => (int) $userId,
                'credit_limit' => max(0, $creditLimit),
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
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
        $role = strtoupper(trim((string) ($request['role'] ?? 'USER')));
        $userType = strtolower(trim((string) ($request['user_type'] ?? 'staff')));
        $roles = $this->extractRolesFromRequest($request);
        $baseSalary = (float) ($request['base_salary'] ?? 0);
        $creditLimit = (float) ($request['credit_limit'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? 1) === 1 ? 1 : 0;

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

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        if (empty($roles)) {
            $roles = [$role];
        }
        foreach ($roles as $selectedRole) {
            if (!in_array($selectedRole, $allowedRoles, true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid role or user type.',
                ]);
            }
        }
        if (!in_array($role, $allowedRoles, true) || !in_array($userType, $allowedTypes, true)) {
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
        $db->transStart();

        $userModel->update($userId, [
            'employee_id' => $employeeId !== '' ? $employeeId : null,
            'name' => $name,
            'email' => $email,
            'role' => $primaryRole,
            'user_type' => $userType,
            'base_salary' => max(0, $baseSalary),
            'is_active' => $isActive,
        ]);
        $this->syncUserRoles($userId, $roles);

        $balance = $balanceModel->find($userId);
        if ($balance) {
            $balanceModel->update($userId, [
                'credit_limit' => max(0, $creditLimit),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $balanceModel->insert([
                'user_id' => $userId,
                'credit_limit' => max(0, $creditLimit),
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
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

        $headersRaw = fgetcsv($handle);
        $headers = is_array($headersRaw) ? array_map(static fn($h): string => strtolower(trim((string) $h)), $headersRaw) : [];
        $required = ['name', 'email', 'role', 'user_type'];
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

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        $total = 0;
        $created = 0;
        $updated = 0;
        $invalid = 0;

        $db->transStart();
        while (($values = fgetcsv($handle)) !== false) {
            $total++;
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = trim((string) ($values[$i] ?? ''));
            }

            $name = $row['name'] ?? '';
            $email = strtolower($row['email'] ?? '');
            $role = strtoupper($row['role'] ?? '');
            $roles = $this->parseRoleList($role);
            $userType = strtolower($row['user_type'] ?? '');
            $employeeId = $row['employee_id'] ?? '';
            $baseSalary = isset($row['base_salary']) ? (float) $row['base_salary'] : 0;
            $creditLimit = isset($row['credit_limit']) ? (float) $row['credit_limit'] : 0;
            $isActive = isset($row['is_active']) ? ((int) $row['is_active'] === 1 ? 1 : 0) : 1;

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

            if ($existing) {
                $userModel->update((int) $existing['id'], [
                    'employee_id' => $employeeId !== '' ? $employeeId : $existing['employee_id'],
                    'name' => $name,
                    'email' => $email,
                    'role' => $primaryRole,
                    'user_type' => $userType,
                    'base_salary' => max(0, $baseSalary),
                    'is_active' => $isActive,
                ]);
                $userId = (int) $existing['id'];
                $updated++;
            } else {
                $userId = (int) $userModel->insert([
                    'employee_id' => $employeeId !== '' ? $employeeId : null,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
                    'role' => $primaryRole,
                    'user_type' => $userType,
                    'qr_token' => bin2hex(random_bytes(16)),
                    'base_salary' => max(0, $baseSalary),
                    'is_active' => $isActive,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                if ($userId <= 0) {
                    $invalid++;
                    continue;
                }
                $created++;
            }
            $this->syncUserRoles($userId, $roles);

            $balance = $balanceModel->find($userId);
            if ($balance) {
                $balanceModel->update($userId, [
                    'credit_limit' => max(0, $creditLimit),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $balanceModel->insert([
                    'user_id' => $userId,
                    'credit_limit' => max(0, $creditLimit),
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
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to import users.',
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
        return view('admin/stores');
    }

    public function storesData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $db = Database::connect();
        $query = $db->table('stores s')
            ->select('s.id, s.store_name, s.logo_url, s.is_active, s.created_at, s.officer_id, u.name AS officer_name, u.email AS officer_email')
            ->join('users u', 'u.id = s.officer_id', 'left');

        if ($q !== '') {
            $query->groupStart()
                ->like('s.store_name', $q)
                ->orLike('u.name', $q)
                ->orLike('u.email', $q)
                ->groupEnd();
        }

        $rows = $query->orderBy('s.store_name', 'ASC')->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    public function storeDetails(int $storeId)
    {
        if ($storeId <= 0) {
            return redirect()->to('/admin/stores');
        }

        return view('admin/store-details', ['storeId' => $storeId]);
    }

    public function storeDetailsData(int $storeId)
    {
        if ($storeId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store id.',
            ]);
        }

        $db = Database::connect();
        $store = $db->table('stores s')
            ->select('s.id, s.store_name, s.logo_url, s.is_active, s.created_at, u.name AS officer_name, u.email AS officer_email')
            ->join('users u', 'u.id = s.officer_id', 'left')
            ->where('s.id', $storeId)
            ->get()
            ->getRowArray();

        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        $summary = $db->table('transactions')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->where('store_id', $storeId)
            ->get()
            ->getRowArray();

        $inventory = $db->table('products p')
            ->select('p.id, p.name, p.stock_qty, p.price, p.image_url')
            ->where('p.store_id', $storeId)
            ->orderBy('p.name', 'ASC')
            ->get()
            ->getResultArray();

        $recentTransactions = $db->table('transactions t')
            ->select('t.id, t.created_at, t.payment_method, t.amount, u.name AS customer_name')
            ->join('users u', 'u.id = t.user_id', 'left')
            ->where('t.store_id', $storeId)
            ->orderBy('t.id', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'store' => [
                'id' => (int) $store['id'],
                'store_name' => $store['store_name'],
                'logo_url' => $store['logo_url'],
                'is_active' => (int) $store['is_active'] === 1,
                'created_at' => $store['created_at'],
                'officer_name' => $store['officer_name'],
                'officer_email' => $store['officer_email'],
            ],
            'summary' => [
                'txn_count' => (int) ($summary['txn_count'] ?? 0),
                'sales_total' => (float) ($summary['sales_total'] ?? 0),
                'product_count' => count($inventory),
                'stock_units' => array_reduce($inventory, static function (int $carry, array $row): int {
                    return $carry + (int) ($row['stock_qty'] ?? 0);
                }, 0),
            ],
            'inventory' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'stock_qty' => (int) ($row['stock_qty'] ?? 0),
                    'price' => (float) ($row['price'] ?? 0),
                    'image_url' => $row['image_url'] ?? null,
                ];
            }, $inventory),
            'recent_transactions' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'created_at' => $row['created_at'],
                    'payment_method' => $row['payment_method'],
                    'amount' => (float) ($row['amount'] ?? 0),
                    'customer_name' => $row['customer_name'] ?: 'Walk-in',
                ];
            }, $recentTransactions),
        ]);
    }

    public function officers()
    {
        $q = trim((string) $this->request->getGet('q'));
        $db = Database::connect();
        $query = $db->table('users')
            ->select('id, employee_id, name, email, role, user_type')
            ->where('is_active', 1)
            ->whereIn('user_type', ['faculty', 'staff']);

        if ($q !== '') {
            $query->groupStart()
                ->like('name', $q)
                ->orLike('email', $q)
                ->orLike('employee_id', $q)
                ->groupEnd();
        }

        $rows = $query->orderBy('name', 'ASC')->limit(300)->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'user_type' => $row['user_type'],
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
        $logoUrl = trim((string) ($request['logo_url'] ?? ''));

        if ($storeName === '' || $officerId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Store name and officer are required.',
            ]);
        }

        $db = Database::connect();
        $storeModel = new StoreModel();
        $auditLogModel = new AuditLogModel();
        $userModel = new UserModel();

        $officer = $userModel->find($officerId);
        if (!$officer || !(bool) $officer['is_active'] || !in_array($officer['user_type'], ['faculty', 'staff'], true)) {
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
            'officer_id' => $officerId,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($storeId) {
            $this->addRoleToUser($officerId, 'STORE_SYSTEM', $userModel);
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_CREATE_STORE',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'store_name' => $storeName,
                'officer_id' => $officerId,
                'logo_url' => $logoUrl,
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
        $logoUrl = trim((string) ($request['logo_url'] ?? ''));

        if ($storeId <= 0 || $storeName === '' || $officerId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store update payload.',
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

        $officer = $userModel->find($officerId);
        if (!$officer || !(bool) $officer['is_active'] || !in_array($officer['user_type'], ['faculty', 'staff'], true)) {
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

        $storeModel->update($storeId, [
            'store_name' => $storeName,
            'officer_id' => $officerId,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
        ]);

        $this->addRoleToUser($officerId, 'STORE_SYSTEM', $userModel);

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
                    'logo_url' => $store['logo_url'] ?? null,
                ],
                'after' => [
                    'store_name' => $storeName,
                    'officer_id' => $officerId,
                    'logo_url' => $logoUrl !== '' ? $logoUrl : null,
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

            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array((string) $logoFile->getMimeType(), $allowedMimeTypes, true)) {
                throw new \RuntimeException('Logo must be JPG, PNG, WEBP, or GIF.');
            }

            if ((int) $logoFile->getSize() > 2 * 1024 * 1024) {
                throw new \RuntimeException('Logo file size must be 2MB or less.');
            }

            $uploadDir = FCPATH . 'uploads/store-logos';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new \RuntimeException('Failed to prepare upload directory.');
            }

            $newName = $logoFile->getRandomName();
            $logoFile->move($uploadDir, $newName);
            $storedPath = '/uploads/store-logos/' . $newName;

            if ($currentUrl && strpos($currentUrl, '/uploads/store-logos/') === 0) {
                $oldFile = FCPATH . ltrim($currentUrl, '/');
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            return $storedPath;
        }

        if ($inputUrl !== '') {
            return $inputUrl;
        }

        return $currentUrl;
    }

    public function toggleStoreStatus()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeId = (int) ($request['store_id'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? -1);

        if ($storeId <= 0 || !in_array($isActive, [0, 1], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid status payload.',
            ]);
        }

        $storeModel = new StoreModel();
        $auditLogModel = new AuditLogModel();
        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $storeModel->update($storeId, ['is_active' => $isActive]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_TOGGLE_STORE_STATUS',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'previous_is_active' => (int) $store['is_active'],
                'new_is_active' => $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update store status.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
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

            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array((string) $imageFile->getMimeType(), $allowedMimeTypes, true)) {
                throw new \RuntimeException('Product image must be JPG, PNG, WEBP, or GIF.');
            }

            if ((int) $imageFile->getSize() > 2 * 1024 * 1024) {
                throw new \RuntimeException('Product image size must be 2MB or less.');
            }

            $uploadDir = FCPATH . 'uploads/product-images';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new \RuntimeException('Failed to prepare product image upload directory.');
            }

            $newName = $imageFile->getRandomName();
            $imageFile->move($uploadDir, $newName);
            $storedPath = '/uploads/product-images/' . $newName;

            if ($currentUrl && strpos($currentUrl, '/uploads/product-images/') === 0) {
                $oldFile = FCPATH . ltrim($currentUrl, '/');
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            return $storedPath;
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

    private function pickPrimaryRole(array $roles): string
    {
        $priority = ['ADMIN', 'ACCOUNTING_OFFICE', 'STORE_SYSTEM', 'USER'];
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
