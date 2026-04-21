<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\InventoryMovementModel;
use App\Models\AuditLogModel;
use Config\Database;

class StoreController extends BaseController
{
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

    public function debtCustomers()
    {
        $q = trim((string) $this->request->getGet('q'));
        $limit = 20;
        $db = Database::connect();

        $builder = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.user_type, b.credit_limit, b.current_debt')
            ->join('balances b', 'b.user_id = u.id', 'inner')
            ->where('u.is_active', 1)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->orderBy('u.name', 'ASC')
            ->limit($limit);

        if ($q !== '') {
            $builder->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        $rows = $builder->get()->getResultArray();

        $customers = array_map(static function (array $row): array {
            $creditLimit = (float) $row['credit_limit'];
            $currentDebt = (float) $row['current_debt'];
            $row['available_credit'] = max(0, $creditLimit - $currentDebt);
            return $row;
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'customers' => $customers,
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

        $limit = (int) ($this->request->getGet('limit') ?? 50);
        $limit = max(1, min(100, $limit));
        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $paymentMethod = trim((string) $this->request->getGet('payment_method'));

        $allowedPaymentMethods = ['cash', 'gcash', 'card', 'bank_transfer', 'other', 'debt', 'advance_payment'];

        $db = Database::connect();
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
            $query->where('t.payment_method', $paymentMethod);
        }

        $rows = $query->orderBy('t.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $transactions = array_map(static function (array $row): array {
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
            ];
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => $storeId,
            'transactions' => $transactions,
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
        $limit = (int) ($this->request->getGet('limit') ?? 100);
        $limit = max(1, min(200, $limit));

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
        $reason = trim((string) ($request['reason'] ?? 'Stock in'));

        if ($storeId <= 0 || $productId <= 0 || $qty <= 0 || $unitCost < 0) {
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
        $expectedProfit = ((float) $product['price'] - $unitCost) * $qty;
        $db = Database::connect();
        $db->transStart();

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
            'expected_profit' => $expectedProfit,
            'total_cost' => $totalCost,
        ]);
    }

}
