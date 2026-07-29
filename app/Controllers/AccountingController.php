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
use CodeIgniter\Controller;
use Config\Database;

class AccountingController extends Controller
{
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
        $periods = $db->table('deduction_periods dp')
            ->select('dp.*, db.id AS batch_id, db.status AS batch_status, db.total_accounts, db.total_requested, db.total_confirmed, db.total_carryover')
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

        return $this->response->setJSON([
            'status' => 'success',
            'periods' => $periods,
            'items' => $items,
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
            ->where('u.is_active', 1)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->get()
            ->getRowArray() ?? [];

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todayCashbook = $db->table('debt_cashbook_entries')
            ->select('COUNT(*) AS entry_count, COALESCE(SUM(amount), 0) AS total_amount')
            ->where('direction', 'credit')
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->get()
            ->getRowArray() ?? [];

        $todayDeductionCount = (int) ($todayCashbook['entry_count'] ?? 0);
        $todayDeductionAmount = (float) ($todayCashbook['total_amount'] ?? 0);

        $lastRun = $db->table('settlement_runs sr')
            ->select('sr.id, sr.run_month, sr.run_at, sr.total_accounts, sr.total_debt_before, u.name AS run_by_name')
            ->join('users u', 'u.id = sr.run_by', 'left')
            ->orderBy('sr.id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $topDebtAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $overLimitAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', 1)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt > b.credit_limit', null, false)
            ->where('b.current_debt >', 0)
            ->orderBy('(b.current_debt - b.credit_limit)', 'DESC', false)
            ->limit(5)
            ->get()
            ->getResultArray();

        $staleCutoff = date('Y-m-d H:i:s', strtotime('-30 day'));
        $staleDebtAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, b.current_debt, b.updated_at, MAX(dce.created_at) AS last_cashbook_at')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->join('debt_cashbook_entries dce', 'dce.user_id = u.id', 'left')
            ->where('u.is_active', 1)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->groupBy('u.id, u.employee_id, u.name, u.email, b.current_debt, b.updated_at')
            ->having('(last_cashbook_at IS NULL OR last_cashbook_at < ' . $db->escape($staleCutoff) . ')', null, false)
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
            ->join('users target', 'target.id = al.entity_id AND al.entity = "balances"', 'left')
            ->whereIn('al.action', [
                'ACCOUNTING_IMPORT_HR_CSV',
                'ACCOUNTING_DEDUCT_DEBT',
                'ACCOUNTING_DEDUCT_FULL_DEBT',
                'ACCOUNTING_UPDATE_CREDIT_LIMIT',
                'ACCOUNTING_RUN_SETTLEMENT',
                'ACCOUNTING_SETTLEMENT_DEDUCT',
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
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'label' => $label,
                'actor_name' => $row['actor_name'] ?: 'Unknown',
                'target_name' => $row['target_name'] ?: null,
                'amount' => (float) ($payload['deducted_amount'] ?? 0),
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
                'last_settlement_month' => $lastRun['run_month'] ?? null,
                'last_settlement_at' => $lastRun['run_at'] ?? null,
            ],
            'top_debt_accounts' => array_map(static function (array $row): array {
                return [
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
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

        $query = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, u.is_active, b.credit_limit, b.current_debt, b.updated_at')
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

        $data = array_map(function (array $row): array {
            $creditLimit = (float) $row['credit_limit'];
            $currentDebt = (float) $row['current_debt'];
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
            ->where('u.is_active', 1)
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
            ->where('u.is_active', 1)
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
            $monthlySalaryRaw = $rowAssoc['monthly_salary'] ?? '';
            $creditLimitRaw = $rowAssoc['credit_limit'] ?? '';
            $monthlySalary = is_numeric($monthlySalaryRaw) ? (float) $monthlySalaryRaw : -1;
            $creditLimit = $hasCreditLimit && $creditLimitRaw !== '' && is_numeric($creditLimitRaw) ? (float) $creditLimitRaw : null;

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
            if (!is_numeric($monthlySalaryRaw) || $monthlySalary < 0) {
                $errors[] = 'monthly_salary must be a number >= 0';
            }
            if ($hasCreditLimit && $creditLimitRaw !== '' && (!is_numeric($creditLimitRaw) || (float) $creditLimitRaw < 0)) {
                $errors[] = 'credit_limit must be a number >= 0';
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
            $monthlySalaryRaw = $rowAssoc['monthly_salary'] ?? '';
            $creditLimitRaw = $rowAssoc['credit_limit'] ?? '';
            $monthlySalary = is_numeric($monthlySalaryRaw) ? (float) $monthlySalaryRaw : -1;
            $creditLimit = $hasCreditLimit && $creditLimitRaw !== '' && is_numeric($creditLimitRaw) ? (float) $creditLimitRaw : null;

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
            if (!is_numeric($monthlySalaryRaw) || $monthlySalary < 0) {
                $errors[] = 'monthly_salary must be a number >= 0';
            }
            if ($hasCreditLimit && $creditLimitRaw !== '' && (!is_numeric($creditLimitRaw) || (float) $creditLimitRaw < 0)) {
                $errors[] = 'credit_limit must be a number >= 0';
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
            if ($hasCreditLimit && $creditLimit !== null) {
                $creditLimitUpdateCount++;
            }

            if (count($validPreview) < 20) {
                $validPreview[] = [
                    'line' => $totalRows + 1,
                    'employee_id' => $employeeId,
                    'name' => $name,
                    'email' => $email,
                    'user_type' => $userType,
                    'monthly_salary' => $monthlySalary,
                    'credit_limit' => $creditLimit,
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

        $balance = $balanceModel->getBalanceByUserId($userId);
        if (!$balance) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Balance record not found.',
            ]);
        }

        $currentDebt = (float) $balance['current_debt'];
        $creditLimit = (float) $balance['credit_limit'];
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
        $creditLimit = (float) ($balance['credit_limit'] ?? 0);
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

    private function buildDebtStatus(float $creditLimit, float $currentDebt): array
    {
        if ($currentDebt <= 0) {
            return [
                'debt_status' => 'settled',
                'debt_status_label' => 'Settled',
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
        if ($ratio >= 0.5) {
            return [
                'debt_status' => 'partially_settled',
                'debt_status_label' => 'Partially Settled',
                'debt_status_tone' => 'warning',
                'debt_ratio' => $ratio,
            ];
        }

        return [
            'debt_status' => 'pending',
            'debt_status_label' => 'Pending',
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
