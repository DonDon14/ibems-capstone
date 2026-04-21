<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\StoreModel;
use App\Models\UserModel;
use CodeIgniter\Controller;
use Config\Database;

class AdminController extends Controller
{
    public function dashboard()
    {
        return view('admin/dashboard');
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

        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : password_hash('123456', PASSWORD_BCRYPT);

        $db->transStart();

        $userId = $userModel->insert([
            'employee_id' => $employeeId !== '' ? $employeeId : null,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'user_type' => $userType,
            'qr_token' => bin2hex(random_bytes(16)),
            'base_salary' => max(0, $baseSalary),
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($userId) {
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
                'role' => $role,
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

        $db->transStart();

        $userModel->update($userId, [
            'employee_id' => $employeeId !== '' ? $employeeId : null,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'user_type' => $userType,
            'base_salary' => max(0, $baseSalary),
            'is_active' => $isActive,
        ]);

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
                'role' => $role,
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
            $userType = strtolower($row['user_type'] ?? '');
            $employeeId = $row['employee_id'] ?? '';
            $baseSalary = isset($row['base_salary']) ? (float) $row['base_salary'] : 0;
            $creditLimit = isset($row['credit_limit']) ? (float) $row['credit_limit'] : 0;
            $isActive = isset($row['is_active']) ? ((int) $row['is_active'] === 1 ? 1 : 0) : 1;

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedRoles, true) || !in_array($userType, $allowedTypes, true)) {
                $invalid++;
                continue;
            }

            $existing = $db->table('users')->where('email', $email)->get()->getRowArray();
            if (!$existing && $employeeId !== '') {
                $existing = $db->table('users')->where('employee_id', $employeeId)->get()->getRowArray();
            }

            if ($existing) {
                $userModel->update((int) $existing['id'], [
                    'employee_id' => $employeeId !== '' ? $employeeId : $existing['employee_id'],
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
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
                    'role' => $role,
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

        if ($storeId && $officer['role'] !== 'STORE_SYSTEM') {
            $userModel->update($officerId, ['role' => 'STORE_SYSTEM']);
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

        if ($officer['role'] !== 'STORE_SYSTEM') {
            $userModel->update($officerId, ['role' => 'STORE_SYSTEM']);
        }

        if ($previousOfficerId > 0 && $previousOfficerId !== $officerId) {
            $assignedCount = $db->table('stores')->where('officer_id', $previousOfficerId)->countAllResults();
            if ($assignedCount === 0) {
                $previousOfficer = $userModel->find($previousOfficerId);
                if ($previousOfficer && $previousOfficer['role'] === 'STORE_SYSTEM') {
                    $userModel->update($previousOfficerId, ['role' => 'USER']);
                }
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
}
