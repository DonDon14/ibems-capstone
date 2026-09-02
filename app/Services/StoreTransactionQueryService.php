<?php

namespace App\Services;

use App\Models\StoreModel;
use Config\Database;

final class StoreTransactionQueryService
{
    /** @param array<string, mixed> $filters @return array{code:int,payload:array<string, mixed>} */
    public function list(int $userId, string $role, array $filters): array
    {
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);
        if ($stores === []) {
            return $this->error(403, 'No accessible store found.');
        }

        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }
        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->error(403, 'You cannot access this store.');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(10, min(100, (int) ($filters['page_size'] ?? $filters['limit'] ?? 25)));
        $offset = ($page - 1) * $pageSize;
        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'date')));
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'date' => 't.created_at',
            'customer' => 'u.name',
            'payment' => 't.payment_method',
            'amount' => 't.amount',
            'reference' => 't.client_txn_id',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $paymentMethod = trim((string) ($filters['payment_method'] ?? ''));

        $db = Database::connect();
        $allowedPaymentMethods = array_column($db->table('store_payment_methods')->select('code')->where('store_id', $storeId)->get()->getResultArray(), 'code');
        $allowedPaymentMethods[] = 'split';
        $hasPaymentLines = in_array('transaction_payments', $db->listTables(), true);
        $hasDepartmentDebt = $db->tableExists('department_debt_transactions') && $db->tableExists('departments');
        $transactionSelect = 't.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.customer_type, t.user_id, u.name AS customer_name, u.profile_image_url AS customer_profile_image_url';
        if ($hasDepartmentDebt) {
            $transactionSelect .= ', ddt.department_id, d.name AS department_name, d.code AS department_code, ddt.requester_name AS department_requester_name';
        }
        $query = $db->table('transactions t')
            ->select($transactionSelect)
            ->join('users u', 'u.id = t.user_id', 'left');
        if ($hasDepartmentDebt) {
            $query->join('department_debt_transactions ddt', 'ddt.transaction_id = t.id', 'left')
                ->join('departments d', 'd.id = ddt.department_id', 'left');
        }
        $query->where('t.store_id', $storeId);

        $this->applyFilters($query, $db, $dateFrom, $dateTo, $paymentMethod, $allowedPaymentMethods, $hasPaymentLines);
        $total = (clone $query)->countAllResults();
        $summaryQuery = $db->table('transactions t')
            ->select('COUNT(t.id) AS transaction_count, COALESCE(SUM(t.amount), 0) AS total_amount')
            ->where('t.store_id', $storeId);
        $this->applyFilters($summaryQuery, $db, $dateFrom, $dateTo, $paymentMethod, $allowedPaymentMethods, $hasPaymentLines);
        $filteredSummary = $summaryQuery->get()->getRowArray() ?? [];

        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }
        $rows = $query->orderBy($sortColumn, $sortDir)->orderBy('t.id', $sortDir)
            ->limit($pageSize, $offset)->get()->getResultArray();

        $paymentRowsByTransaction = [];
        $transactionIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        if ($hasPaymentLines && $transactionIds !== []) {
            $paymentLineSelect = $db->fieldExists('destination_account_id', 'transaction_payments')
                ? 'transaction_id, payment_method, destination_account_id, destination_account_name, destination_account_number, amount, cash_received, change_due'
                : 'transaction_id, payment_method, amount, cash_received, change_due';
            $paymentRows = $db->table('transaction_payments')->select($paymentLineSelect)
                ->whereIn('transaction_id', $transactionIds)->orderBy('id', 'ASC')->get()->getResultArray();
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
            $customerName = ($row['department_name'] ?? '') ?: ($row['customer_name'] ?: 'Walk-in');
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
                'debt_account_type' => !empty($row['department_id']) ? 'department' : 'employee',
                'department_id' => isset($row['department_id']) ? (int) $row['department_id'] : null,
                'department_code' => (string) ($row['department_code'] ?? ''),
                'department_requester_name' => (string) ($row['department_requester_name'] ?? ''),
                'customer_profile_image_url' => $row['customer_profile_image_url'] ?? null,
                'payments' => $paymentRowsByTransaction[(int) $row['id']] ?? [],
            ];
        }, $rows);

        return ['code' => 200, 'payload' => [
            'status' => 'success',
            'store_id' => $storeId,
            'transactions' => $transactions,
            'summary' => [
                'transaction_count' => (int) ($filteredSummary['transaction_count'] ?? 0),
                'total_amount' => (float) ($filteredSummary['total_amount'] ?? 0),
            ],
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => $totalPages],
        ]];
    }

    /** @return array{code:int,payload:array<string, mixed>} */
    public function details(int $userId, string $role, int $transactionId): array
    {
        $db = Database::connect();
        $hasDepartmentDebt = $db->tableExists('department_debt_transactions') && $db->tableExists('departments');
        $detailSelect = 't.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.customer_type, t.store_id, t.user_id, u.name AS customer_name, s.store_name';
        if ($hasDepartmentDebt) {
            $detailSelect .= ', ddt.department_id, ddt.debt_amount AS department_debt_amount, ddt.requester_name AS department_requester_name, d.name AS department_name, d.code AS department_code, ap.name AS department_approver_name';
        }
        $txnQuery = $db->table('transactions t')
            ->select($detailSelect)
            ->join('users u', 'u.id = t.user_id', 'left')
            ->join('stores s', 's.id = t.store_id', 'inner');
        if ($hasDepartmentDebt) {
            $txnQuery->join('department_debt_transactions ddt', 'ddt.transaction_id = t.id', 'left')
                ->join('departments d', 'd.id = ddt.department_id', 'left')
                ->join('users ap', 'ap.id = ddt.approved_by_user_id', 'left');
        }
        $txn = $txnQuery->where('t.id', $transactionId)->get()->getRowArray();
        if (!$txn) {
            return $this->error(404, 'Transaction not found.');
        }
        if (!(new StoreModel())->canUserAccessStore($userId, $role, (int) $txn['store_id'])) {
            return $this->error(403, 'You cannot access this transaction.');
        }

        $itemsRaw = $db->table('transaction_items ti')
            ->select('ti.product_id, ti.qty, ti.unit_price, ti.line_total, COALESCE(ti.item_name_snapshot, p.name) AS product_name, ti.sku_snapshot, ti.variant_snapshot, ti.unit_code_snapshot')
            ->join('products p', 'p.id = ti.product_id', 'left')
            ->where('ti.transaction_id', $transactionId)
            ->orderBy('ti.id', 'ASC')
            ->get()
            ->getResultArray();
        $items = array_map(static fn(array $row): array => [
            'name' => $row['product_name'] ?: 'Product #' . $row['product_id'],
            'qty' => (int) $row['qty'],
            'unit_price' => (float) $row['unit_price'],
            'line_total' => (float) $row['line_total'],
        ], $itemsRaw);

        $paymentRows = [];
        if (in_array('transaction_payments', $db->listTables(), true)) {
            $detailPaymentSelect = $db->fieldExists('destination_account_id', 'transaction_payments')
                ? 'payment_method, destination_account_id, destination_account_name, destination_account_number, amount, cash_received, change_due'
                : 'payment_method, amount, cash_received, change_due';
            $paymentRows = $db->table('transaction_payments')->select($detailPaymentSelect)
                ->where('transaction_id', $transactionId)->orderBy('id', 'ASC')->get()->getResultArray();
        }
        $payments = array_map(static fn(array $row): array => [
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

        $customerName = ($txn['department_name'] ?? '') ?: ($txn['customer_name'] ?: 'Walk-in');
        if (($txn['customer_type'] ?? '') !== 'walk_in' && !$txn['customer_name']) {
            $customerName = ucfirst((string) $txn['customer_type']);
        }

        return ['code' => 200, 'payload' => ['status' => 'success', 'transaction' => [
            'id' => (int) $txn['id'],
            'client_txn_id' => $txn['client_txn_id'],
            'created_at' => $txn['created_at'],
            'payment_method' => $txn['payment_method'],
            'amount' => (float) $txn['amount'],
            'customer_name' => $customerName,
            'debt_account_type' => !empty($txn['department_id']) ? 'department' : 'employee',
            'department_id' => isset($txn['department_id']) ? (int) $txn['department_id'] : null,
            'department_code' => (string) ($txn['department_code'] ?? ''),
            'department_requester_name' => (string) ($txn['department_requester_name'] ?? ''),
            'department_approver_name' => (string) ($txn['department_approver_name'] ?? ''),
            'department_debt_amount' => isset($txn['department_debt_amount']) ? (float) $txn['department_debt_amount'] : null,
            'store_name' => $txn['store_name'],
            'items' => $items,
            'payments' => $payments,
        ]]];
    }

    /** @param array<string, mixed> $filters @return array{code:int,payload:array<string, mixed>} */
    public function staff(int $userId, string $role, array $filters): array
    {
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);
        if ($stores === []) {
            return $this->error(403, 'No accessible store found.');
        }
        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }
        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->error(403, 'You cannot access this store.');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(10, min(100, (int) ($filters['page_size'] ?? $filters['limit'] ?? 25)));
        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'date')));
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = ['date' => 't.created_at', 'payment' => 't.payment_method', 'amount' => 't.amount'];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];
        $search = trim((string) ($filters['q'] ?? ''));
        $employeeUserId = (int) ($filters['user_id'] ?? 0);
        $debtOnly = (int) ($filters['debt_only'] ?? 0) === 1;
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $db = Database::connect();
        $query = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, b.current_debt')
            ->join('users u', 'u.id = t.user_id', 'inner')
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('t.store_id', $storeId)
            ->whereIn('u.user_type', ['faculty', 'staff']);
        if ($debtOnly) {
            if (in_array('transaction_payments', $db->listTables(), true)) {
                $query->groupStart()->where('t.payment_method', 'debt')
                    ->orWhere('EXISTS (SELECT 1 FROM transaction_payments tp_debt WHERE tp_debt.transaction_id = t.id AND tp_debt.payment_method = ' . $db->escape('debt') . ')', null, false)->groupEnd();
            } else {
                $query->where('t.payment_method', 'debt');
            }
        }
        if ($dateFrom !== '') {
            $query->where('t.created_at >=', ibems_business_day_utc_bounds($dateFrom)['start']);
        }
        if ($dateTo !== '') {
            $query->where('t.created_at <=', ibems_business_day_utc_bounds($dateTo)['end']);
        }
        if ($search !== '') {
            $query->groupStart()->like('u.name', $search)->orLike('u.email', $search)->orLike('u.employee_id', $search)->groupEnd();
        }
        if ($employeeUserId > 0) {
            $query->where('u.id', $employeeUserId);
        }

        $total = (clone $query)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $rows = $query->orderBy($sortColumn, $sortDir)->orderBy('t.id', $sortDir)
            ->limit($pageSize, ($page - 1) * $pageSize)->get()->getResultArray();
        $transactions = array_map(static fn(array $row): array => [
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
        ], $rows);

        return ['code' => 200, 'payload' => [
            'status' => 'success', 'store_id' => $storeId, 'transactions' => $transactions,
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => $totalPages],
        ]];
    }

    private function applyFilters($query, $db, string $dateFrom, string $dateTo, string $paymentMethod, array $allowedPaymentMethods, bool $hasPaymentLines): void
    {
        if ($dateFrom !== '') {
            $query->where('t.created_at >=', ibems_business_day_utc_bounds($dateFrom)['start']);
        }
        if ($dateTo !== '') {
            $query->where('t.created_at <=', ibems_business_day_utc_bounds($dateTo)['end']);
        }
        if ($paymentMethod === '' || !in_array($paymentMethod, $allowedPaymentMethods, true)) {
            return;
        }
        if ($hasPaymentLines) {
            $query->groupStart()
                ->where('t.payment_method', $paymentMethod)
                ->orWhere('EXISTS (SELECT 1 FROM transaction_payments tp_filter WHERE tp_filter.transaction_id = t.id AND tp_filter.payment_method = ' . $db->escape($paymentMethod) . ')', null, false)
                ->groupEnd();
        } else {
            $query->where('t.payment_method', $paymentMethod);
        }
    }

    /** @return array{code:int,payload:array{status:string,message:string}} */
    private function error(int $code, string $message): array
    {
        return ['code' => $code, 'payload' => ['status' => 'error', 'message' => $message]];
    }
}
