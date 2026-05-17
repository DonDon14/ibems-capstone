<?php

namespace App\Controllers;

use App\Models\BalanceModel;
use App\Models\SalaryImportBatchModel;
use App\Models\SalaryImportRowModel;
use App\Models\UserModel;
use App\Services\AuditService;
use App\Services\CreditService;

class HrController extends BaseController
{
    public function index()
    {
        $batches = (new SalaryImportBatchModel())->orderBy('id', 'DESC')->findAll(20);

        return view('hr/import', [
            'title' => 'CSV Import',
            'batches' => $batches,
        ]);
    }

    public function importCsv()
    {
        $file = $this->request->getFile('csv_file');
        if ($file === null || ! $file->isValid() || strtolower($file->getExtension()) !== 'csv') {
            return redirect()->back()->with('error', 'A valid CSV file is required.');
        }

        $batchModel = new SalaryImportBatchModel();
        $rowModel = new SalaryImportRowModel();
        $userModel = new UserModel();
        $balanceModel = new BalanceModel();
        $creditService = new CreditService();

        $batchId = $batchModel->insert([
            'filename' => $file->getClientName(),
            'imported_by' => current_user_id(),
            'imported_at' => date('Y-m-d H:i:s'),
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
        ], true);

        $handle = fopen($file->getTempName(), 'r');
        $total = 0;
        $valid = 0;
        $invalid = 0;

        if ($handle !== false) {
            $header = fgetcsv($handle);
            while (($line = fgetcsv($handle)) !== false) {
                $total++;
                $employeeId = trim((string) ($line[0] ?? ''));
                $name = trim((string) ($line[1] ?? ''));
                $email = strtolower(trim((string) ($line[2] ?? '')));
                $salaryRaw = trim((string) ($line[3] ?? ''));
                $salary = is_numeric($salaryRaw) ? (float) $salaryRaw : null;

                $error = null;
                if ($employeeId === '' || $name === '' || $email === '') {
                    $error = 'Missing required field(s).';
                } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Invalid email format.';
                }

                if ($error !== null) {
                    $invalid++;
                    $rowModel->insert([
                        'batch_id' => $batchId,
                        'employee_id' => $employeeId,
                        'name' => $name,
                        'email' => $email,
                        'monthly_salary' => $salary,
                        'status' => 'invalid',
                        'error_msg' => $error,
                    ]);
                    continue;
                }

                $user = $userModel->where('employee_id', $employeeId)->first();
                $payload = [
                    'employee_id' => $employeeId,
                    'name' => $name,
                    'email' => $email,
                    'base_salary' => $salary,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ($user === null) {
                    $payload['password_hash'] = password_hash('password1234', PASSWORD_DEFAULT);
                    $payload['role'] = 'USER';
                    $payload['user_type'] = 'staff';
                    $payload['qr_token'] = 'QR-' . strtoupper($employeeId);
                    $payload['is_active'] = 1;
                    $payload['created_at'] = date('Y-m-d H:i:s');
                    $userId = $userModel->insert($payload, true);
                } else {
                    $userModel->update($user['id'], $payload);
                    $userId = $user['id'];
                }

                $creditLimit = $creditService->computeLimit($salary);
                $existingBalance = $balanceModel->find($userId);
                if ($existingBalance === null) {
                    $balanceModel->insert([
                        'user_id' => $userId,
                        'credit_limit' => $creditLimit,
                        'current_debt' => 0,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $balanceModel->update($userId, [
                        'credit_limit' => $creditLimit,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                $valid++;
                $rowModel->insert([
                    'batch_id' => $batchId,
                    'employee_id' => $employeeId,
                    'name' => $name,
                    'email' => $email,
                    'monthly_salary' => $salary,
                    'status' => 'valid',
                    'error_msg' => null,
                ]);
            }
            fclose($handle);
        }

        $batchModel->update($batchId, [
            'total_rows' => $total,
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
        ]);

        (new AuditService())->log(current_user_id(), 'IMPORT', 'salary_import_batches', (string) $batchId, [
            'total' => $total,
            'valid' => $valid,
            'invalid' => $invalid,
            'header' => $header ?? [],
        ]);

        return redirect()->to('/hr/import-csv/' . $batchId . '/result')->with('success', 'CSV import completed.');
    }

    public function result(int $batchId)
    {
        $batch = (new SalaryImportBatchModel())->find($batchId);
        $rows = (new SalaryImportRowModel())->where('batch_id', $batchId)->findAll();

        return view('hr/result', [
            'title' => 'Import Result',
            'batch' => $batch,
            'rows' => $rows,
        ]);
    }
}
