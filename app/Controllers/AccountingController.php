<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\SalaryImportBatchModel;
use App\Models\SalaryImportRowModel;
use App\Models\UserModel;
use App\Services\DebtInvestigationService;
use App\Services\SalaryCreditPolicy;
use App\Services\AccountingReportingService;
use App\Services\AccountingDebtQueryService;
use App\Services\AccountingSettlementQueryService;
use App\Services\AccountingFinancialProfileService;
use App\Services\SalaryScheduleService;
use App\Services\SalaryImportIdentityGuard;
use CodeIgniter\Controller;
use Config\Database;

class AccountingController extends Controller
{
    public function deductions()
    {
        return redirect()->to(site_url('deductions/workspace'));
    }

    public function debtInvestigationsData()
    {
        $result = (new DebtInvestigationService())->data(max(0, (int) ($this->request->getGet('user_id') ?? 0)));

        return $this->response->setJSON($result);
    }

    public function openDebtInvestigation()
    {
        $result = (new DebtInvestigationService())->open(
            str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json') ? $this->request->getJSON(true) : $this->request->getPost(),
            (int) session()->get('user_id')
        );
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    public function recommendDebtInvestigation(int $investigationId)
    {
        $result = (new DebtInvestigationService())->recommend(
            $investigationId,
            str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json') ? $this->request->getJSON(true) : $this->request->getPost(),
            (int) session()->get('user_id')
        );
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    public function approveDebtInvestigation(int $investigationId)
    {
        $result = (new DebtInvestigationService())->approveAndPost(
            $investigationId,
            (int) session()->get('user_id')
        );
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    public function dashboard()
    {
        return view('accounting/dashboard');
    }

    public function dashboardData()
    {
        $reporting = new AccountingReportingService();

        return $this->response->setJSON($reporting->dashboardData(
            Database::connect(),
            (string) ($this->request->getGet('period') ?? 'day')
        ));
    }

    public function debts()
    {
        return view('accounting/debts');
    }

    public function debtsData()
    {
        $query = new AccountingDebtQueryService();

        return $this->response->setJSON($query->listAccounts(
            Database::connect(),
            trim((string) $this->request->getGet('q')),
            (int) ($this->request->getGet('debt_only') ?? 0) === 1,
            (int) ($this->request->getGet('limit') ?? 200)
        ));
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
        $row = (new AccountingDebtQueryService())->profile($db, $userId);
        if ($row === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Record not found.',
            ]);
        }

        $row['pending_salary_profiles'] = (new \App\Services\ScheduledSalaryProfileService())->pendingForUser($db, $userId);
        return $this->response->setJSON(['status' => 'success', 'data' => $row]);
    }

    public function activateScheduledSalaryProfile(int $profileId)
    {
        $result = (new \App\Services\ScheduledSalaryProfileService())->activate(
            Database::connect(), $profileId, (int) session()->get('user_id')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function scheduledSalaryQueue()
    {
        $pageInput = $this->request->getGet('page');
        $page = $pageInput === null ? 1 : filter_var($pageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($page === false) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Page must be a positive integer.',
            ]);
        }
        $queue = (new \App\Services\ScheduledSalaryProfileService())->pendingQueue(Database::connect(), $page);
        if ($queue === null) {
            return $this->response->setStatusCode(503)->setJSON([
                'status' => 'error',
                'message' => 'Scheduled salary profiles are unavailable until the latest migration is applied.',
            ]);
        }

        return $this->response->setJSON(['status' => 'success'] + $queue);
    }

    public function debtHistory()
    {
        $userId = (int) ($this->request->getGet('user_id') ?? 0);
        if ($userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid user_id.',
            ]);
        }

        $history = (new AccountingDebtQueryService())->history(
            Database::connect(),
            $userId,
            (int) ($this->request->getGet('limit') ?? 30)
        );

