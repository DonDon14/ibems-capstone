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

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'credit_limit' => (float) ($balance['credit_limit'] ?? 0),
                'current_debt' => (float) ($balance['current_debt'] ?? 0),
                'available_credit' => max(0, (float) ($balance['credit_limit'] ?? 0) - (float) ($balance['current_debt'] ?? 0)),
                'txn_count' => (int) ($txnSummary['txn_count'] ?? 0),
                'total_spent' => (float) ($txnSummary['total_spent'] ?? 0),
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
