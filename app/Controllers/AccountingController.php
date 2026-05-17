<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\SettlementRunModel;
use App\Models\UserModel;
use App\Services\AuditService;
use App\Services\CreditService;

class AccountingController extends BaseController
{
    public function index()
    {
        $balances = (new BalanceModel())
            ->select('balances.*, users.name, users.employee_id')
            ->join('users', 'users.id = balances.user_id')
            ->orderBy('users.name', 'ASC')
            ->findAll();

        return view('accounting/index', [
            'title' => 'Accounting',
            'balances' => $balances,
        ]);
    }

    public function creditOverride(int $userId)
    {
        $limit = (float) $this->request->getPost('credit_limit');
        $balanceModel = new BalanceModel();
        $existing = $balanceModel->find($userId);

        if ($existing === null) {
            $balanceModel->insert([
                'user_id' => $userId,
                'credit_limit' => $limit,
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $balanceModel->update($userId, [
                'credit_limit' => $limit,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new AuditService())->log(current_user_id(), 'UPDATE', 'balances', (string) $userId, ['credit_limit' => $limit]);

        return redirect()->to('/accounting')->with('success', 'Credit limit updated.');
    }

    public function globalSettle()
    {
        $balanceModel = new BalanceModel();
        $rows = $balanceModel->findAll();
        $totalDebt = 0;
        foreach ($rows as $row) {
            $totalDebt += (float) $row['current_debt'];
        }

        $runId = (new SettlementRunModel())->insert([
            'run_month' => date('Y-m'),
            'run_by' => current_user_id(),
            'run_at' => date('Y-m-d H:i:s'),
            'total_accounts' => count($rows),
            'total_debt_before' => $totalDebt,
            'notes' => 'Global settle executed',
        ], true);

        foreach ($rows as $row) {
            $balanceModel->update($row['user_id'], [
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new AuditService())->log(current_user_id(), 'SETTLE', 'settlement_runs', (string) $runId, [
            'total_accounts' => count($rows),
            'total_debt_before' => $totalDebt,
        ]);

        return redirect()->to('/accounting')->with('success', 'Global settlement completed.');
    }
}
