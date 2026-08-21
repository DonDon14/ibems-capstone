<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\UserModel;
use CodeIgniter\Controller;
use Config\Database;
use Config\Services;

class UserController extends Controller
{
    public function dashboard()
    {
        return view('user/dashboard');
    }

    public function dashboardData()
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

        $recentTransactions = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, s.store_name')
            ->join('stores s', 's.id = t.store_id', 'left')
            ->where('t.user_id', $userId)
            ->orderBy('t.id', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $recentCashbook = $db->table('debt_cashbook_entries')
            ->select('id, created_at, entry_type, direction, amount, debt_after, remarks')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        $trendByDate = [];
        $trendSeed = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} day"));
            $trendByDate[$date] = ['date' => $date, 'amount' => 0.0];
            $trendSeed[] = $date;
        }

        $trendRows = $db->table('transactions')
            ->select('created_at, amount')
            ->where('user_id', $userId)
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
            $trendByDate[$date]['amount'] += (float) ($row['amount'] ?? 0);
        }

        $trend = [];
        foreach ($trendSeed as $date) {
            $trend[] = $trendByDate[$date];
        }

        $summary = [
            'credit_limit' => (float) ($balance['credit_limit'] ?? 0),
            'current_debt' => (float) ($balance['current_debt'] ?? 0),
            'available_credit' => max(0, (float) ($balance['credit_limit'] ?? 0) - (float) ($balance['current_debt'] ?? 0)),
            'txn_count' => (int) ($txnSummary['txn_count'] ?? 0),
            'total_spent' => (float) ($txnSummary['total_spent'] ?? 0),
            'debt_added_total' => $debtAddedTotal,
            'debt_deducted_total' => $deductedTotal,
        ];
        $summary = array_merge($summary, $this->buildDebtStatus($summary['credit_limit'], $summary['current_debt']));

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => $summary,
            'trend' => $trend,
            'recent_transactions' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'client_txn_id' => (string) ($row['client_txn_id'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'payment_method' => (string) ($row['payment_method'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'store_name' => (string) ($row['store_name'] ?? ''),
                ];
            }, $recentTransactions),
            'recent_cashbook' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'entry_type' => (string) ($row['entry_type'] ?? ''),
                    'direction' => (string) ($row['direction'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'debt_after' => (float) ($row['debt_after'] ?? 0),
                    'remarks' => (string) ($row['remarks'] ?? ''),
                ];
            }, $recentCashbook),
        ]);
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
            ] + $this->buildDebtStatus((float) ($balance['credit_limit'] ?? 0), (float) ($balance['current_debt'] ?? 0)),
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
        $limit = (int) ($this->request->getGet('limit') ?? 150);
        $limit = max(1, min(300, $limit));

        $db = Database::connect();
        $query = $db->table('debt_cashbook_entries dce')
            ->select('dce.id, dce.created_at, dce.entry_type, dce.direction, dce.amount, dce.debt_before, dce.debt_after, dce.credit_limit_snapshot, dce.available_credit_snapshot, dce.reference_type, dce.reference_id, dce.remarks')
            ->where('dce.user_id', $userId);

        if ($dateFrom !== '') {
            $query->where('dce.created_at >=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $query->where('dce.created_at <=', $dateTo . ' 23:59:59');
        }

        $rows = $query->orderBy('dce.id', 'DESC')
            ->limit($limit)
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
        $limit = (int) ($this->request->getGet('limit') ?? 100);
        $limit = max(1, min(200, $limit));

        $db = Database::connect();
        $query = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, t.store_id, s.store_name')
            ->join('stores s', 's.id = t.store_id', 'left')
            ->where('t.user_id', $userId);

        if ($dateFrom !== '') {
            $query->where('t.created_at >=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('t.created_at <=', $dateTo . ' 23:59:59');
        }

        $rows = $query->orderBy('t.id', 'DESC')->limit($limit)->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'client_txn_id' => $row['client_txn_id'],
                    'created_at' => $row['created_at'],
                    'payment_method' => $row['payment_method'],
                    'amount' => (float) $row['amount'],
                    'store_name' => $row['store_name'] ?: ('Store #' . (int) $row['store_id']),
                ];
            }, $rows),
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
            ->select('ti.product_id, ti.qty, ti.unit_price, ti.line_total, p.name AS product_name')
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

    private function buildDebtStatus(float $creditLimit, float $currentDebt): array
    {
        if ($currentDebt <= 0) {
            return [
                'debt_status' => 'paid',
                'debt_status_label' => 'Paid',
                'debt_status_tone' => 'success',
                'debt_status_message' => 'Your account has no unpaid debt.',
                'debt_ratio' => 0.0,
            ];
        }

        if ($creditLimit > 0 && $currentDebt > $creditLimit) {
            return [
                'debt_status' => 'over_limit',
                'debt_status_label' => 'Over Limit',
                'debt_status_tone' => 'danger',
                'debt_status_message' => 'Your current debt is above your credit limit. Please coordinate with Accounting before adding more debt purchases.',
                'debt_ratio' => $currentDebt / $creditLimit,
            ];
        }

        $ratio = $creditLimit > 0 ? $currentDebt / $creditLimit : 1.0;
        if ($ratio >= 0.5) {
            return [
                'debt_status' => 'partially_settled',
                'debt_status_label' => 'Partially Settled',
                'debt_status_tone' => 'warning',
                'debt_status_message' => 'You have an unpaid balance and have used more than half of your credit limit.',
                'debt_ratio' => $ratio,
            ];
        }

        return [
            'debt_status' => 'unpaid',
            'debt_status_label' => 'Unpaid',
            'debt_status_tone' => 'info',
            'debt_status_message' => 'You have an unpaid balance within your available credit limit.',
            'debt_ratio' => $ratio,
        ];
    }
}
