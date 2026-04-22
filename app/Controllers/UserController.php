<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;

class UserController extends Controller
{
    public function dashboard()
    {
        return view('user/dashboard');
    }

    public function history()
    {
        return view('user/history');
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
            ],
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
}
