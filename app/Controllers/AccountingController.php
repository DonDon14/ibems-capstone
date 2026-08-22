<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\SalaryImportBatchModel;
use App\Models\SalaryImportRowModel;
use App\Models\UserModel;
use App\Models\DebtCashbookEntryModel;
use App\Services\DeductionBatchService;
use App\Services\DeductionPeriodService;
use App\Services\DebtInvestigationService;
use App\Services\SalaryCreditPolicy;
use App\Services\SalaryScheduleService;
use CodeIgniter\Controller;
use Config\Database;

class AccountingController extends Controller
{
    public function deductions()
    {
        return view('accounting/debts', ['deductionsPage' => true]);
    }

    public function exportDeductionBatch(int $batchId)
    {
        $db = Database::connect();
        $batch = $db->table('deduction_batches db')
            ->select('db.id, db.status, dp.period_code')
            ->join('deduction_periods dp', 'dp.id = db.period_id', 'inner')
            ->where('db.id', $batchId)->get()->getRowArray();
        if (!$batch) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Deduction batch not found.']);
        }
        $itemFields = array_flip($db->getFieldNames('deduction_batch_items'));
        $select = 'dbi.id, dbi.debt_snapshot, dbi.requested_amount, u.employee_id, u.name, u.email';
        if (isset($itemFields['deduction_choice'])) $select .= ', dbi.deduction_choice';
        if (isset($itemFields['preparation_reason'])) $select .= ', dbi.preparation_reason';
        $items = $db->table('deduction_batch_items dbi')
            ->select($select)
            ->join('users u', 'u.id = dbi.user_id', 'inner')
            ->where('dbi.batch_id', $batchId)->orderBy('u.name', 'ASC')->get()->getResultArray();

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['batch_item_id', 'period_code', 'employee_id', 'employee_name', 'email', 'debt_at_cutoff', 'requested_deduction', 'deduction_choice', 'actual_deduction', 'payroll_reference', 'result_code', 'result_notes']);
        foreach ($items as $item) {
            fputcsv($handle, [$item['id'], $batch['period_code'], $item['employee_id'], $item['name'], $item['email'], $item['debt_snapshot'], $item['requested_amount'], $item['deduction_choice'] ?? 'partial', '', '', '', $item['preparation_reason'] ?? '']);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . strtolower((string) $batch['period_code']) . '-deduction-advice.csv"')
            ->setBody("\xEF\xBB\xBF" . $csv);
    }

    public function importDeductionResults(int $batchId)
    {
        $file = $this->request->getFile('csv_file');
        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Select a valid payroll results CSV.']);
        }
        $handle = fopen($file->getTempName(), 'r');
        $headers = array_map(static fn($value) => strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        $required = ['batch_item_id', 'actual_deduction', 'payroll_reference', 'result_code'];
        foreach ($required as $header) {
            if (!in_array($header, $headers, true)) {
                fclose($handle);
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Missing required CSV header: ' . $header]);
            }
        }
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, static fn($value) => trim((string) $value) !== '')) === 0) continue;
            $row = array_combine($headers, array_pad($values, count($headers), ''));
            $rows[] = $row;
        }
        fclose($handle);
        if ($rows === []) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'The payroll results CSV has no data rows.']);
        }

        $db = Database::connect();
        $items = $db->table('deduction_batch_items')->where('batch_id', $batchId)->get()->getResultArray();
        $itemsById = array_column($items, null, 'id');
        foreach ($rows as $index => $row) {
            $item = $itemsById[(int) $row['batch_item_id']] ?? null;
            $amount = round((float) $row['actual_deduction'], 2);
            if (!$item || $amount < 0 || $amount > (float) $item['requested_amount'] || trim((string) $row['payroll_reference']) === '') {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid payroll result on CSV row ' . ($index + 2) . '.']);
            }
        }
        $service = new DeductionBatchService();
        $db->transBegin();
        foreach ($rows as $row) {
            $result = $service->confirmResult((int) $row['batch_item_id'], [
                'confirmed_amount' => (float) $row['actual_deduction'],
                'result_reference' => trim((string) $row['payroll_reference']),
                'reason_code' => trim((string) $row['result_code']),
                'result_notes' => trim((string) ($row['result_notes'] ?? '')),
            ], (int) session()->get('user_id'));
            if (($result['status'] ?? 'error') !== 'success') {
                $db->transRollback();
                return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
            }
        }
        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Payroll results import was rolled back.']);
        }
        $db->transCommit();
        return $this->response->setJSON(['status' => 'success', 'processed_rows' => count($rows)]);
    }

    public function debtInvestigationsData()
    {
        $db = Database::connect();
        $userId = max(0, (int) ($this->request->getGet('user_id') ?? 0));
        $investigations = $db->table('debt_investigations di')
            ->select('di.*, u.employee_id, u.name, t.client_txn_id, opener.name AS opened_by_name, recommender.name AS recommended_by_name, approver.name AS approved_by_name')
            ->join('users u', 'u.id = di.user_id', 'inner')
            ->join('transactions t', 't.id = di.transaction_id', 'left')
            ->join('users opener', 'opener.id = di.opened_by', 'left')
            ->join('users recommender', 'recommender.id = di.recommended_by', 'left')
            ->join('users approver', 'approver.id = di.approved_by', 'left')
            ->orderBy('di.id', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();
        $transactions = [];
        if ($userId > 0) {
            $transactions = $db->table('transactions')
                ->select('id, client_txn_id, amount, store_id, created_at')
                ->where('user_id', $userId)
                ->where('payment_method', 'debt')
                ->orderBy('id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();
        }

        return $this->response->setJSON([
            'status' => 'success',
            'investigations' => $investigations,
            'transactions' => $transactions,
        ]);
    }

    public function openDebtInvestigation()
    {
        $result = (new DebtInvestigationService())->open(
            $this->request->getJSON(true) ?? $this->request->getPost(),
            (int) session()->get('user_id')
        );
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    public function recommendDebtInvestigation(int $investigationId)
    {
        $result = (new DebtInvestigationService())->recommend(
            $investigationId,
            $this->request->getJSON(true) ?? $this->request->getPost(),
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

    public function deductionWorkflowData()
    {
        $db = Database::connect();
        $batchId = max(0, (int) ($this->request->getGet('batch_id') ?? 0));
        $periodId = max(0, (int) ($this->request->getGet('period_id') ?? 0));
        $periods = $db->table('deduction_periods dp')
            ->select('dp.*, db.id AS batch_id, db.status AS batch_status, db.total_accounts, db.total_requested, db.total_confirmed, db.total_carryover, db.created_by AS batch_created_by')
            ->join('deduction_batches db', 'db.period_id = dp.id', 'left')
            ->orderBy('dp.date_start', 'DESC')
            ->orderBy('dp.id', 'DESC')
            ->get()
            ->getResultArray();

        $items = [];
        if ($batchId > 0) {
            $items = $db->table('deduction_batch_items dbi')
                ->select('dbi.*, u.employee_id, u.name, u.email, u.user_type, b.current_debt')
                ->join('users u', 'u.id = dbi.user_id', 'inner')
                ->join('balances b', 'b.user_id = dbi.user_id', 'left')
                ->where('dbi.batch_id', $batchId)
                ->orderBy('u.name', 'ASC')
                ->get()
                ->getResultArray();
        }

        $register = [];
        if ($periodId > 0) {
            $period = $db->table('deduction_periods')->where('id', $periodId)->get()->getRowArray();
            if ($period) {
                $register = (new \App\Services\DebtPeriodRegisterService())->build($period);
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'periods' => $periods,
            'items' => $items,
            'register' => $register,
        ]);
    }

    public function createDeductionPeriod()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $result = (new DeductionPeriodService())->create($request, (int) session()->get('user_id'));

        return $this->response
            ->setStatusCode((int) ($result['code'] ?? 400))
            ->setJSON($result);
    }

    public function prepareDeductionBatch()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $result = (new DeductionBatchService())->prepare(
            (int) ($request['period_id'] ?? 0),
            is_array($request['requests'] ?? null) ? $request['requests'] : [],
            (int) session()->get('user_id'),
            isset($request['notes']) ? (string) $request['notes'] : null
        );

        return $this->response
            ->setStatusCode((int) ($result['code'] ?? 400))
            ->setJSON($result);
    }

    public function confirmDeductionResult(int $itemId)
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $result = (new DeductionBatchService())->confirmResult(
            $itemId,
            $request,
            (int) session()->get('user_id')
        );

        return $this->response
            ->setStatusCode((int) ($result['code'] ?? 400))
            ->setJSON($result);
    }

    public function applyDeductionBatch(int $batchId)
    {
        $result = (new DeductionBatchService())->applyPrepared($batchId, (int) session()->get('user_id'));
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    public function reconcileDeductionBatch(int $batchId)
    {
        $result = (new DeductionBatchService())->reconcile($batchId, (int) session()->get('user_id'));
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    public function finalizeDeductionBatch(int $batchId)
    {
        $result = (new DeductionBatchService())->finalize($batchId, (int) session()->get('user_id'));
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setJSON($result);
    }

    private function addDebtCashbookEntry(
        int $userId,
        string $entryType,
        float $amount,
        float $debtBefore,
        float $debtAfter,
        float $creditLimit,
        ?int $actorId = null,
        ?string $remarks = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = []
    ): void {
        $cashbookModel = new DebtCashbookEntryModel();
        $cashbookModel->insert([
            'user_id' => $userId,
            'entry_type' => $entryType,
            'direction' => $debtAfter >= $debtBefore ? 'debit' : 'credit',
            'amount' => abs($amount),
            'debt_before' => $debtBefore,
            'debt_after' => $debtAfter,
            'credit_limit_snapshot' => $creditLimit,
            'available_credit_snapshot' => max(0, $creditLimit - $debtAfter),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actorId,
            'remarks' => $remarks,
            'meta_json' => $meta !== [] ? json_encode($meta) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function dashboard()
    {
        return view('accounting/dashboard');
    }

    public function dashboardData()
    {
        $db = Database::connect();

        $accountsRow = $db->table('balances b')
            ->select('COUNT(*) AS total_accounts, SUM(b.current_debt) AS total_debt, SUM(CASE WHEN b.current_debt > 0 THEN 1 ELSE 0 END) AS with_debt, SUM(CASE WHEN b.current_debt > b.credit_limit AND b.current_debt > 0 THEN 1 ELSE 0 END) AS over_limit_count')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->get()
            ->getRowArray() ?? [];

        [$todayStart, $todayEnd] = $this->businessDayStorageBounds();
        $todayDeductions = $this->deductionSummaryForStorageRange($db, $todayStart, $todayEnd);
        $todayDeductionCount = $todayDeductions['count'];
        $todayDeductionAmount = $todayDeductions['amount'];

        $lastPeriod = $db->table('deduction_periods')
            ->select('period_code, label, status, updated_at')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $topDebtAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $overLimitAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt > b.credit_limit', null, false)
            ->where('b.current_debt >', 0)
            ->orderBy('(b.current_debt - b.credit_limit)', 'DESC', false)
            ->limit(5)
            ->get()
            ->getResultArray();

        $staleCutoff = date('Y-m-d H:i:s', strtotime('-30 day'));
        $staleDebtAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.updated_at, MAX(dce.created_at) AS last_cashbook_at')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->join('debt_cashbook_entries dce', 'dce.user_id = u.id', 'left')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->groupBy('u.id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.updated_at')
            ->having('(MAX(dce.created_at) IS NULL OR MAX(dce.created_at) < ' . $db->escape($staleCutoff) . ')', null, false)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $failedImports = $db->table('salary_import_batches sib')
            ->select('sib.id, sib.filename, sib.imported_at, sib.total_rows, sib.valid_rows, sib.invalid_rows, u.name AS imported_by_name')
            ->join('users u', 'u.id = sib.imported_by', 'left')
            ->where('sib.invalid_rows >', 0)
            ->orderBy('sib.id', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $trendByDate = [];
        $trendSeed = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} day"));
            $trendByDate[$date] = ['date' => $date, 'amount' => 0.0, 'count' => 0];
            $trendSeed[] = $date;
        }

        $trendRows = $db->table('debt_cashbook_entries')
            ->select('created_at, amount')
            ->where('direction', 'credit')
            ->whereIn('entry_type', ['confirmed_salary_deduction', 'salary_deduction', 'manual_deduction', 'full_deduction'])
            ->where('created_at >=', date('Y-m-d 00:00:00', strtotime('-6 day')))
            ->where('created_at <=', date('Y-m-d 23:59:59'))
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($trendRows as $row) {
            $date = substr((string) ($row['created_at'] ?? ''), 0, 10);
            if (!isset($trendByDate[$date])) {
                continue;
            }

            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $trendByDate[$date]['amount'] += $amount;
            $trendByDate[$date]['count'] += 1;
        }

        $trend = [];
        foreach ($trendSeed as $date) {
            $trend[] = $trendByDate[$date];
        }

        $recentActivities = $db->table('audit_logs al')
            ->select('al.id, al.action, al.created_at, al.payload_json, actor.name AS actor_name, target.name AS target_name')
            ->join('users actor', 'actor.id = al.actor_id', 'left')
            ->join('users target', "target.id = al.entity_id AND al.entity = 'balances'", 'left', false)
            ->whereIn('al.action', [
                'ACCOUNTING_IMPORT_HR_CSV',
                'ACCOUNTING_DEDUCT_DEBT',
                'ACCOUNTING_DEDUCT_FULL_DEBT',
                'ACCOUNTING_UPDATE_CREDIT_LIMIT',
                'ACCOUNTING_RUN_SETTLEMENT',
                'ACCOUNTING_SETTLEMENT_DEDUCT',
                'ACCOUNTING_PREPARE_DEDUCTION_BATCH',
                'ACCOUNTING_SUBMIT_DEDUCTION_BATCH',
                'ACCOUNTING_CONFIRM_DEDUCTION_RESULT',
                'ACCOUNTING_RECONCILE_DEDUCTION_BATCH',
                'ACCOUNTING_FINALIZE_DEDUCTION_BATCH',
                'OPEN_DEBT_INVESTIGATION',
                'RECOMMEND_DEBT_INVESTIGATION',
                'APPROVE_AND_POST_DEBT_REVERSAL',
            ])
            ->orderBy('al.id', 'DESC')
            ->limit(10)
            ->get()
            ->getResultArray();

        $activityFeed = array_map(static function (array $row): array {
            $action = (string) ($row['action'] ?? '');
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $label = $action;
            if ($action === 'ACCOUNTING_IMPORT_HR_CSV') {
                $label = 'Imported HR CSV';
            } elseif ($action === 'ACCOUNTING_DEDUCT_DEBT') {
                $label = 'Manual debt deduction';
            } elseif ($action === 'ACCOUNTING_DEDUCT_FULL_DEBT') {
                $label = 'Full debt deduction';
            } elseif ($action === 'ACCOUNTING_UPDATE_CREDIT_LIMIT') {
                $label = 'Updated credit limit';
            } elseif ($action === 'ACCOUNTING_RUN_SETTLEMENT') {
                $label = 'Ran monthly settlement';
            } elseif ($action === 'ACCOUNTING_SETTLEMENT_DEDUCT') {
                $label = 'Settlement deduction entry';
            } elseif ($action === 'ACCOUNTING_PREPARE_DEDUCTION_BATCH') {
                $label = 'Prepared deduction batch';
            } elseif ($action === 'ACCOUNTING_SUBMIT_DEDUCTION_BATCH') {
                $label = 'Submitted deduction batch';
            } elseif ($action === 'ACCOUNTING_CONFIRM_DEDUCTION_RESULT') {
                $label = 'Confirmed payroll result';
            } elseif ($action === 'ACCOUNTING_RECONCILE_DEDUCTION_BATCH') {
                $label = 'Reconciled deduction batch';
            } elseif ($action === 'ACCOUNTING_FINALIZE_DEDUCTION_BATCH') {
                $label = 'Finalized deduction period';
            } elseif ($action === 'OPEN_DEBT_INVESTIGATION') {
                $label = 'Opened debt investigation';
            } elseif ($action === 'RECOMMEND_DEBT_INVESTIGATION') {
                $label = 'Recommended debt correction';
            } elseif ($action === 'APPROVE_AND_POST_DEBT_REVERSAL') {
                $label = 'Approved debt correction';
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'label' => $label,
                'actor_name' => $row['actor_name'] ?: 'Unknown',
                'target_name' => $row['target_name'] ?: null,
                'amount' => (float) ($payload['deducted_amount'] ?? $payload['confirmed_amount'] ?? $payload['amount'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $recentActivities);

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'total_accounts' => (int) ($accountsRow['total_accounts'] ?? 0),
                'with_debt' => (int) ($accountsRow['with_debt'] ?? 0),
                'total_debt' => (float) ($accountsRow['total_debt'] ?? 0),
                'today_deduction_count' => $todayDeductionCount,
                'today_deduction_amount' => $todayDeductionAmount,
                'over_limit_count' => (int) ($accountsRow['over_limit_count'] ?? 0),
                'last_deduction_period' => $lastPeriod['period_code'] ?? null,
                'last_deduction_label' => $lastPeriod['label'] ?? null,
                'last_deduction_status' => $lastPeriod['status'] ?? null,
                'last_deduction_at' => $lastPeriod['updated_at'] ?? null,
            ],
            'top_debt_accounts' => array_map(static function (array $row): array {
                return [
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'current_debt' => (float) ($row['current_debt'] ?? 0),
                    'credit_limit' => (float) ($row['credit_limit'] ?? 0),
                ];
            }, $topDebtAccounts),
            'alerts' => [
                'over_limit' => array_map(static function (array $row): array {
                    return [
                        'user_id' => (int) ($row['user_id'] ?? 0),
                        'employee_id' => $row['employee_id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'profile_image_url' => $row['profile_image_url'] ?? null,
                        'current_debt' => (float) ($row['current_debt'] ?? 0),
                        'credit_limit' => (float) ($row['credit_limit'] ?? 0),
                        'over_amount' => max(0, (float) ($row['current_debt'] ?? 0) - (float) ($row['credit_limit'] ?? 0)),
                    ];
                }, $overLimitAccounts),
                'stale_debts' => array_map(static function (array $row): array {
                    return [
                        'user_id' => (int) ($row['user_id'] ?? 0),
                        'employee_id' => $row['employee_id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'profile_image_url' => $row['profile_image_url'] ?? null,
                        'current_debt' => (float) ($row['current_debt'] ?? 0),
                        'last_cashbook_at' => $row['last_cashbook_at'] ?? null,
                    ];
                }, $staleDebtAccounts),
                'failed_imports' => array_map(static function (array $row): array {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'filename' => $row['filename'],
                        'imported_at' => $row['imported_at'],
                        'imported_by_name' => $row['imported_by_name'] ?: 'Unknown',
                        'total_rows' => (int) ($row['total_rows'] ?? 0),
                        'valid_rows' => (int) ($row['valid_rows'] ?? 0),
                        'invalid_rows' => (int) ($row['invalid_rows'] ?? 0),
                    ];
                }, $failedImports),
            ],
            'trend' => $trend,
            'activity' => $activityFeed,
        ]);
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
        $salaryProfileSelect = $this->salaryProfileSelect($db, 'u');
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances') ? 'b.credit_rate' : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';
        $profileImageSelect = $db->fieldExists('profile_image_url', 'users') ? 'u.profile_image_url' : 'NULL AS profile_image_url';

        $query = $db->table('users u')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, ' . $profileImageSelect . ', u.user_type, u.is_active, u.base_salary, ' . $salaryProfileSelect . ', b.user_id AS balance_user_id, b.credit_limit, b.current_debt, b.updated_at, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.is_active', true)
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

        $data = array_map(function (array $row): array {
            $row['financial_profile_configured'] = $row['balance_user_id'] !== null
                && trim((string) ($row['employment_type'] ?? '')) !== ''
                && trim((string) ($row['salary_grade'] ?? '')) !== ''
                && trim((string) ($row['salary_effective_date'] ?? '')) !== ''
                && (int) ($row['salary_schedule_id'] ?? 0) > 0;
            unset($row['balance_user_id']);
            $creditLimit = (float) ($row['credit_limit'] ?? 0);
            $currentDebt = (float) ($row['current_debt'] ?? 0);
            $row['credit_limit'] = $creditLimit;
            $row['credit_percentage'] = SalaryCreditPolicy::percentageFromRate((float) ($row['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE));
            $row['current_debt'] = $currentDebt;
            $row['available_credit'] = max(0, $creditLimit - $currentDebt);
            $row += $this->buildDebtStatus($creditLimit, $currentDebt);
            return $row;
        }, $rows);
        $advancePayments = $this->getAccountingCashbookRows(['store_repayment'], 100);
        $operatorAccountabilities = $this->getAccountingCashbookRows(['operator_shortage'], 100);
        $employeeDebtTotal = array_reduce($data, static fn(float $sum, array $row): float => $sum + (float) ($row['current_debt'] ?? 0), 0.0);
        $advanceTotal = array_reduce($advancePayments, static fn(float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0), 0.0);
        $accountabilityTotal = array_reduce($operatorAccountabilities, static fn(float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0), 0.0);

        return $this->response->setJSON([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
            'summary' => [
                'employee_account_count' => count($data),
                'configured_account_count' => count(array_filter($data, static fn(array $row): bool => (bool) ($row['financial_profile_configured'] ?? false))),
                'needs_setup_count' => count(array_filter($data, static fn(array $row): bool => !(bool) ($row['financial_profile_configured'] ?? false))),
                'employee_debt_accounts' => count(array_filter($data, static fn(array $row): bool => (float) ($row['current_debt'] ?? 0) > 0)),
                'employee_debt_total' => $employeeDebtTotal,
                'advance_payment_count' => count($advancePayments),
                'advance_payment_total' => $advanceTotal,
                'operator_accountability_count' => count($operatorAccountabilities),
                'operator_accountability_total' => $accountabilityTotal,
            ],
            'advance_payments' => $advancePayments,
            'operator_accountabilities' => $operatorAccountabilities,
        ]);
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
        $salaryProfileSelect = $this->salaryProfileSelect($db, 'u');
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances') ? 'b.credit_rate' : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';
        $profileImageSelect = $db->fieldExists('profile_image_url', 'users') ? 'u.profile_image_url' : 'NULL AS profile_image_url';
        $row = $db->table('users u')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, ' . $profileImageSelect . ', u.user_type, u.base_salary, ' . $salaryProfileSelect . ', b.user_id AS balance_user_id, b.credit_limit, b.current_debt, b.updated_at, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.is_active', true)
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Record not found.',
            ]);
        }

        $row['financial_profile_configured'] = $row['balance_user_id'] !== null
            && trim((string) ($row['employment_type'] ?? '')) !== ''
            && trim((string) ($row['salary_grade'] ?? '')) !== ''
            && trim((string) ($row['salary_effective_date'] ?? '')) !== ''
            && (int) ($row['salary_schedule_id'] ?? 0) > 0;
        unset($row['balance_user_id']);
        $creditLimit = (float) ($row['credit_limit'] ?? 0);
        $currentDebt = (float) ($row['current_debt'] ?? 0);
        $row['credit_limit'] = $creditLimit;
        $row['credit_percentage'] = SalaryCreditPolicy::percentageFromRate((float) ($row['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE));
        $row['current_debt'] = $currentDebt;
        $row['available_credit'] = max(0, $creditLimit - $currentDebt);
        $row += $this->buildDebtStatus($creditLimit, $currentDebt);

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
                'ACCOUNTING_UPDATE_FINANCIAL_PROFILE',
                'STORE_DEBT_REPAYMENT',
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
        [$start, $end, $businessDate] = $this->businessDayStorageBounds();

        $summary = $this->deductionSummaryForStorageRange($db, $start, $end);

        return $this->response->setJSON([
            'status' => 'success',
            'date' => $businessDate,
            'deduction_count' => $summary['count'],
            'deducted_amount' => $summary['amount'],
        ]);
    }

    /** @return array{count:int,amount:float} */
    private function deductionSummaryForStorageRange($db, string $start, string $end): array
    {
        // Confirmed batch items are authoritative for the new payroll workflow.
        // Exclude their mirrored cashbook rows so the same result is not counted twice.
        $legacy = $db->table('debt_cashbook_entries')
            ->select('COUNT(*) AS entry_count, COALESCE(SUM(amount), 0) AS total_amount')
            ->where('direction', 'credit')
            ->whereIn('entry_type', ['salary_deduction', 'manual_deduction', 'full_deduction'])
            ->where('created_at >=', $start)
            ->where('created_at <=', $end)
            ->get()
            ->getRowArray() ?? [];

        $batch = $db->table('deduction_batch_items')
            ->select('COUNT(*) AS entry_count, COALESCE(SUM(confirmed_amount), 0) AS total_amount')
            ->where('confirmed_amount >', 0)
            ->where('confirmed_at >=', $start)
            ->where('confirmed_at <=', $end)
            ->get()
            ->getRowArray() ?? [];

        return [
            'count' => (int) ($legacy['entry_count'] ?? 0) + (int) ($batch['entry_count'] ?? 0),
            'amount' => (float) ($legacy['total_amount'] ?? 0) + (float) ($batch['total_amount'] ?? 0),
        ];
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

        $db = Database::connect();
        $rows = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, u.base_salary, b.current_debt')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->get()
            ->getResultArray();

        $accounts = [];
        $totalDebtBefore = 0.0;
        $totalDeductible = 0.0;
        $processableCount = 0;
        $skippedCount = 0;

        foreach ($rows as $row) {
            $currentDebt = (float) ($row['current_debt'] ?? 0);
            $monthlySalary = max(0, (float) ($row['base_salary'] ?? 0));
            $deductible = min($currentDebt, $monthlySalary);
            $newDebt = max(0, $currentDebt - $deductible);
            $isProcessable = $deductible > 0;

            $totalDebtBefore += $currentDebt;
            $totalDeductible += $deductible;
            if ($isProcessable) {
                $processableCount++;
            } else {
                $skippedCount++;
            }

            $accounts[] = [
                'user_id' => (int) $row['user_id'],
                'employee_id' => $row['employee_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'category' => $row['user_type'],
                'monthly_salary' => $monthlySalary,
                'current_debt' => $currentDebt,
                'deductible_amount' => $deductible,
                'new_debt' => $newDebt,
                'processable' => $isProcessable,
            ];
        }

        return $this->response->setJSON([
            'status' => 'success',
            'run_month' => $runMonth,
            'summary' => [
                'candidate_count' => count($accounts),
                'processable_count' => $processableCount,
                'skipped_count' => $skippedCount,
                'total_debt_before' => $totalDebtBefore,
                'total_deductible' => $totalDeductible,
                'total_debt_after' => max(0, $totalDebtBefore - $totalDeductible),
            ],
            'accounts' => $accounts,
        ]);
    }

    public function applySettlementRun()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $runMonth = trim((string) ($request['run_month'] ?? date('Y-m')));
        $notes = trim((string) ($request['notes'] ?? ''));
        $selectedUserIds = [];
        if (array_key_exists('selected_user_ids', $request)) {
            $rawSelected = is_array($request['selected_user_ids']) ? $request['selected_user_ids'] : [];
            $selectedUserIds = array_values(array_unique(array_filter(array_map('intval', $rawSelected), static fn (int $id): bool => $id > 0)));
            if ($selectedUserIds === []) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Select at least one employee before confirming deduction.',
                ]);
            }
        }
        $actorId = (int) session()->get('user_id');

        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $runMonth)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'run_month must be in YYYY-MM format.',
            ]);
        }

        $db = Database::connect();

        $existingRun = $db->table('settlement_runs')
            ->select('id')
            ->where('run_month', $runMonth)
            ->get()
            ->getRowArray();
        if ($existingRun) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => "Settlement run for {$runMonth} already exists.",
            ]);
        }

        $settlementBuilder = $db->table('balances b')
            ->select('u.id AS user_id, u.base_salary, b.current_debt')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0);

        if ($selectedUserIds !== []) {
            $settlementBuilder->whereIn('u.id', $selectedUserIds);
        }

        $rows = $settlementBuilder
            ->get()
            ->getResultArray();

        $processRows = [];
        $totalDebtBefore = 0.0;
        $totalDeducted = 0.0;
        foreach ($rows as $row) {
            $currentDebt = (float) ($row['current_debt'] ?? 0);
            $monthlySalary = max(0, (float) ($row['base_salary'] ?? 0));
            $deductible = min($currentDebt, $monthlySalary);
            if ($deductible <= 0) {
                continue;
            }

            $processRows[] = [
                'user_id' => (int) $row['user_id'],
                'previous_debt' => $currentDebt,
                'deducted_amount' => $deductible,
                'new_debt' => max(0, $currentDebt - $deductible),
                'monthly_salary' => $monthlySalary,
            ];
            $totalDebtBefore += $currentDebt;
            $totalDeducted += $deductible;
        }

        if ($processRows === []) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
            'message' => 'No processable debt accounts found for settlement.',
            ]);
        }

        if ($selectedUserIds !== [] && count($processRows) !== count($selectedUserIds)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'One or more selected employees no longer have a processable debt. Refresh the preview and try again.',
            ]);
        }

        $auditLogModel = new AuditLogModel();
        $balanceModel = new BalanceModel();

        $db->transStart();

        $runInserted = $db->table('settlement_runs')->insert([
            'run_month' => $runMonth,
            'run_by' => $actorId,
            'run_at' => date('Y-m-d H:i:s'),
            'total_accounts' => count($processRows),
            'total_debt_before' => $totalDebtBefore,
            'notes' => json_encode([
                'note' => $notes,
                'total_deducted' => $totalDeducted,
                'total_debt_after' => max(0, $totalDebtBefore - $totalDeducted),
            ]),
        ]);

        if (!$runInserted) {
            $error = $db->error();
            $db->transRollback();

            if ((int) ($error['code'] ?? 0) === 1062) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => "Settlement run for {$runMonth} already exists.",
                ]);
            }

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to create settlement run.',
            ]);
        }

        $runId = (int) $db->insertID();

        foreach ($processRows as $entry) {
            $balanceModel->update($entry['user_id'], [
                'current_debt' => $entry['new_debt'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $updatedBalance = $balanceModel->getBalanceByUserId((int) $entry['user_id']);
            $creditLimit = (float) ($updatedBalance['credit_limit'] ?? 0);

            $auditLogModel->insert([
                'actor_id' => $actorId,
                'action' => 'ACCOUNTING_SETTLEMENT_DEDUCT',
                'entity' => 'balances',
                'entity_id' => $entry['user_id'],
                'payload_json' => json_encode([
                    'run_id' => $runId,
                    'run_month' => $runMonth,
                    'previous_debt' => $entry['previous_debt'],
                    'deducted_amount' => $entry['deducted_amount'],
                    'new_debt' => $entry['new_debt'],
                    'monthly_salary' => $entry['monthly_salary'],
                ]),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->addDebtCashbookEntry(
                (int) $entry['user_id'],
                'salary_deduction',
                (float) $entry['deducted_amount'],
                (float) $entry['previous_debt'],
                (float) $entry['new_debt'],
                $creditLimit,
                $actorId > 0 ? $actorId : null,
                'Salary settlement deduction',
                'settlement_run',
                $runId,
                [
                    'run_month' => $runMonth,
                    'monthly_salary' => (float) $entry['monthly_salary'],
                ]
            );
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ACCOUNTING_RUN_SETTLEMENT',
            'entity' => 'settlement_runs',
            'entity_id' => $runId,
            'payload_json' => json_encode([
                'run_month' => $runMonth,
                'total_accounts' => count($processRows),
                'total_debt_before' => $totalDebtBefore,
                'total_deducted' => $totalDeducted,
                'total_debt_after' => max(0, $totalDebtBefore - $totalDeducted),
                'notes' => $notes,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Settlement run failed.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'run_id' => $runId,
            'run_month' => $runMonth,
            'total_accounts' => count($processRows),
            'total_debt_before' => $totalDebtBefore,
            'total_deducted' => $totalDeducted,
            'total_debt_after' => max(0, $totalDebtBefore - $totalDeducted),
        ]);
    }

    public function settlementRuns()
    {
        $limit = (int) ($this->request->getGet('limit') ?? 20);
        $limit = max(1, min(100, $limit));
        $db = Database::connect();

        $rows = $db->table('settlement_runs sr')
            ->select('sr.id, sr.run_month, sr.run_at, sr.total_accounts, sr.total_debt_before, sr.notes, u.name AS run_by_name')
            ->join('users u', 'u.id = sr.run_by', 'left')
            ->orderBy('sr.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $runs = array_map(static function (array $row): array {
            $notes = [];
            if (!empty($row['notes'])) {
                $decoded = json_decode((string) $row['notes'], true);
                if (is_array($decoded)) {
                    $notes = $decoded;
                }
            }

            return [
                'id' => (int) $row['id'],
                'run_month' => $row['run_month'],
                'run_at' => $row['run_at'],
                'run_by_name' => $row['run_by_name'] ?: 'Unknown',
                'total_accounts' => (int) ($row['total_accounts'] ?? 0),
                'total_debt_before' => (float) ($row['total_debt_before'] ?? 0),
                'notes' => $notes,
            ];
        }, $rows);

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $runs,
        ]);
    }

    public function settlementRunDetails(int $runId)
    {
        if ($runId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid run id.',
            ]);
        }

        $db = Database::connect();
        $run = $db->table('settlement_runs sr')
            ->select('sr.id, sr.run_month, sr.run_at, sr.total_accounts, sr.total_debt_before, sr.notes, u.name AS run_by_name')
            ->join('users u', 'u.id = sr.run_by', 'left')
            ->where('sr.id', $runId)
            ->get()
            ->getRowArray();

        if (!$run) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Settlement run not found.',
            ]);
        }

        $auditRows = $db->table('audit_logs al')
            ->select('al.id, al.created_at, al.entity_id, al.payload_json, u.employee_id, u.name, u.email, u.user_type')
            ->join('users u', 'u.id = al.entity_id', 'left')
            ->where('al.action', 'ACCOUNTING_SETTLEMENT_DEDUCT')
            ->like('al.payload_json', '"run_id":' . $runId)
            ->orderBy('al.id', 'ASC')
            ->get()
            ->getResultArray();

        $items = [];
        $totalDeducted = 0.0;
        $totalDebtAfter = 0.0;
        foreach ($auditRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload) || (int) ($payload['run_id'] ?? 0) !== $runId) {
                continue;
            }

            $deducted = (float) ($payload['deducted_amount'] ?? 0);
            $newDebt = (float) ($payload['new_debt'] ?? 0);
            $totalDeducted += $deducted;
            $totalDebtAfter += $newDebt;

            $items[] = [
                'user_id' => (int) ($row['entity_id'] ?? 0),
                'employee_id' => $row['employee_id'] ?? null,
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'category' => $row['user_type'] ?? null,
                'deducted_amount' => $deducted,
                'previous_debt' => (float) ($payload['previous_debt'] ?? 0),
                'new_debt' => $newDebt,
                'monthly_salary' => (float) ($payload['monthly_salary'] ?? 0),
                'created_at' => $row['created_at'],
            ];
        }

        $notes = [];
        if (!empty($run['notes'])) {
            $decoded = json_decode((string) $run['notes'], true);
            if (is_array($decoded)) {
                $notes = $decoded;
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'run' => [
                'id' => (int) $run['id'],
                'run_month' => $run['run_month'],
                'run_at' => $run['run_at'],
                'run_by_name' => $run['run_by_name'] ?: 'Unknown',
                'total_accounts' => (int) ($run['total_accounts'] ?? 0),
                'total_debt_before' => (float) ($run['total_debt_before'] ?? 0),
                'notes' => $notes,
            ],
            'summary' => [
                'processed_accounts' => count($items),
                'total_deducted' => $totalDeducted,
                'total_debt_after' => $totalDebtAfter,
            ],
            'items' => $items,
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

        $required = ['employee_id', 'name', 'email', 'user_type', 'employment_type', 'salary_grade', 'salary_step', 'salary_effective_date'];
        foreach ($required as $field) {
            if (!in_array($field, $headers, true)) {
                fclose($handle);
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => "Missing required column: {$field}",
                ]);
            }
        }

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
            $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($rowAssoc['employment_type'] ?? ''));
            $salaryGrade = SalaryCreditPolicy::normalizeSalaryGrade((string) ($rowAssoc['salary_grade'] ?? ''));
            $salaryStep = (int) ($rowAssoc['salary_step'] ?? 0);
            $effectiveDate = trim((string) ($rowAssoc['salary_effective_date'] ?? ''));
            $scheduleCode = strtoupper(trim((string) ($rowAssoc['salary_schedule_code'] ?? '')));
            if ($scheduleCode === '') {
                $scheduleCode = SalaryScheduleService::STANDARD_SCHEDULE_CODE;
            }
            $creditPercentage = trim((string) ($rowAssoc['credit_percentage'] ?? '')) === ''
                ? SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE)
                : (float) $rowAssoc['credit_percentage'];
            $resolvedRate = (new SalaryScheduleService())->resolveRateByCode($db, $scheduleCode, $salaryGrade, $salaryStep);
            $monthlySalary = (float) ($resolvedRate['monthly_salary'] ?? 0);
            $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage);
            $creditLimit = SalaryCreditPolicy::creditLimit($monthlySalary, $creditRate);

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
            if (!in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)) {
                $errors[] = 'employment_type must be plantilla, cos, or part_time';
            }
            if ($resolvedRate === null) {
                $errors[] = 'salary schedule, grade, and step combination is invalid';
            }
            if (!SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)) {
                $errors[] = 'salary_effective_date must be YYYY-MM-DD';
            }
            if ($resolvedRate !== null && ($effectiveDate < $resolvedRate['effective_from'] || ($resolvedRate['effective_to'] !== null && $effectiveDate > $resolvedRate['effective_to']))) {
                $errors[] = 'salary_effective_date is outside the selected schedule';
            }
            if (!SalaryCreditPolicy::isValidCreditPercentage($creditPercentage)) {
                $errors[] = 'credit_percentage must be from 0 to 100';
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
                    'employment_type' => $employmentType,
                    'salary_grade' => $resolvedRate['salary_grade_label'],
                    'salary_step' => $salaryStep,
                    'salary_effective_date' => $effectiveDate,
                    'salary_schedule_id' => $resolvedRate['schedule_id'],
                    'is_active' => true,
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
                $balanceModel->update((int) $userId, $updateData);
            } else {
                $balanceModel->insert([
                    'user_id' => (int) $userId,
                    'credit_limit' => $creditLimit,
                    'credit_rate' => $creditRate,
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

    /**
     * Cashbook timestamps are stored as UTC values, while IBEMS reporting days
     * follow the university's Asia/Manila business date.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function businessDayStorageBounds(): array
    {
        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        $businessNow = new \DateTimeImmutable('now', $businessZone);
        $start = $businessNow->setTime(0, 0)->setTimezone($storageZone);
        $end = $businessNow->setTime(23, 59, 59)->setTimezone($storageZone);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $businessNow->format('Y-m-d')];
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

        $required = ['employee_id', 'name', 'email', 'user_type', 'employment_type', 'salary_grade', 'salary_step', 'salary_effective_date'];
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
        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $createCount = 0;
        $updateCount = 0;
        $creditLimitUpdateCount = 0;
        $invalidPreview = [];
        $validPreview = [];

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
            $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($rowAssoc['employment_type'] ?? ''));
            $salaryGrade = SalaryCreditPolicy::normalizeSalaryGrade((string) ($rowAssoc['salary_grade'] ?? ''));
            $salaryStep = (int) ($rowAssoc['salary_step'] ?? 0);
            $effectiveDate = trim((string) ($rowAssoc['salary_effective_date'] ?? ''));
            $scheduleCode = strtoupper(trim((string) ($rowAssoc['salary_schedule_code'] ?? '')));
            if ($scheduleCode === '') {
                $scheduleCode = SalaryScheduleService::STANDARD_SCHEDULE_CODE;
            }
            $creditPercentage = trim((string) ($rowAssoc['credit_percentage'] ?? '')) === ''
                ? SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE)
                : (float) $rowAssoc['credit_percentage'];
            $resolvedRate = (new SalaryScheduleService())->resolveRateByCode($db, $scheduleCode, $salaryGrade, $salaryStep);
            $monthlySalary = (float) ($resolvedRate['monthly_salary'] ?? 0);
            $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage);
            $creditLimit = SalaryCreditPolicy::creditLimit($monthlySalary, $creditRate);

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
            if (!in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)) {
                $errors[] = 'employment_type must be plantilla, cos, or part_time';
            }
            if ($resolvedRate === null) {
                $errors[] = 'salary schedule, grade, and step combination is invalid';
            }
            if (!SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)) {
                $errors[] = 'salary_effective_date must be YYYY-MM-DD';
            }
            if ($resolvedRate !== null && ($effectiveDate < $resolvedRate['effective_from'] || ($resolvedRate['effective_to'] !== null && $effectiveDate > $resolvedRate['effective_to']))) {
                $errors[] = 'salary_effective_date is outside the selected schedule';
            }
            if (!SalaryCreditPolicy::isValidCreditPercentage($creditPercentage)) {
                $errors[] = 'credit_percentage must be from 0 to 100';
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

            $existingUser = null;
            if ($employeeId !== '') {
                $existingUser = $db->table('users')->where('employee_id', $employeeId)->get()->getRowArray();
            }
            if (!$existingUser) {
                $existingUser = $db->table('users')->where('email', $email)->get()->getRowArray();
            }

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

        $db->transBegin();
        $balance = $balanceModel->getBalanceForUpdate($userId);
        if (!$balance) {
            $db->transRollback();
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Balance record not found.',
            ]);
        }

        $currentDebt = (float) $balance['current_debt'];
        $creditLimit = (float) $balance['credit_limit'];
        if ($currentDebt <= 0) {
            $db->transRollback();
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'This account has no debt.',
            ]);
        }

        if ($amount > $currentDebt) {
            $db->transRollback();
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Deduction cannot exceed current debt.',
            ]);
        }

        $newDebt = $currentDebt - $amount;

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

        $this->addDebtCashbookEntry(
            $userId,
            'manual_deduction',
            $amount,
            $currentDebt,
            $newDebt,
            $creditLimit,
            $actorId > 0 ? $actorId : null,
            $reason,
            'audit_log',
            null,
            ['source' => 'accounting_manual_deduction']
        );

        if (!$db->transStatus()) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to apply deduction.',
            ]);
        }

        $db->transCommit();

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

        $db->transBegin();
        $balance = $balanceModel->getBalanceForUpdate($userId);
        if (!$balance) {
            $db->transRollback();
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Balance record not found.',
            ]);
        }

        $currentDebt = (float) $balance['current_debt'];
        $creditLimit = (float) ($balance['credit_limit'] ?? 0);
        if ($currentDebt <= 0) {
            $db->transRollback();
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'This account has no debt.',
            ]);
        }

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

        $this->addDebtCashbookEntry(
            $userId,
            'full_deduction',
            $currentDebt,
            $currentDebt,
            0.0,
            $creditLimit,
            $actorId > 0 ? $actorId : null,
            $reason,
            'audit_log',
            null,
            ['source' => 'accounting_full_deduction']
        );

        if (!$db->transStatus()) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to apply full deduction.',
            ]);
        }

        $db->transCommit();

        return $this->response->setJSON([
            'status' => 'success',
            'user_id' => $userId,
            'previous_debt' => $currentDebt,
            'deducted_amount' => $currentDebt,
            'new_debt' => 0,
        ]);
    }

    public function updateFinancialProfile()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $actorId = (int) session()->get('user_id');
        $userId = (int) ($request['user_id'] ?? 0);
        $scheduleId = (int) ($request['salary_schedule_id'] ?? 0);
        $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($request['employment_type'] ?? ''));
        $salaryGrade = trim((string) ($request['salary_grade'] ?? ''));
        $salaryStep = (int) ($request['salary_step'] ?? 0);
        $effectiveDate = trim((string) ($request['salary_effective_date'] ?? ''));
        $creditPercentage = array_key_exists('credit_percentage', $request)
            ? (float) $request['credit_percentage']
            : SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE);
        $reason = trim((string) ($request['reason'] ?? ''));

        if ($userId <= 0 || $scheduleId <= 0 || !in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)
            || $salaryGrade === '' || $salaryStep < 1 || $salaryStep > 8
            || !SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)
            || !SalaryCreditPolicy::isValidCreditPercentage($creditPercentage) || $reason === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Salary schedule, grade, step, employment type, effective date, credit percentage, and reason are required.',
            ]);
        }

        $db = Database::connect();
        foreach (['employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            if (!$db->fieldExists($field, 'users')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'status' => 'error',
                    'message' => 'Dynamic salary-grade setup is unavailable until the latest database migration is applied.',
                ]);
            }
        }
        if (!$db->fieldExists('credit_rate', 'balances')) {
            return $this->response->setStatusCode(503)->setJSON([
                'status' => 'error',
                'message' => 'Dynamic credit percentage is unavailable until the latest database migration is applied.',
            ]);
        }

        $resolvedRate = (new SalaryScheduleService())->resolveRate($db, $scheduleId, $salaryGrade, $salaryStep);
        if ($resolvedRate === null) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'The selected salary grade and step are not available in that salary schedule.',
            ]);
        }
        if ($effectiveDate < $resolvedRate['effective_from']
            || ($resolvedRate['effective_to'] !== null && $effectiveDate > $resolvedRate['effective_to'])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'The effective date must fall within the selected salary schedule.',
            ]);
        }

        $salary = (float) $resolvedRate['monthly_salary'];
        $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage);
        $creditLimit = SalaryCreditPolicy::creditLimit($salary, $creditRate);
        $current = $db->table('users u')
            ->select('u.base_salary, u.employment_type, u.salary_grade, u.salary_step, u.salary_effective_date, u.salary_schedule_id, u.user_type, u.is_active, b.user_id AS balance_user_id, b.credit_limit, b.credit_rate')
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.id', $userId)->get()->getRowArray();
        if (!$current) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Employee not found.']);
        }
        if (!ibems_bool($current['is_active'] ?? false) || !in_array(strtolower((string) ($current['user_type'] ?? '')), ['faculty', 'staff'], true)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Only active Faculty and Staff can have an employee financial profile.']);
        }

        $wasConfigured = $current['balance_user_id'] !== null;
        $beforeSalary = (float) ($current['base_salary'] ?? 0);
        $beforeLimit = (float) ($current['credit_limit'] ?? 0);
        $beforeCreditRate = (float) ($current['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE);
        $beforeProfile = [
            'employment_type' => $current['employment_type'] ?? null,
            'salary_grade' => $current['salary_grade'] ?? null,
            'salary_step' => $current['salary_step'] ?? null,
            'salary_effective_date' => $current['salary_effective_date'] ?? null,
            'salary_schedule_id' => $current['salary_schedule_id'] ?? null,
        ];
        $now = date('Y-m-d H:i:s');
        $db->transException(true)->transStart();
        try {
            $db->table('users')->where('id', $userId)->update([
                'base_salary' => $salary,
                'employment_type' => $employmentType,
                'salary_grade' => $resolvedRate['salary_grade_label'],
                'salary_step' => $salaryStep,
                'salary_effective_date' => $effectiveDate,
                'salary_schedule_id' => $scheduleId,
            ]);
            if ($wasConfigured) {
                $db->table('balances')->where('user_id', $userId)->update([
                    'credit_limit' => $creditLimit,
                    'credit_rate' => $creditRate,
                    'updated_at' => $now,
                ]);
            } else {
                $db->table('balances')->insert([
                    'user_id' => $userId,
                    'credit_limit' => $creditLimit,
                    'credit_rate' => $creditRate,
                    'current_debt' => 0,
                    'updated_at' => $now,
                ]);
            }
            (new AuditLogModel())->insert([
                'actor_id' => $actorId,
                'action' => 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE',
                'entity' => 'balances',
                'entity_id' => $userId,
                'payload_json' => json_encode([
                    'previous_base_salary' => $beforeSalary,
                    'new_base_salary' => $salary,
                    'previous_credit_limit' => $beforeLimit,
                    'new_credit_limit' => $creditLimit,
                    'previous_salary_profile' => $beforeProfile,
                    'new_salary_profile' => [
                        'employment_type' => $employmentType,
                        'salary_grade' => $resolvedRate['salary_grade_label'],
                        'salary_step' => $salaryStep,
                        'salary_effective_date' => $effectiveDate,
                        'salary_schedule_id' => $scheduleId,
                        'salary_schedule_code' => $resolvedRate['schedule_code'],
                    ],
                    'previous_credit_percentage' => SalaryCreditPolicy::percentageFromRate($beforeCreditRate),
                    'new_credit_percentage' => $creditPercentage,
                    'credit_rate' => $creditRate,
                    'reason' => $reason,
                    'financial_profile_created' => !$wasConfigured,
                ]),
                'created_at' => $now,
            ]);
            $db->transComplete();
        } catch (\Throwable $exception) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Financial profile update failed and was rolled back.']);
        }

        return $this->response->setJSON([
            'status' => 'success', 'user_id' => $userId,
            'previous_base_salary' => $beforeSalary, 'new_base_salary' => $salary,
            'previous_credit_limit' => $beforeLimit, 'new_credit_limit' => $creditLimit,
            'employment_type' => $employmentType, 'salary_grade' => $resolvedRate['salary_grade_label'],
            'salary_step' => $salaryStep, 'salary_effective_date' => $effectiveDate,
            'salary_schedule_id' => $scheduleId, 'salary_schedule_code' => $resolvedRate['schedule_code'],
            'credit_rate' => $creditRate, 'credit_percentage' => $creditPercentage,
            'financial_profile_created' => !$wasConfigured,
        ]);
    }

    private function salaryProfileSelect($db, string $alias): string
    {
        $parts = [];
        foreach (['employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            $parts[] = $db->fieldExists($field, 'users')
                ? $alias . '.' . $field
                : 'NULL AS ' . $field;
        }

        return implode(', ', $parts);
    }

    private function buildDebtStatus(float $creditLimit, float $currentDebt): array
    {
        if ($currentDebt <= 0) {
            return [
                'debt_status' => 'no_outstanding_debt',
                'debt_status_label' => 'No Outstanding Debt',
                'debt_status_tone' => 'success',
                'debt_ratio' => 0.0,
            ];
        }

        if ($creditLimit > 0 && $currentDebt > $creditLimit) {
            return [
                'debt_status' => 'over_limit',
                'debt_status_label' => 'Over Limit',
                'debt_status_tone' => 'danger',
                'debt_ratio' => $currentDebt / $creditLimit,
            ];
        }

        $ratio = $creditLimit > 0 ? $currentDebt / $creditLimit : 1.0;
        if ($creditLimit > 0 && $ratio >= 1.0) {
            return [
                'debt_status' => 'at_credit_limit',
                'debt_status_label' => 'At Credit Limit',
                'debt_status_tone' => 'warning',
                'debt_ratio' => $ratio,
            ];
        }

        return [
            'debt_status' => 'outstanding',
            'debt_status_label' => 'Outstanding',
            'debt_status_tone' => 'info',
            'debt_ratio' => $ratio,
        ];
    }

    private function getAccountingCashbookRows(array $entryTypes, int $limit = 100): array
    {
        if ($entryTypes === []) {
            return [];
        }

        $db = Database::connect();
        if (!$db->tableExists('debt_cashbook_entries')) {
            return [];
        }

        $rows = $db->table('debt_cashbook_entries dce')
            ->select('dce.id, dce.user_id, dce.entry_type, dce.direction, dce.amount, dce.debt_before, dce.debt_after, dce.reference_type, dce.reference_id, dce.remarks, dce.meta_json, dce.created_at, u.employee_id, u.name, u.email, u.user_type, actor.name AS actor_name')
            ->join('users u', 'u.id = dce.user_id', 'left')
            ->join('users actor', 'actor.id = dce.actor_id', 'left')
            ->whereIn('dce.entry_type', $entryTypes)
            ->orderBy('dce.id', 'DESC')
            ->limit(max(1, min(300, $limit)))
            ->get()
            ->getResultArray();

        return array_map(static function (array $row): array {
            $meta = [];
            if (!empty($row['meta_json'])) {
                $decoded = json_decode((string) $row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'employee_id' => $row['employee_id'] ?? null,
                'name' => $row['name'] ?: 'Unknown',
                'email' => $row['email'] ?? null,
                'user_type' => $row['user_type'] ?? null,
                'entry_type' => (string) ($row['entry_type'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'debt_before' => (float) ($row['debt_before'] ?? 0),
                'debt_after' => (float) ($row['debt_after'] ?? 0),
                'reference_type' => $row['reference_type'] ?? null,
                'reference_id' => $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
                'remarks' => $row['remarks'] ?? null,
                'actor_name' => $row['actor_name'] ?: 'System',
                'created_at' => $row['created_at'] ?? null,
                'meta' => $meta,
                'store_name' => $meta['store_name'] ?? null,
                'channel' => $meta['channel'] ?? null,
                'business_date' => $meta['business_date'] ?? null,
            ];
        }, $rows);
    }
}
