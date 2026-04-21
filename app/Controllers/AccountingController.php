<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\SalaryImportBatchModel;
use App\Models\SalaryImportRowModel;
use App\Models\UserModel;
use CodeIgniter\Controller;
use Config\Database;

class AccountingController extends Controller
{
    public function dashboard()
    {
        return view('accounting/dashboard');
    }

    public function debts()
    {
        return view('accounting/debts');
    }

    public function debtsData()
    {
        $q = trim((string) $this->request->getGet('q'));
        $debtOnly = (int) ($this->request->getGet('debt_only') ?? 0) === 1;
        $limit = (int) ($this->request->getGet('limit') ?? 200);
        $limit = max(1, min(500, $limit));

        $db = Database::connect();

        $query = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, b.credit_limit, b.current_debt, b.updated_at')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
            ->whereIn('u.user_type', ['faculty', 'staff']);

        if ($debtOnly) {
            $query->where('b.current_debt >', 0);
        }

        if ($q !== '') {
            $query->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        $rows = $query
            ->orderBy('u.name', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $data = array_map(static function (array $row): array {
            $creditLimit = (float) $row['credit_limit'];
            $currentDebt = (float) $row['current_debt'];
            $row['available_credit'] = max(0, $creditLimit - $currentDebt);
            return $row;
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    public function debtProfile()
    {
        $userId = (int) ($this->request->getGet('user_id') ?? 0);
        if ($userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user_id.',
            ]);
        }

        $db = Database::connect();
        $row = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, b.credit_limit, b.current_debt, b.updated_at')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Record not found.',
            ]);
        }

        $creditLimit = (float) $row['credit_limit'];
        $currentDebt = (float) $row['current_debt'];
        $row['available_credit'] = max(0, $creditLimit - $currentDebt);

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $row,
        ]);
    }

    public function debtHistory()
    {
        $userId = (int) ($this->request->getGet('user_id') ?? 0);
        $limit = (int) ($this->request->getGet('limit') ?? 30);
        $limit = max(1, min(100, $limit));

        if ($userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user_id.',
            ]);
        }

        $db = Database::connect();
        $rows = $db->table('audit_logs al')
            ->select('al.id, al.created_at, al.action, al.payload_json, u.name AS actor_name')
            ->join('users u', 'u.id = al.actor_id', 'left')
            ->where('al.entity', 'balances')
            ->where('al.entity_id', $userId)
            ->whereIn('al.action', [
                'ACCOUNTING_DEDUCT_DEBT',
                'ACCOUNTING_DEDUCT_FULL_DEBT',
                'ACCOUNTING_UPDATE_CREDIT_LIMIT',
            ])
            ->orderBy('al.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $history = array_map(static function (array $row): array {
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string) $row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            return [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'action' => $row['action'],
                'actor_name' => $row['actor_name'] ?: 'Unknown',
                'payload' => $payload,
            ];
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $history,
        ]);
    }

    public function dailySummary()
    {
        $db = Database::connect();
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');

        $rows = $db->table('audit_logs')
            ->select('action, payload_json')
            ->whereIn('action', [
                'ACCOUNTING_DEDUCT_DEBT',
                'ACCOUNTING_DEDUCT_FULL_DEBT',
            ])
            ->where('created_at >=', $start)
            ->where('created_at <=', $end)
            ->get()
            ->getResultArray();

        $count = 0;
        $amount = 0.0;
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $deducted = (float) ($payload['deducted_amount'] ?? 0);
            if ($deducted <= 0) {
                continue;
            }
            $count++;
            $amount += $deducted;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'date' => date('Y-m-d'),
            'deduction_count' => $count,
            'deducted_amount' => $amount,
        ]);
    }

    public function importCsv()
    {
        $actorId = (int) session()->get('user_id');
        $file = $this->request->getFile('csv_file');

        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Please upload a valid CSV file.',
            ]);
        }

        $extension = strtolower((string) $file->getExtension());
        if (!in_array($extension, ['csv', 'txt'], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Unsupported file type. Use .csv',
            ]);
        }

        $path = $file->getTempName();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Unable to read CSV.',
            ]);
        }

        $rawHeaders = fgetcsv($handle);
        if (!is_array($rawHeaders) || $rawHeaders === []) {
            fclose($handle);
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'CSV has no header row.',
            ]);
        }

        $headers = array_map(static function ($value): string {
            return strtolower(trim((string) $value));
        }, $rawHeaders);

        $required = ['employee_id', 'name', 'email', 'user_type', 'monthly_salary'];
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                fclose($handle);
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => "Missing required column: {$field}",
                ]);
            }
        }

        $hasCreditLimit = in_array('credit_limit', $headers, true);

        $batchModel = new SalaryImportBatchModel();
        $rowModel = new SalaryImportRowModel();
        $userModel = new UserModel();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();

        $db = Database::connect();
        $db->transStart();

        $batchId = $batchModel->insert([
            'filename' => $file->getClientName(),
            'imported_by' => $actorId,
            'imported_at' => date('Y-m-d H:i:s'),
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
        ]);

        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $invalidPreview = [];

        while (($rowValues = fgetcsv($handle)) !== false) {
            $totalRows++;
            $rowAssoc = [];
            foreach ($headers as $index => $column) {
                $rowAssoc[$column] = trim((string) ($rowValues[$index] ?? ''));
            }

            $employeeId = $rowAssoc['employee_id'] ?? '';
            $name = $rowAssoc['name'] ?? '';
            $email = $rowAssoc['email'] ?? '';
            $userType = strtolower((string) ($rowAssoc['user_type'] ?? ''));
            $monthlySalary = (float) ($rowAssoc['monthly_salary'] ?? 0);
            $creditLimit = $hasCreditLimit ? (float) ($rowAssoc['credit_limit'] ?? 0) : null;

            $errors = [];
            if ($name === '') {
                $errors[] = 'name is required';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'valid email is required';
            }
            if (!in_array($userType, ['faculty', 'staff'], true)) {
                $errors[] = 'user_type must be faculty or staff';
            }
            if ($monthlySalary < 0) {
                $errors[] = 'monthly_salary must be >= 0';
            }
            if ($hasCreditLimit && $creditLimit !== null && $creditLimit < 0) {
                $errors[] = 'credit_limit must be >= 0';
            }

            if ($errors !== []) {
                $invalidRows++;
                $errorMsg = implode('; ', $errors);
                $rowModel->insert([
                    'batch_id' => $batchId,
                    'employee_id' => $employeeId !== '' ? $employeeId : null,
                    'name' => $name !== '' ? $name : null,
                    'email' => $email !== '' ? $email : null,
                    'monthly_salary' => max(0, $monthlySalary),
                    'status' => 'invalid',
                    'error_msg' => $errorMsg,
                ]);
                if (count($invalidPreview) < 15) {
                    $invalidPreview[] = [
                        'line' => $totalRows + 1,
                        'employee_id' => $employeeId,
                        'name' => $name,
                        'email' => $email,
                        'error' => $errorMsg,
                    ];
                }
                continue;
            }

            $existingUser = null;
            if ($employeeId !== '') {
                $existingUser = $db->table('users')->where('employee_id', $employeeId)->get()->getRowArray();
            }
            if (!$existingUser) {
                $existingUser = $db->table('users')->where('email', $email)->get()->getRowArray();
            }

            if ($existingUser) {
                $userModel->update((int) $existingUser['id'], [
                    'employee_id' => $employeeId !== '' ? $employeeId : $existingUser['employee_id'],
                    'name' => $name,
                    'email' => $email,
                    'user_type' => $userType,
                    'base_salary' => $monthlySalary,
                    'is_active' => 1,
                ]);
                $userId = (int) $existingUser['id'];
            } else {
                $generatedPassword = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                $qrToken = bin2hex(random_bytes(16));

                $userId = $userModel->insert([
                    'employee_id' => $employeeId !== '' ? $employeeId : null,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $generatedPassword,
                    'role' => 'USER',
                    'user_type' => $userType,
                    'qr_token' => $qrToken,
                    'base_salary' => $monthlySalary,
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if (!$userId) {
                $invalidRows++;
                $errorMsg = 'failed to create/update user';
                $rowModel->insert([
                    'batch_id' => $batchId,
                    'employee_id' => $employeeId !== '' ? $employeeId : null,
                    'name' => $name,
                    'email' => $email,
                    'monthly_salary' => max(0, $monthlySalary),
                    'status' => 'invalid',
                    'error_msg' => $errorMsg,
                ]);
                if (count($invalidPreview) < 15) {
                    $invalidPreview[] = [
                        'line' => $totalRows + 1,
                        'employee_id' => $employeeId,
                        'name' => $name,
                        'email' => $email,
                        'error' => $errorMsg,
                    ];
                }
                continue;
            }

            $balance = $balanceModel->getBalanceByUserId((int) $userId);
            if ($balance) {
                $updateData = ['updated_at' => date('Y-m-d H:i:s')];
                if ($hasCreditLimit && $creditLimit !== null) {
                    $updateData['credit_limit'] = $creditLimit;
                }
                $balanceModel->update((int) $userId, $updateData);
            } else {
                $balanceModel->insert([
                    'user_id' => (int) $userId,
                    'credit_limit' => $hasCreditLimit && $creditLimit !== null ? $creditLimit : 0,
                    'current_debt' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $validRows++;
            $rowModel->insert([
                'batch_id' => $batchId,
                'employee_id' => $employeeId !== '' ? $employeeId : null,
                'name' => $name,
                'email' => $email,
                'monthly_salary' => max(0, $monthlySalary),
                'status' => 'valid',
                'error_msg' => null,
            ]);
        }

        fclose($handle);

        $batchModel->update((int) $batchId, [
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ACCOUNTING_IMPORT_HR_CSV',
            'entity' => 'salary_import_batches',
            'entity_id' => (int) $batchId,
            'payload_json' => json_encode([
                'filename' => $file->getClientName(),
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Import failed due to database error.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'batch_id' => (int) $batchId,
            'filename' => $file->getClientName(),
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'invalid_preview' => $invalidPreview,
        ]);
    }

    public function deductDebt()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $actorId = (int) session()->get('user_id');
        $userId = (int) ($request['user_id'] ?? 0);
        $amount = (float) ($request['amount'] ?? 0);
        $reason = trim((string) ($request['reason'] ?? 'Manual deduction'));

        if ($userId <= 0 || $amount <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid deduction payload.',
            ]);
        }

        $db = Database::connect();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();

        $balance = $balanceModel->getBalanceByUserId($userId);
        if (!$balance) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Balance record not found.',
            ]);
        }

        $currentDebt = (float) $balance['current_debt'];
        if ($currentDebt <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'This account has no debt.',
            ]);
        }

        if ($amount > $currentDebt) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Deduction cannot exceed current debt.',
            ]);
        }

        $newDebt = $currentDebt - $amount;

        $db->transStart();

        $balanceModel->update($userId, [
            'current_debt' => $newDebt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ACCOUNTING_DEDUCT_DEBT',
            'entity' => 'balances',
            'entity_id' => $userId,
            'payload_json' => json_encode([
                'user_id' => $userId,
                'previous_debt' => $currentDebt,
                'deducted_amount' => $amount,
                'new_debt' => $newDebt,
                'reason' => $reason,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to apply deduction.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'user_id' => $userId,
            'previous_debt' => $currentDebt,
            'deducted_amount' => $amount,
            'new_debt' => $newDebt,
        ]);
    }

    public function deductFullDebt()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $actorId = (int) session()->get('user_id');
        $userId = (int) ($request['user_id'] ?? 0);
        $reason = trim((string) ($request['reason'] ?? 'Full debt deduction'));

        if ($userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid payload.',
            ]);
        }

        $db = Database::connect();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();

        $balance = $balanceModel->getBalanceByUserId($userId);
        if (!$balance) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Balance record not found.',
            ]);
        }

        $currentDebt = (float) $balance['current_debt'];
        if ($currentDebt <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'This account has no debt.',
            ]);
        }

        $db->transStart();

        $balanceModel->update($userId, [
            'current_debt' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ACCOUNTING_DEDUCT_FULL_DEBT',
            'entity' => 'balances',
            'entity_id' => $userId,
            'payload_json' => json_encode([
                'user_id' => $userId,
                'previous_debt' => $currentDebt,
                'deducted_amount' => $currentDebt,
                'new_debt' => 0,
                'reason' => $reason,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to apply full deduction.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'user_id' => $userId,
            'previous_debt' => $currentDebt,
            'deducted_amount' => $currentDebt,
            'new_debt' => 0,
        ]);
    }

    public function updateCreditLimit()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $actorId = (int) session()->get('user_id');
        $userId = (int) ($request['user_id'] ?? 0);
        $creditLimit = (float) ($request['credit_limit'] ?? -1);
        $reason = trim((string) ($request['reason'] ?? 'Manual credit limit update'));

        if ($userId <= 0 || $creditLimit < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid credit limit payload.',
            ]);
        }

        $db = Database::connect();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();

        $balance = $balanceModel->getBalanceByUserId($userId);
        if (!$balance) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Balance record not found.',
            ]);
        }

        $previousLimit = (float) $balance['credit_limit'];

        $db->transStart();

        $balanceModel->update($userId, [
            'credit_limit' => $creditLimit,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ACCOUNTING_UPDATE_CREDIT_LIMIT',
            'entity' => 'balances',
            'entity_id' => $userId,
            'payload_json' => json_encode([
                'user_id' => $userId,
                'previous_credit_limit' => $previousLimit,
                'new_credit_limit' => $creditLimit,
                'reason' => $reason,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update credit limit.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'user_id' => $userId,
            'previous_credit_limit' => $previousLimit,
            'new_credit_limit' => $creditLimit,
        ]);
    }
}
