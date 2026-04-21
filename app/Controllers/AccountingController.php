<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;

class AccountingController extends Controller
{
    public function debts()
    {
        $db = Database::connect();

        $rows = $db->table('balances b')
            ->select('u.id as user_id, u.name, u.email, b.credit_limit, b.current_debt, b.updated_at')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'count' => count($rows),
            'data' => $rows,
        ]);
    }
}
