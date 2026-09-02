<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use Config\Database;

final class AdminUserService
{
    public function data(string $query): array
    {
        $q = trim($query);
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
        return $this->result(200, [
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

    public function detail(int $userId): array
    {
        if ($userId <= 0) {
            return $this->result(400, [
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
            return $this->result(404, [
                'status' => 'error',
                'message' => 'User not found.',
            ]);
        }

        return $this->result(200, [
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

    public function create(array $request, int $actorId, $profileImageFile = null): array
    {

        $employeeId = trim((string) ($request['employee_id'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $userType = strtolower(trim((string) ($request['user_type'] ?? 'staff')));
        // Employee accounts always begin in the personal User portal. Operational
        // store roles are granted later by the store-assignment workflow.
        $roles = ['USER'];
        $isActive = (int) ($request['is_active'] ?? 1) === 1;
        $password = (string) ($request['password'] ?? '');

        if ($name === '' || $email === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Name and email are required.',
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid email address.',
            ]);
        }

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        $roles = $this->sanitizeRoles($roles, ['USER']);
        foreach ($roles as $selectedRole) {
            if (!in_array($selectedRole, $allowedRoles, true)) {
                return $this->result(400, [
                    'status' => 'error',
                    'message' => 'Invalid role.',
                ]);
            }
        }
        if (!in_array($userType, $allowedTypes, true)) {
            return $this->result(400, [
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
                return $this->result(400, [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($userModel->where('email', $email)->first()) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Email already exists.',
            ]);
        }
        if ($employeeId !== '' && $userModel->where('employee_id', $employeeId)->first()) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Employee ID already exists.',
            ]);
        }

        $primaryRole = $this->pickPrimaryRole($roles);
        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : password_hash('123456', PASSWORD_BCRYPT);
        $initialCreditLimit = (float) ($financialProfile['credit_limit'] ?? 0);

        try {
            $profileImageUrl = $this->storeUserProfileImage($profileImageFile);
        } catch (\Throwable $exception) {
            return $this->result(400, ['status' => 'error', 'message' => $exception->getMessage()]);
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
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to create user.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'user_id' => (int) $userId,
            'initial_credit_limit' => $initialCreditLimit,
            'credit_percentage' => (float) ($financialProfile['credit_percentage'] ?? 0),
        ]);
    }

    public function update(array $request, int $actorId, $profileImageFile = null): array
    {
        $userId = (int) ($request['user_id'] ?? 0);

        $employeeId = trim((string) ($request['employee_id'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $userType = strtolower(trim((string) ($request['user_type'] ?? 'staff')));
        $roles = $this->extractRolesFromRequest($request);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;

        if ($userId <= 0 || $name === '' || $email === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid user update payload.',
            ]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid email address.',
            ]);
        }

        $allowedRoles = ['USER', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ADMIN'];
        $allowedTypes = ['faculty', 'staff', 'student'];
        $roles = $this->sanitizeRoles($roles, ['USER']);
        foreach ($roles as $selectedRole) {
            if (!in_array($selectedRole, $allowedRoles, true)) {
                return $this->result(400, [
                    'status' => 'error',
                    'message' => 'Invalid role or user type.',
                ]);
            }
        }
        if (!in_array($userType, $allowedTypes, true)) {
            return $this->result(400, [
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
            return $this->result(404, [
                'status' => 'error',
                'message' => 'User not found.',
            ]);
        }
        $roles = $this->employeeRolesWithAssignments($userId, $roles, $db);
        $existingBalance = $balanceModel->find($userId);
        $financialProfile = null;
        if (in_array($userType, ['faculty', 'staff'], true)) {
            try {
                $financialProfile = $this->resolveEmployeeFinancialProfile($db, $request, array_merge($user, $existingBalance ?? []));
            } catch (\InvalidArgumentException $exception) {
                return $this->result(400, [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $sameEmail = $db->table('users')->where('email', $email)->where('id !=', $userId)->get()->getRowArray();
        if ($sameEmail) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Email already exists.',
            ]);
        }
        if ($employeeId !== '') {
            $sameEmp = $db->table('users')->where('employee_id', $employeeId)->where('id !=', $userId)->get()->getRowArray();
            if ($sameEmp) {
                return $this->result(409, [
                    'status' => 'error',
                    'message' => 'Employee ID already exists.',
                ]);
            }
        }

        $primaryRole = $this->pickPrimaryRole($roles);
        try {
            $profileImageUrl = $this->storeUserProfileImage($profileImageFile, (string) ($user['profile_image_url'] ?? ''));
        } catch (\Throwable $exception) {
            return $this->result(400, ['status' => 'error', 'message' => $exception->getMessage()]);
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
            return $this->result(500, [
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

        return $this->result(200, [
            'status' => 'success',
        ]);
    }

    public function importCsv($file, int $actorId): array
    {
        if (!$file || !$file->isValid()) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Please upload a valid CSV file.',
            ]);
        }

        $handle = fopen($file->getTempName(), 'rb');
        if ($handle === false) {
            return $this->result(400, [
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
                return $this->result(400, [
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
            $existing = $db->table('users')->where('email', $email)->get()->getRowArray();
            if (!$existing && $employeeId !== '') {
                $existing = $db->table('users')->where('employee_id', $employeeId)->get()->getRowArray();
            }
            $roles = $existing
                ? $this->employeeRolesWithAssignments((int) $existing['id'], $roles, $db)
                : ['USER'];
            $primaryRole = $this->pickPrimaryRole($roles);

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
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Import failed and no employee records were applied. Please try again or contact the administrator.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'invalid' => $invalid,
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

    private function storeUserProfileImage($file, ?string $currentUrl = null): ?string
    {
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return (new AssetStorageService())->storeImage($file, 'profile-images', $currentUrl);
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

    /**
     * Store roles are assignment-owned. Independent roles may still be edited,
     * but the employee and store staffing records cannot drift apart.
     */
    private function employeeRolesWithAssignments(int $userId, array $requestedRoles, $db): array
    {
        $roles = array_values(array_filter(
            $this->sanitizeRoles($requestedRoles, ['USER']),
            static fn(string $role): bool => !in_array($role, ['STORE_SYSTEM', 'STORE_SUPERVISOR'], true)
        ));
        if (!in_array('USER', $roles, true)) {
            $roles[] = 'USER';
        }

        if ($userId > 0 && $db->table('stores')->where('officer_id', $userId)->countAllResults() > 0) {
            $roles[] = 'STORE_SYSTEM';
        }
        if ($userId > 0 && $db->tableExists('store_supervisors')
            && $db->table('store_supervisors')->where('user_id', $userId)->countAllResults() > 0) {
            $roles[] = 'STORE_SUPERVISOR';
        }

        return array_values(array_unique($roles));
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

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
