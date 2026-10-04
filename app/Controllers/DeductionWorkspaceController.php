<?php

namespace App\Controllers;

use App\Services\DebtPeriodRegisterService;
use App\Services\DeductionBatchService;
use App\Services\DeductionCarryoverTraceService;
use App\Services\DeductionPeriodService;
use Config\Database;

/** One audited deduction workflow shared by Accounting and the School Cashier Office. */
class DeductionWorkspaceController extends BaseController
{
    public function index()
    {
        $db = Database::connect();
        $periodTotal = (int) $db->table('deduction_periods')->countAllResults();
        $periodPages = max(1, (int) ceil($periodTotal / 50));
        $periodPage = min($periodPages, max(1, (int) ($this->request->getGet('page') ?? 1)));
        $periods = $db->table('deduction_periods')
            ->orderBy('date_start', 'DESC')->orderBy('id', 'DESC')
            ->limit(50, ($periodPage - 1) * 50)->get()->getResultArray();
        $requestedId = max(0, (int) ($this->request->getGet('period_id') ?? 0));
        $selected = null;
        foreach ($periods as $period) {
            if ((int) $period['id'] === $requestedId) {
                $selected = $period;
                break;
            }
        }
        $selectedOutsidePage = false;
        if ($selected === null && $requestedId > 0) {
            $selected = $db->table('deduction_periods')->where('id', $requestedId)->get()->getRowArray();
            if ($selected !== null) {
                array_unshift($periods, $selected);
                $selectedOutsidePage = true;
            }
        }
        if ($selected === null && $periods !== [] && $requestedId === 0) {
            $selected = $periods[0];
        }

        $batch = null;
        $items = [];
        $register = [];
        $historyUnavailable = false;
        $priorResults = [];
        if ($selected !== null) {
            $batch = $db->table('deduction_batches')->where('period_id', $selected['id'])->get()->getRowArray();
            if ($batch !== null) {
                $items = $db->table('deduction_batch_items i')
                    ->select('i.*, u.name, u.employee_id')
                    ->join('users u', 'u.id = i.user_id', 'inner')
                    ->where('i.batch_id', $batch['id'])
                    ->orderBy('u.name', 'ASC')->get()->getResultArray();
            }
            if ($batch === null || (string) $batch['status'] === 'prepared') {
                $allAccounts = (new DebtPeriodRegisterService())->build($selected);
                $historyUnavailable = count(array_filter($allAccounts,
                    static fn (array $row): bool => empty($row['history_complete']))) > 0;
                $register = $historyUnavailable ? [] : array_values(array_filter(
                    $allAccounts,
                    static fn (array $row): bool => (float) ($row['cutoff_debt'] ?? 0) > 0
                ));
            }
            $visibleUserIds = array_map('intval', array_column($batch !== null ? $items : $register, 'user_id'));
            $priorResults = (new DeductionCarryoverTraceService($db))->priorResults($visibleUserIds, $selected);
        }

        $role = ibems_current_role();
        $cashier = in_array($role, ['CASHIER_HEAD', 'COLLECTING_OFFICER'], true);
        return view('office/deduction_workspace', [
            'portalTitle' => $cashier ? 'Cashier Office' : 'Accounting Payroll',
            'portalSubtitle' => $cashier ? ($role === 'CASHIER_HEAD' ? 'Cashier Head' : 'Collecting Officer') : 'Deduction Processing',
            'homePath' => $cashier ? 'cashier/dashboard' : ($role === 'PAYROLL_CLERK' ? 'accounting/payroll' : 'accounting/dashboard'),
            'periods' => $periods,
            'periodPage' => $periodPage,
            'periodPages' => $periodPages,
            'selectedOutsidePage' => $selectedOutsidePage,
            'selected' => $selected,
            'batch' => $batch,
            'items' => $items,
            'itemsByUser' => array_column($items, null, 'user_id'),
            'register' => $register,
            'historyUnavailable' => $historyUnavailable,
            'priorResults' => $priorResults,
            'message' => session()->getFlashdata('deduction_message'),
            'error' => session()->getFlashdata('deduction_error'),
        ]);
    }

    public function createPeriod()
    {
        $result = (new DeductionPeriodService())->create($this->request->getPost(), $this->actorId());
        return $this->back($result, (int) ($result['period']['id'] ?? 0));
    }

    public function prepareBatch()
    {
        $periodId = max(0, (int) $this->request->getPost('period_id'));
        $rows = $this->request->getPost('requests');
        $requests = [];
        foreach (is_array($rows) ? $rows : [] as $userId => $row) {
            if (!is_array($row) || !isset($row['selected'])) {
                continue;
            }
            $requests[] = [
                'user_id' => (int) $userId,
                'deduction_choice' => (string) ($row['deduction_choice'] ?? ''),
                'requested_amount' => (string) ($row['requested_amount'] ?? ''),
                'preparation_reason' => (string) ($row['preparation_reason'] ?? ''),
            ];
        }
        $result = (new DeductionBatchService())->prepare($periodId, $requests, $this->actorId());
        return $this->back($result, $periodId);
    }

    public function submitBatch(int $batchId)
    {
        return $this->batchAction($batchId, 'submit');
    }

    public function reconcileBatch(int $batchId)
    {
        return $this->batchAction($batchId, 'reconcile');
    }

    public function finalizeBatch(int $batchId)
    {
        return $this->batchAction($batchId, 'finalize');
    }

    public function confirmItem(int $itemId)
    {
        $db = Database::connect();
        $item = $db->table('deduction_batch_items')->where('id', $itemId)->get()->getRowArray();
        $periodId = 0;
        if ($item !== null) {
            $batch = $db->table('deduction_batches')->where('id', $item['batch_id'])->get()->getRowArray();
            $periodId = (int) ($batch['period_id'] ?? 0);
        }
        $result = (new DeductionBatchService())->confirmResult($itemId, $this->request->getPost(), $this->actorId());
        return $this->back($result, $periodId);
    }

    private function batchAction(int $batchId, string $action)
    {
        $batch = Database::connect()->table('deduction_batches')->where('id', $batchId)->get()->getRowArray();
        $service = new DeductionBatchService();
        $result = $service->{$action}($batchId, $this->actorId());
        return $this->back($result, (int) ($batch['period_id'] ?? 0));
    }

    private function back(array $result, int $periodId)
    {
        $success = ($result['status'] ?? '') === 'success';
        session()->setFlashdata($success ? 'deduction_message' : 'deduction_error',
            $success ? 'Deduction workflow updated.' : (string) ($result['message'] ?? 'The deduction action failed.'));
        $url = site_url('deductions/workspace');
        if ($periodId > 0) {
            $url .= '?period_id=' . $periodId;
        }
        return redirect()->to($url);
    }

    private function actorId(): int
    {
        return (int) session()->get('user_id');
    }
}