        return $this->response->setJSON(['status' => 'success', 'data' => $history]);
    }

    public function dailySummary()
    {
        $reporting = new AccountingReportingService();

        return $this->response->setJSON($reporting->dailySummary(Database::connect()));
    }

    public function settlementPreview()
    {
        $runMonth = trim((string) ($this->request->getGet('run_month') ?? date('Y-m')));
        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $runMonth)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'run_month must be in YYYY-MM format.',
            ]);
        }

        $query = new AccountingSettlementQueryService();

        return $this->response->setJSON($query->preview(Database::connect(), $runMonth));
    }

    public function settlementRuns()
    {
        $query = new AccountingSettlementQueryService();
        $runs = $query->runs(Database::connect(), (int) ($this->request->getGet('limit') ?? 20));

        return $this->response->setJSON(['status' => 'success', 'data' => $runs]);
    }

    public function settlementRunDetails(int $runId)
    {
        if ($runId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid run id.',
            ]);
        }

        $data = (new AccountingSettlementQueryService())->runDetails(Database::connect(), $runId);
        if ($data === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Settlement run not found.',
            ]);
        }

        return $this->response->setJSON($data);
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

        $headerError = $this->salaryCsvHeaderError($headers);
        if ($headerError !== null) {
            fclose($handle);
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $headerError,
            ]);
        }

        $batchModel = new SalaryImportBatchModel();
        $rowModel = new SalaryImportRowModel();
        $userModel = new UserModel();
        $balanceModel = new BalanceModel();
        $auditLogModel = new AuditLogModel();
        $sourceHash = hash_file('sha256', $path);

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
        $identityGuard = new SalaryImportIdentityGuard();
        $seenEmployeeIds = [];
        $seenEmails = [];

        while (($rowValues = fgetcsv($handle)) !== false) {
            $totalRows++;
            $rowAssoc = [];
            foreach ($headers as $index => $column) {
                $rowAssoc[$column] = trim((string) ($rowValues[$index] ?? ''));
            }

            $employeeId = $rowAssoc['employee_id'] ?? '';
            $name = $rowAssoc['name'] ?? '';
            $email = strtolower($rowAssoc['email'] ?? '');
            $userType = strtolower((string) ($rowAssoc['user_type'] ?? ''));
            $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($rowAssoc['employment_type'] ?? ''));
            $salaryGrade = SalaryCreditPolicy::normalizeSalaryGrade((string) ($rowAssoc['salary_grade'] ?? ''));
            $salaryStep = SalaryCreditPolicy::parseSalaryStep($rowAssoc['salary_step'] ?? null);
            $effectiveDate = trim((string) ($rowAssoc['salary_effective_date'] ?? ''));
            $scheduleCode = strtoupper(trim((string) ($rowAssoc['salary_schedule_code'] ?? '')));
            if ($scheduleCode === '') {
                $scheduleCode = SalaryScheduleService::STANDARD_SCHEDULE_CODE;
            }
            $creditPercentage = trim((string) ($rowAssoc['credit_percentage'] ?? '')) === ''
                ? SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE)
                : SalaryCreditPolicy::parseCreditPercentage($rowAssoc['credit_percentage']);
            $resolvedRate = $salaryStep === null ? null : (new SalaryScheduleService())->resolveRateByCode($db, $scheduleCode, $salaryGrade, $salaryStep);
            $monthlySalary = (float) ($resolvedRate['monthly_salary'] ?? 0);
            $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage ?? 0);
            $creditLimit = SalaryCreditPolicy::creditLimit($monthlySalary, $creditRate);

            $errors = count($rowValues) === count($headers)
                ? [] : ['row must contain exactly one value for each CSV column'];
            if ($name === '') {
                $errors[] = 'name is required';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'valid email is required';
            }
            if (!in_array($userType, ['faculty', 'staff'], true)) {
                $errors[] = 'user_type must be faculty or staff';
            }
            if (!in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)) {
                $errors[] = 'employment_type must be plantilla, cos, or part_time';
            }
            if ($resolvedRate === null) {
                $errors[] = 'salary schedule, grade, and step combination is invalid';
            }
            if ($salaryStep === null) {
                $errors[] = 'salary_step must be an integer from 1 to 8';
            }
            if (!SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)) {
                $errors[] = 'salary_effective_date must be YYYY-MM-DD';
            }
            if (SalaryCreditPolicy::isFutureEffectiveDate($effectiveDate)) {
                $errors[] = 'future-effective salary profiles cannot be imported until scheduling is available';
            }
            if ($resolvedRate !== null && ($effectiveDate < $resolvedRate['effective_from'] || ($resolvedRate['effective_to'] !== null && $effectiveDate > $resolvedRate['effective_to']))) {
                $errors[] = 'salary_effective_date is outside the selected schedule';
            }
            if ($creditPercentage === null) {
                $errors[] = 'credit_percentage must be a number from 0 to 100 with at most two decimals';
            }
            $identity = ['errors' => [], 'existing' => null];
            if ($errors === []) {
                $identity = $identityGuard->inspect($db, $employeeId, $email, $seenEmployeeIds, $seenEmails);
                array_push($errors, ...$identity['errors']);
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

            $identityGuard->claim($employeeId, $email, $seenEmployeeIds, $seenEmails);
            $existingUser = $identity['existing'];
            $previousBalance = $existingUser ? $balanceModel->getBalanceByUserId((int) $existingUser['id']) : null;
            $previousProfile = $existingUser ? array_intersect_key($existingUser, array_flip([
                'user_type', 'employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id',
            ])) : null;

            if ($existingUser) {
                $userSaved = $userModel->update((int) $existingUser['id'], [
                    'employee_id' => $employeeId !== '' ? $employeeId : $existingUser['employee_id'],
                    'name' => $name,
                    'email' => $email,
                    'user_type' => $userType,
                    'base_salary' => $monthlySalary,
                    'employment_type' => $employmentType,
                    'salary_grade' => $resolvedRate['salary_grade_label'],
                    'salary_step' => $salaryStep,
                    'salary_effective_date' => $effectiveDate,
                    'salary_schedule_id' => $resolvedRate['schedule_id'],
                    'is_active' => true,
                ]);
                $userId = $userSaved ? (int) $existingUser['id'] : false;
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
                    'employment_type' => $employmentType,
                    'salary_grade' => $resolvedRate['salary_grade_label'],
                    'salary_step' => $salaryStep,
                    'salary_effective_date' => $effectiveDate,
                    'salary_schedule_id' => $resolvedRate['schedule_id'],
                    'is_active' => true,
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
                $updateData = ['credit_limit' => $creditLimit, 'credit_rate' => $creditRate, 'updated_at' => date('Y-m-d H:i:s')];
                $balanceSaved = $balanceModel->update((int) $userId, $updateData);
            } else {
                $balanceSaved = $balanceModel->insert([
                    'user_id' => (int) $userId,
                    'credit_limit' => $creditLimit,
                    'credit_rate' => $creditRate,
                    'current_debt' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $profileAudit = $balanceSaved && $auditLogModel->insert([
                'actor_id' => $actorId,
                'action' => 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE',
                'entity' => 'balances',
                'entity_id' => (int) $userId,
                'payload_json' => json_encode([
                    'source' => 'salary_csv_import',
                    'import_batch_id' => (int) $batchId,
                    'source_sha256' => $sourceHash,
                    'source_line' => $totalRows + 1,
                    'operation' => $existingUser ? 'update' : 'create',
                    'reason' => 'Accounting salary schedule CSV import',
                    'previous_base_salary' => $existingUser ? (float) $existingUser['base_salary'] : null,
                    'new_base_salary' => $monthlySalary,
                    'previous_credit_limit' => $previousBalance ? (float) $previousBalance['credit_limit'] : null,
                    'new_credit_limit' => $creditLimit,
                    'previous_salary_profile' => $previousProfile,
                    'new_salary_profile' => [
                        'user_type' => $userType, 'employment_type' => $employmentType,
                        'salary_grade' => $resolvedRate['salary_grade_label'], 'salary_step' => $salaryStep,
                        'salary_effective_date' => $effectiveDate, 'salary_schedule_id' => $resolvedRate['schedule_id'],
                    ],
                    'previous_credit_percentage' => $previousBalance ? SalaryCreditPolicy::percentageFromRate((float) $previousBalance['credit_rate']) : null,
                    'new_credit_percentage' => $creditPercentage,
                    'current_debt' => (float) ($previousBalance['current_debt'] ?? 0),
                ]),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$profileAudit) {
                fclose($handle);
                $db->transRollback();
                return $this->response->setStatusCode(500)->setJSON([
                    'status' => 'error',
                    'message' => 'Import could not save the financial profile and its audit history. No changes were applied.',
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
                'source_sha256' => $sourceHash,
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

    public function previewImportCsv()
    {
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

        $handle = fopen($file->getTempName(), 'rb');
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

        $headerError = $this->salaryCsvHeaderError($headers);
        if ($headerError !== null) {
            fclose($handle);
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $headerError,
            ]);
        }

        $db = Database::connect();
        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $createCount = 0;
        $updateCount = 0;
        $creditLimitUpdateCount = 0;
        $invalidPreview = [];
        $validPreview = [];
        $identityGuard = new SalaryImportIdentityGuard();
        $seenEmployeeIds = [];
        $seenEmails = [];

        while (($rowValues = fgetcsv($handle)) !== false) {
            $totalRows++;
            $rowAssoc = [];
            foreach ($headers as $index => $column) {
                $rowAssoc[$column] = trim((string) ($rowValues[$index] ?? ''));
            }

            $employeeId = $rowAssoc['employee_id'] ?? '';
            $name = $rowAssoc['name'] ?? '';
            $email = strtolower($rowAssoc['email'] ?? '');
            $userType = strtolower((string) ($rowAssoc['user_type'] ?? ''));
            $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($rowAssoc['employment_type'] ?? ''));
            $salaryGrade = SalaryCreditPolicy::normalizeSalaryGrade((string) ($rowAssoc['salary_grade'] ?? ''));
            $salaryStep = SalaryCreditPolicy::parseSalaryStep($rowAssoc['salary_step'] ?? null);
            $effectiveDate = trim((string) ($rowAssoc['salary_effective_date'] ?? ''));
            $scheduleCode = strtoupper(trim((string) ($rowAssoc['salary_schedule_code'] ?? '')));
            if ($scheduleCode === '') {
                $scheduleCode = SalaryScheduleService::STANDARD_SCHEDULE_CODE;
            }
            $creditPercentage = trim((string) ($rowAssoc['credit_percentage'] ?? '')) === ''
                ? SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE)
                : SalaryCreditPolicy::parseCreditPercentage($rowAssoc['credit_percentage']);
            $resolvedRate = $salaryStep === null ? null : (new SalaryScheduleService())->resolveRateByCode($db, $scheduleCode, $salaryGrade, $salaryStep);
            $monthlySalary = (float) ($resolvedRate['monthly_salary'] ?? 0);
            $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage ?? 0);
            $creditLimit = SalaryCreditPolicy::creditLimit($monthlySalary, $creditRate);

            $errors = count($rowValues) === count($headers)
                ? [] : ['row must contain exactly one value for each CSV column'];
            if ($name === '') {
                $errors[] = 'name is required';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'valid email is required';
            }
            if (!in_array($userType, ['faculty', 'staff'], true)) {
                $errors[] = 'user_type must be faculty or staff';
            }
            if (!in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)) {
                $errors[] = 'employment_type must be plantilla, cos, or part_time';
            }
            if ($resolvedRate === null) {
                $errors[] = 'salary schedule, grade, and step combination is invalid';
            }
            if ($salaryStep === null) {
                $errors[] = 'salary_step must be an integer from 1 to 8';
            }
            if (!SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)) {
                $errors[] = 'salary_effective_date must be YYYY-MM-DD';
            }
            if (SalaryCreditPolicy::isFutureEffectiveDate($effectiveDate)) {
                $errors[] = 'future-effective salary profiles cannot be imported until scheduling is available';
            }
            if ($resolvedRate !== null && ($effectiveDate < $resolvedRate['effective_from'] || ($resolvedRate['effective_to'] !== null && $effectiveDate > $resolvedRate['effective_to']))) {
                $errors[] = 'salary_effective_date is outside the selected schedule';
            }
            if ($creditPercentage === null) {
                $errors[] = 'credit_percentage must be a number from 0 to 100 with at most two decimals';
            }
            $identity = ['errors' => [], 'existing' => null];
            if ($errors === []) {
                $identity = $identityGuard->inspect($db, $employeeId, $email, $seenEmployeeIds, $seenEmails);
                array_push($errors, ...$identity['errors']);
            }

            if ($errors !== []) {
                $invalidRows++;
                if (count($invalidPreview) < 15) {
                    $invalidPreview[] = [
                        'line' => $totalRows + 1,
                        'employee_id' => $employeeId,
                        'name' => $name,
                        'email' => $email,
                        'error' => implode('; ', $errors),
                    ];
                }
                continue;
            }

            $identityGuard->claim($employeeId, $email, $seenEmployeeIds, $seenEmails);
            $existingUser = $identity['existing'];

            $validRows++;
            $action = $existingUser ? 'update' : 'create';
            if ($existingUser) {
                $updateCount++;
            } else {
                $createCount++;
            }
            $creditLimitUpdateCount++;

            if (count($validPreview) < 20) {
                $validPreview[] = [
                    'line' => $totalRows + 1,
                    'employee_id' => $employeeId,
                    'name' => $name,
                    'email' => $email,
                    'user_type' => $userType,
                    'employment_type' => $employmentType,
                    'salary_schedule_code' => $scheduleCode,
                    'salary_grade' => $resolvedRate['salary_grade_label'],
                    'salary_step' => $salaryStep,
                    'salary_effective_date' => $effectiveDate,
                    'monthly_salary' => $monthlySalary,
                    'credit_limit' => $creditLimit,
                    'credit_percentage' => $creditPercentage,
                    'action' => $action,
                ];
            }
        }

        fclose($handle);

        return $this->response->setJSON([
            'status' => 'success',
            'filename' => $file->getClientName(),
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'create_count' => $createCount,
            'update_count' => $updateCount,
            'credit_limit_update_count' => $creditLimitUpdateCount,
            'valid_preview' => $validPreview,
            'invalid_preview' => $invalidPreview,
        ]);
    }

    /** @param list<string> $headers */
    private function salaryCsvHeaderError(array $headers): ?string
    {
        $required = ['employee_id', 'name', 'email', 'user_type', 'employment_type', 'salary_grade', 'salary_step', 'salary_effective_date'];
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                return "Missing required column: {$field}";
            }
        }
        if (count(array_unique($headers)) !== count($headers)) {
            return 'CSV column names must be unique.';
        }
        $unsupported = array_diff($headers, [...$required, 'salary_schedule_code', 'credit_percentage']);
        if ($unsupported !== []) {
            return 'Unsupported CSV columns: ' . implode(', ', $unsupported)
                . '. This import accepts salary schedule profiles only; debt and salary overrides require their separate workflows.';
        }

        return null;
    }

    public function updateFinancialProfile()
    {
        $request = str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')
            ? $this->request->getJSON(true) : $this->request->getPost();
        $result = (new AccountingFinancialProfileService())->update(
            Database::connect(),
            is_array($request) ? $request : [],
            (int) session()->get('user_id')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

}
