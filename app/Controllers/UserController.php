<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\UserModel;
use CodeIgniter\Controller;
use Config\Database;
use Config\Services;
use App\Services\UserDashboardService;

class UserController extends Controller
{
    public function dashboard()
    {
        return view('user/dashboard');
    }

    public function dashboardData()
    {
        $result = (new UserDashboardService())->data(
            (int) session()->get('user_id'),
            (string) ($this->request->getGet('period') ?? 'day')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function debtPinStatus()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $userModel = new UserModel();
        $user = $userModel->find($userId);

        return $this->response->setJSON([
            'status' => 'success',
            'has_pin' => $user && trim((string) ($user['debt_pin_hash'] ?? '')) !== '',
        ]);
    }

    public function setDebtPin()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = $this->request->getPost();
        }

        $validation = Services::validation();
        $validation->setRules([
            'pin' => 'required|regex_match[/^[0-9]{4,6}$/]',
            'pin_confirm' => 'required|matches[pin]',
        ], [
            'pin' => [
                'regex_match' => 'Debt PIN must be 4 to 6 digits.',
            ],
            'pin_confirm' => [
                'matches' => 'Debt PIN confirmation does not match.',
            ],
        ]);

        if (!$validation->run($payload)) {
            $errors = $validation->getErrors();
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $errors[array_key_first($errors)] ?? 'Invalid debt PIN.',
            ]);
        }

        $userModel = new UserModel();
        $user = $userModel->find($userId);
        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'User account not found.',
            ]);
        }

        $hasExistingPin = trim((string) ($user['debt_pin_hash'] ?? '')) !== '';
        if ($hasExistingPin) {
            $currentPassword = (string) ($payload['current_password'] ?? '');
            if ($currentPassword === '' || !password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Enter your current password to change your debt PIN.',
                ]);
            }
        }

        $pin = (string) ($payload['pin'] ?? '');
        if (!$userModel->update($userId, [
            'debt_pin_hash' => password_hash($pin, PASSWORD_BCRYPT),
        ])) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Unable to update debt PIN.',
            ]);
        }

        (new AuditLogModel())->insert([
            'actor_id' => $userId,
            'action' => $hasExistingPin ? 'CHANGE_DEBT_PIN' : 'SET_DEBT_PIN',
            'entity' => 'users',
            'entity_id' => $userId,
            'payload_json' => json_encode([
                'self_service' => true,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => $hasExistingPin ? 'Debt PIN updated.' : 'Debt PIN set.',
            'has_pin' => true,
        ]);
    }

    public function history()
    {
        return view('user/history');
    }

    public function stores()
    {
        return view('user/stores');
    }

    public function storesData()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $q = trim((string) $this->request->getGet('q'));
        $storeId = max(0, (int) ($this->request->getGet('store_id') ?? 0));
        $category = trim((string) $this->request->getGet('category'));
        $availability = strtolower(trim((string) $this->request->getGet('availability')));
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'store')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pageSize = max(12, min(60, (int) ($this->request->getGet('page_size') ?? 24)));
        $includeOptions = filter_var($this->request->getGet('include_options') ?? true, FILTER_VALIDATE_BOOL);
        $offset = ($page - 1) * $pageSize;

        $sortColumns = [
            'store' => 's.store_name',
            'name' => 'p.name',
            'price' => 'p.price',
            'stock' => 'p.stock_qty',
            'category' => 'p.category',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['store'];

        $db = Database::connect();
        $applyFilters = static function ($query) use ($q, $storeId, $category, $availability) {
            $query->where('s.is_active', true)->where('p.is_active', true);
            if ($storeId > 0) {
                $query->where('p.store_id', $storeId);
            }
            if ($category !== '') {
                $query->where('p.category', $category);
            }
            if ($availability === 'available') {
                $query->where('p.stock_qty >', 0);
            } elseif ($availability === 'out') {
                $query->where('p.stock_qty <=', 0);
            }
            if ($q !== '') {
                $query->groupStart()
                    ->like('p.name', $q, 'both', null, true)
                    ->orLike('p.variant_label', $q, 'both', null, true)
                    ->orLike('p.category', $q, 'both', null, true)
                    ->orLike('p.sku', $q, 'both', null, true)
                    ->orLike('s.store_name', $q, 'both', null, true)
                    ->groupEnd();
            }
            return $query;
        };

        $countQuery = $applyFilters($db->table('products p')->join('stores s', 's.id = p.store_id', 'inner'));
        $total = $countQuery->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $query = $db->table('products p')
            ->select('p.id, p.store_id, p.family_id, p.sku, p.name, p.variant_label, p.category, p.supplier, p.location_bin, p.barcode, p.image_url, p.price, p.stock_qty, p.item_type, p.stock_policy, p.unit_code, p.updated_at, s.store_name, s.logo_url AS store_logo_url')
            ->join('stores s', 's.id = p.store_id', 'inner');
        $rows = $applyFilters($query)
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('p.name', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $familyIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['family_id'] ?? 0),
            $rows
        ))));
        $variantsByFamily = [];
        if ($familyIds !== []) {
            $variantRows = $db->table('products p')
                ->select('p.id, p.store_id, p.family_id, p.sku, p.name, p.variant_label, p.category, p.supplier, p.location_bin, p.barcode, p.image_url, p.price, p.stock_qty, p.item_type, p.stock_policy, p.unit_code, p.updated_at')
                ->whereIn('p.family_id', $familyIds)
                ->where('p.is_active', true)
                ->orderBy('p.variant_label', 'ASC')
                ->orderBy('p.id', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($variantRows as $variantRow) {
                $familyId = (int) ($variantRow['family_id'] ?? 0);
                if ($familyId > 0) {
                    $variantsByFamily[$familyId][] = $variantRow;
                }
            }
        }

        $stores = [];
        $categories = [];
        if ($includeOptions) {
            $stores = $db->table('stores s')
                ->select('s.id, s.store_name, s.logo_url, COUNT(p.id) AS product_count')
                ->join('products p', 'p.store_id = s.id AND p.is_active = TRUE', 'left', false)
                ->where('s.is_active', true)
                ->groupBy('s.id, s.store_name, s.logo_url')
                ->orderBy('s.store_name', 'ASC')
                ->get()
                ->getResultArray();
            $categories = $db->table('products p')
                ->select('p.category')
                ->join('stores s', 's.id = p.store_id', 'inner')
                ->where('p.is_active', true)
                ->where('s.is_active', true)
                ->where('p.category IS NOT NULL', null, false)
                ->where('p.category !=', '')
                ->groupBy('p.category')
                ->orderBy('p.category', 'ASC')
                ->get()
                ->getResultArray();
        }

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row) use ($variantsByFamily): array {
                $familyId = (int) ($row['family_id'] ?? 0);
                $variantRows = $familyId > 0 ? ($variantsByFamily[$familyId] ?? [$row]) : [$row];
                $mapVariant = static fn(array $variant): array => [
                    'id' => (int) ($variant['id'] ?? 0),
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'variant_label' => (string) ($variant['variant_label'] ?? ''),
                    'supplier' => (string) ($variant['supplier'] ?? ''),
                    'location_bin' => (string) ($variant['location_bin'] ?? ''),
                    'barcode' => (string) ($variant['barcode'] ?? ''),
                    'image_url' => (string) ($variant['image_url'] ?? ''),
                    'price' => (float) ($variant['price'] ?? 0),
                    'stock_qty' => (int) ($variant['stock_qty'] ?? 0),
                    'item_type' => (string) ($variant['item_type'] ?? 'stock_item'),
                    'stock_policy' => (string) ($variant['stock_policy'] ?? 'tracked'),
                    'unit_code' => (string) ($variant['unit_code'] ?? 'piece'),
                    'availability' => (string) ($variant['stock_policy'] ?? 'tracked') === 'untracked' || (int) ($variant['stock_qty'] ?? 0) > 0 ? 'available' : 'out',
                    'updated_at' => (string) ($variant['updated_at'] ?? ''),
                ];

                return [
                'id' => (int) ($row['id'] ?? 0),
                'store_id' => (int) ($row['store_id'] ?? 0),
                'family_id' => $familyId > 0 ? $familyId : null,
                'store_name' => (string) ($row['store_name'] ?? ''),
                'store_logo_url' => (string) ($row['store_logo_url'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'variant_label' => (string) ($row['variant_label'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'supplier' => (string) ($row['supplier'] ?? ''),
                'location_bin' => (string) ($row['location_bin'] ?? ''),
                'barcode' => (string) ($row['barcode'] ?? ''),
                'image_url' => (string) ($row['image_url'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
                'stock_qty' => (int) ($row['stock_qty'] ?? 0),
                'item_type' => (string) ($row['item_type'] ?? 'stock_item'),
                'stock_policy' => (string) ($row['stock_policy'] ?? 'tracked'),
                'unit_code' => (string) ($row['unit_code'] ?? 'piece'),
                'availability' => (string) ($row['stock_policy'] ?? 'tracked') === 'untracked' || (int) ($row['stock_qty'] ?? 0) > 0 ? 'available' : 'out',
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'variants' => array_map($mapVariant, $variantRows),
                ];
            }, $rows),
            'stores' => array_map(static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'store_name' => (string) ($row['store_name'] ?? ''),
                'logo_url' => (string) ($row['logo_url'] ?? ''),
                'product_count' => (int) ($row['product_count'] ?? 0),
            ], $stores),
            'categories' => array_values(array_map(static fn(array $row): string => (string) ($row['category'] ?? ''), $categories)),
            'options_included' => $includeOptions,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function deductions()
    {
        return view('user/deductions');
    }

    public function deductionsData()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $db = Database::connect();
        $periodRows = $db->table('deduction_batch_items dbi')
            ->select('dp.id, dp.period_code, dp.label, dp.date_start, dp.date_end')
            ->join('deduction_batches db', 'db.id = dbi.batch_id', 'inner')
            ->join('deduction_periods dp', 'dp.id = db.period_id', 'inner')
            ->where('dbi.user_id', $userId)
            ->where('dbi.result_status !=', 'pending')
            ->whereIn('db.status', ['processed', 'reconciled', 'finalized'])
            ->groupBy('dp.id, dp.period_code, dp.label, dp.date_start, dp.date_end')
            ->orderBy('dp.date_end', 'DESC')
            ->orderBy('dp.id', 'DESC')
            ->get()
            ->getResultArray();

        $periods = array_map(static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'period_code' => (string) ($row['period_code'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'date_start' => (string) ($row['date_start'] ?? ''),
            'date_end' => (string) ($row['date_end'] ?? ''),
        ], $periodRows);

        $requestedPeriodId = (int) ($this->request->getGet('period_id') ?? 0);
        $allowedPeriodIds = array_column($periods, 'id');
        $selectedPeriodId = $requestedPeriodId > 0 && in_array($requestedPeriodId, $allowedPeriodIds, true)
            ? $requestedPeriodId
            : (int) ($periods[0]['id'] ?? 0);

        $deductions = [];
        if ($selectedPeriodId > 0) {
            $rows = $db->table('deduction_batch_items dbi')
                ->select('dbi.id, dbi.requested_amount, dbi.confirmed_amount, dbi.debt_snapshot, dbi.carryover_amount, dbi.result_status, dbi.confirmed_at, dp.period_code, dp.label, dp.date_start, dp.date_end')
                ->join('deduction_batches db', 'db.id = dbi.batch_id', 'inner')
                ->join('deduction_periods dp', 'dp.id = db.period_id', 'inner')
                ->where('dbi.user_id', $userId)
                ->where('dp.id', $selectedPeriodId)
                ->where('dbi.result_status !=', 'pending')
                ->whereIn('db.status', ['processed', 'reconciled', 'finalized'])
                ->orderBy('dbi.confirmed_at', 'DESC')
                ->orderBy('dbi.id', 'DESC')
                ->get()
                ->getResultArray();

            $deductions = array_map(static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'period_code' => (string) ($row['period_code'] ?? ''),
                'period_label' => (string) ($row['label'] ?? ''),
                'date_start' => (string) ($row['date_start'] ?? ''),
                'date_end' => (string) ($row['date_end'] ?? ''),
                'requested_amount' => (float) ($row['requested_amount'] ?? 0),
                'deducted_amount' => (float) ($row['confirmed_amount'] ?? 0),
                'debt_before' => (float) ($row['debt_snapshot'] ?? 0),
                'debt_after' => (float) ($row['carryover_amount'] ?? 0),
                'status' => (string) ($row['result_status'] ?? ''),
                'applied_at' => (string) ($row['confirmed_at'] ?? ''),
            ], $rows);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'periods' => $periods,
            'selected_period_id' => $selectedPeriodId,
            'deductions' => $deductions,
            'summary' => [
                'entry_count' => count($deductions),
                'total_deducted' => array_sum(array_column($deductions, 'deducted_amount')),
                'debt_before' => (float) ($deductions[0]['debt_before'] ?? 0),
                'debt_after' => (float) ($deductions[0]['debt_after'] ?? 0),
            ],
        ]);
    }

    public function summary()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $db = Database::connect();
        $balance = $db->table('balances')
            ->select('credit_limit, current_debt')
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        $txnSummary = $db->table('transactions')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount),0) AS total_spent')
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        $cashbookSummaryRows = $db->table('debt_cashbook_entries')
            ->select('direction, COALESCE(SUM(amount),0) AS total_amount')
            ->where('user_id', $userId)
            ->groupBy('direction')
            ->get()
            ->getResultArray();

        $debtAddedTotal = 0.0;
        $deductedTotal = 0.0;
        foreach ($cashbookSummaryRows as $row) {
            $direction = strtolower((string) ($row['direction'] ?? ''));
            $amount = (float) ($row['total_amount'] ?? 0);
            if ($direction === 'debit') {
                $debtAddedTotal += $amount;
            } elseif ($direction === 'credit') {
                $deductedTotal += $amount;
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'credit_limit' => (float) ($balance['credit_limit'] ?? 0),
                'current_debt' => (float) ($balance['current_debt'] ?? 0),
                'available_credit' => max(0, (float) ($balance['credit_limit'] ?? 0) - (float) ($balance['current_debt'] ?? 0)),
                'txn_count' => (int) ($txnSummary['txn_count'] ?? 0),
                'total_spent' => (float) ($txnSummary['total_spent'] ?? 0),
                'debt_added_total' => $debtAddedTotal,
                'debt_deducted_total' => $deductedTotal,
            ] + (new UserDashboardService())->debtStatus((float) ($balance['credit_limit'] ?? 0), (float) ($balance['current_debt'] ?? 0)),
        ]);
    }

    public function cashbook()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $fromTs = $dateFrom !== '' ? ibems_business_day_utc_bounds($dateFrom)['start'] : '';
        $toTs = $dateTo !== '' ? ibems_business_day_utc_bounds($dateTo)['end'] : '';
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'date')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pageSize = max(10, min(100, (int) ($this->request->getGet('page_size') ?? $this->request->getGet('limit') ?? 25)));
        $offset = ($page - 1) * $pageSize;
        $sortColumns = [
            'date' => 'dce.created_at',
            'entry' => 'dce.entry_type',
            'direction' => 'dce.direction',
            'amount' => 'dce.amount',
            'balance' => 'dce.debt_after',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];

        $db = Database::connect();
        $applyFilters = static function ($query) use ($userId, $fromTs, $toTs) {
            $query->where('dce.user_id', $userId);
            if ($fromTs !== '') {
                $query->where('dce.created_at >=', $fromTs);
            }
            if ($toTs !== '') {
                $query->where('dce.created_at <=', $toTs);
            }
            return $query;
        };

        $total = $applyFilters($db->table('debt_cashbook_entries dce'))->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $query = $db->table('debt_cashbook_entries dce')
            ->select('dce.id, dce.created_at, dce.entry_type, dce.direction, dce.amount, dce.debt_before, dce.debt_after, dce.credit_limit_snapshot, dce.available_credit_snapshot, dce.reference_type, dce.reference_id, dce.remarks');
        $rows = $applyFilters($query)
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('dce.id', $sortDir)
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'entry_type' => (string) ($row['entry_type'] ?? ''),
                    'direction' => (string) ($row['direction'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'debt_before' => (float) ($row['debt_before'] ?? 0),
                    'debt_after' => (float) ($row['debt_after'] ?? 0),
                    'credit_limit_snapshot' => (float) ($row['credit_limit_snapshot'] ?? 0),
                    'available_credit_snapshot' => (float) ($row['available_credit_snapshot'] ?? 0),
                    'reference_type' => (string) ($row['reference_type'] ?? ''),
                    'reference_id' => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
                    'remarks' => (string) ($row['remarks'] ?? ''),
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

    public function transactions()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $dateFrom = trim((string) $this->request->getGet('date_from'));
        $dateTo = trim((string) $this->request->getGet('date_to'));
        $fromTs = $dateFrom !== '' ? ibems_business_day_utc_bounds($dateFrom)['start'] : '';
        $toTs = $dateTo !== '' ? ibems_business_day_utc_bounds($dateTo)['end'] : '';
        $storeId = max(0, (int) ($this->request->getGet('store_id') ?? 0));
        $sortBy = strtolower(trim((string) ($this->request->getGet('sort_by') ?? 'date')));
        $sortDir = strtolower(trim((string) ($this->request->getGet('sort_dir') ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pageSize = max(10, min(100, (int) ($this->request->getGet('page_size') ?? $this->request->getGet('limit') ?? 25)));
        $offset = ($page - 1) * $pageSize;
        $sortColumns = [
            'date' => 't.created_at',
            'store' => 's.store_name',
            'payment' => 't.payment_method',
            'amount' => 't.amount',
            'reference' => 't.client_txn_id',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];

        $db = Database::connect();
        $applyFilters = static function ($query) use ($userId, $fromTs, $toTs, $storeId) {
            $query->where('t.user_id', $userId);
            if ($fromTs !== '') {
                $query->where('t.created_at >=', $fromTs);
            }
            if ($toTs !== '') {
                $query->where('t.created_at <=', $toTs);
            }
            if ($storeId > 0) {
                $query->where('t.store_id', $storeId);
            }
            return $query;
        };

        $countQuery = $db->table('transactions t')->join('stores s', 's.id = t.store_id', 'left');
        $total = $applyFilters($countQuery)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $query = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.store_id, s.store_name')
            ->join('stores s', 's.id = t.store_id', 'left');
        $rows = $applyFilters($query)
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('t.id', $sortDir)
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $storeOptions = $db->table('transactions t')
            ->select('t.store_id, s.store_name')
            ->join('stores s', 's.id = t.store_id', 'left')
            ->where('t.user_id', $userId)
            ->groupBy('t.store_id, s.store_name')
            ->orderBy('s.store_name', 'ASC')
            ->get()
            ->getResultArray();

        $hasPaymentLines = in_array('transaction_payments', $db->listTables(), true);
        $totalsQuery = $db->table('transactions t')->join('stores s', 's.id = t.store_id', 'left');
        if ($hasPaymentLines) {
            $paymentTotals = '(SELECT transaction_id, SUM(CASE WHEN LOWER(payment_method) = \'debt\' THEN amount ELSE 0 END) AS debt_amount FROM transaction_payments GROUP BY transaction_id) tp';
            $totalsQuery
                ->select("t.store_id, s.store_name, COUNT(t.id) AS purchase_count, COALESCE(SUM(t.amount), 0) AS total_spent, COALESCE(SUM(CASE WHEN tp.transaction_id IS NOT NULL THEN tp.debt_amount WHEN LOWER(COALESCE(t.payment_method, '')) = 'debt' THEN t.amount ELSE 0 END), 0) AS debt_charged", false)
                ->join($paymentTotals, 'tp.transaction_id = t.id', 'left', false);
        } else {
            $totalsQuery->select("t.store_id, s.store_name, COUNT(t.id) AS purchase_count, COALESCE(SUM(t.amount), 0) AS total_spent, COALESCE(SUM(CASE WHEN LOWER(COALESCE(t.payment_method, '')) = 'debt' THEN t.amount ELSE 0 END), 0) AS debt_charged", false);
        }
        $totalsQuery->where('t.user_id', $userId);
        if ($fromTs !== '') {
            $totalsQuery->where('t.created_at >=', $fromTs);
        }
        if ($toTs !== '') {
            $totalsQuery->where('t.created_at <=', $toTs);
        }
        $storeTotals = $totalsQuery
            ->groupBy('t.store_id, s.store_name')
            ->orderBy('total_spent', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'client_txn_id' => $row['client_txn_id'],
                    'created_at' => $row['created_at'],
                    'payment_method' => $row['payment_method'],
                    'amount' => (float) $row['amount'],
                    'store_id' => (int) ($row['store_id'] ?? 0),
                    'store_name' => $row['store_name'] ?: ('Store #' . (int) $row['store_id']),
                ];
            }, $rows),
            'stores' => array_map(static fn(array $row): array => [
                'id' => (int) ($row['store_id'] ?? 0),
                'store_name' => (string) (($row['store_name'] ?? '') ?: ('Store #' . (int) ($row['store_id'] ?? 0))),
            ], $storeOptions),
            'store_totals' => array_map(static fn(array $row): array => [
                'store_id' => (int) ($row['store_id'] ?? 0),
                'store_name' => (string) (($row['store_name'] ?? '') ?: ('Store #' . (int) ($row['store_id'] ?? 0))),
                'purchase_count' => (int) ($row['purchase_count'] ?? 0),
                'total_spent' => (float) ($row['total_spent'] ?? 0),
                'debt_charged' => (float) ($row['debt_charged'] ?? 0),
            ], $storeTotals),
            'grand_totals' => [
                'purchase_count' => array_sum(array_column($storeTotals, 'purchase_count')),
                'total_spent' => array_sum(array_column($storeTotals, 'total_spent')),
                'debt_charged' => array_sum(array_column($storeTotals, 'debt_charged')),
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
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        if ($transactionId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid transaction id.',
            ]);
        }

        $db = Database::connect();
        $txn = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.customer_type, t.user_id, t.store_id, s.store_name, u.name AS customer_name')
            ->join('stores s', 's.id = t.store_id', 'left')
            ->join('users u', 'u.id = t.user_id', 'left')
            ->where('t.id', $transactionId)
            ->where('t.user_id', $userId)
            ->get()
            ->getRowArray();

        if (!$txn) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Transaction not found.',
            ]);
        }

        $itemsRaw = $db->table('transaction_items ti')
            ->select('ti.product_id, ti.qty, ti.unit_price, ti.line_total, COALESCE(ti.item_name_snapshot, p.name) AS product_name, ti.sku_snapshot, ti.variant_snapshot, ti.unit_code_snapshot')
            ->join('products p', 'p.id = ti.product_id', 'left')
            ->where('ti.transaction_id', $transactionId)
            ->orderBy('ti.id', 'ASC')
            ->get()
            ->getResultArray();

        $items = array_map(static function (array $row): array {
            $name = $row['product_name'] ?: ('Product #' . (int) ($row['product_id'] ?? 0));
            return [
                'name' => $name,
                'qty' => (int) ($row['qty'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'line_total' => (float) ($row['line_total'] ?? 0),
            ];
        }, $itemsRaw);

        $customerName = $txn['customer_name'] ?: (string) (session()->get('name') ?? 'User');

        return $this->response->setJSON([
            'status' => 'success',
            'transaction' => [
                'id' => (int) $txn['id'],
                'client_txn_id' => (string) ($txn['client_txn_id'] ?? ''),
                'created_at' => (string) ($txn['created_at'] ?? ''),
                'payment_method' => (string) ($txn['payment_method'] ?? ''),
                'amount' => (float) ($txn['amount'] ?? 0),
                'customer_name' => $customerName,
                'store_name' => (string) ($txn['store_name'] ?: ('Store #' . (int) ($txn['store_id'] ?? 0))),
                'items' => $items,
            ],
        ]);
    }

    public function receiptPage(int $transactionId)
    {
        if ($transactionId <= 0) {
            return redirect()->to('/user/history');
        }

        return view('user/receipt', [
            'transaction_id' => $transactionId,
        ]);
    }

}
