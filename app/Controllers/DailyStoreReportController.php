<?php

namespace App\Controllers;

use App\Services\DailyStoreReportService;
use App\Services\StoreAccessService;
use Config\Database;

class DailyStoreReportController extends BaseController
{
    public function storeInbox()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 0);
        $store = (new StoreAccessService())->resolve((int) session()->get('user_id'), ibems_current_role(), $storeId);
        if ($storeId <= 0 || $store === null) {
            return redirect()->to(site_url('store-admin/stores'));
        }
        $requestedPage = filter_var($this->request->getGet('page') ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($requestedPage === false) {
            return $this->response->setStatusCode(400)->setBody('Select a valid report page.');
        }
        $db = Database::connect();
        $dayQuery = $db->table('store_day_sessions')->select('business_date')
            ->selectMax('id', 'latest_session_id')
            ->where('store_id', $storeId)->where('status', 'closed')
            ->groupBy('business_date');
        $total = $dayQuery->countAllResults(false);
        $pageSize = 60;
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page = min($requestedPage, $lastPage);
        $selectedDays = $dayQuery->orderBy('business_date', 'DESC')
            ->limit($pageSize, ($page - 1) * $pageSize)->get()->getResultArray();
        $sessions = $reports = [];
        if ($selectedDays !== []) {
            $sessions = $db->table('store_day_sessions')
                ->where('store_id', $storeId)->whereIn('id', array_column($selectedDays, 'latest_session_id'))
                ->orderBy('business_date', 'DESC')->get()->getResultArray();
            $latestRevisions = $db->table('daily_store_reports')->select('business_date')
                ->selectMax('revision', 'latest_revision')->where('store_id', $storeId)
                ->whereIn('business_date', array_column($selectedDays, 'business_date'))
                ->groupBy('business_date')->get()->getResultArray();
            if ($latestRevisions !== []) {
                $reportQuery = $db->table('daily_store_reports')->where('store_id', $storeId)->groupStart();
                foreach ($latestRevisions as $revision) {
                    $reportQuery->orGroupStart()->where('business_date', $revision['business_date'])
                        ->where('revision', $revision['latest_revision'])->groupEnd();
                }
                $reports = $reportQuery->groupEnd()->get()->getResultArray();
            }
        }
        $this->attachReturnNotes($db, $reports);
        $latestByDate = [];
        foreach ($reports as $report) {
            $latestByDate[$report['business_date']] ??= $report;
        }
        $days = [];
        foreach ($sessions as $session) {
            $date = (string) $session['business_date'];
            $days[$date] ??= ['session' => $session, 'report' => $latestByDate[$date] ?? null];
        }
        return view('store-admin/daily-reports', [
            'storeId' => $storeId,
            'storeName' => (string) $store['store_name'],
            'days' => array_values($days),
            'pagination' => [
                'page' => $page, 'lastPage' => $lastPage, 'total' => $total, 'label' => 'closed days',
                'first' => $total === 0 ? 0 : ($page - 1) * $pageSize + 1,
                'last' => min($page * $pageSize, $total),
                'url' => site_url('store-admin/daily-reports') . '?store_id=' . $storeId,
                'previousLabel' => 'Previous newer days', 'nextLabel' => 'Next older days',
            ],
            'message' => session()->getFlashdata('report_message'),
            'error' => session()->getFlashdata('report_error'),
        ]);
    }

    public function submit()
    {
        $sessionId = (int) ($this->request->getPost('session_id') ?? 0);
        $result = (new DailyStoreReportService())->submit(
            $sessionId, (int) session()->get('user_id'), ibems_current_role(),
            (string) ($this->request->getPost('note') ?? '')
        );
        $success = ($result['status'] ?? '') === 'success';
        session()->setFlashdata($success ? 'report_message' : 'report_error',
            $success ? 'Closed-day report submitted to the BE Director.' : (string) ($result['message'] ?? 'Submission failed.'));
        $storeId = (int) ($result['report']['store_id'] ?? $this->request->getPost('store_id') ?? 0);
        $store = (new StoreAccessService())->resolve((int) session()->get('user_id'), ibems_current_role(), $storeId);
        return redirect()->to($store && $storeId > 0
            ? site_url('store-admin/daily-reports') . '?store_id=' . $storeId
            : site_url('store-admin/stores'));
    }

    public function preview(int $sessionId)
    {
        $result = (new DailyStoreReportService())->preview($sessionId, (int) session()->get('user_id'), ibems_current_role());
        return $this->response->setStatusCode((int) ($result['code'] ?? 400))->setBody(view('store-admin/daily-report-preview', [
            'result' => $result,
            'backUrl' => ($result['status'] ?? '') === 'success'
                ? site_url('store-admin/daily-reports') . '?store_id=' . (int) $result['store_id']
                : site_url('store-admin/stores'),
        ]));
    }

    public function beInbox()
    {
        return $this->inbox('be');
    }

    public function cashierInbox()
    {
        return $this->inbox('cashier');
    }

    public function accountingInbox()
    {
        return $this->inbox('accounting');
    }

    public function review(int $reportId)
    {
        $decision = (string) $this->request->getPost('decision');
        if (!in_array($decision, ['approve', 'return'], true)) {
            session()->setFlashdata('report_error', 'Choose Approve or Return.');
            return redirect()->to(site_url('be/reports'));
        }
        $result = (new DailyStoreReportService())->review(
            $reportId, (int) session()->get('user_id'), $decision === 'approve',
            (string) $this->request->getPost('note'), ibems_current_role()
        );
        session()->setFlashdata(($result['status'] ?? '') === 'success' ? 'report_message' : 'report_error',
            ($result['status'] ?? '') === 'success' ? 'BE review recorded.' : (string) ($result['message'] ?? 'Review failed.'));
        return redirect()->to(site_url('be/reports'));
    }

    public function receive(int $reportId)
    {
        $result = (new DailyStoreReportService())->receive($reportId, (int) session()->get('user_id'), ibems_current_role());
        session()->setFlashdata(($result['status'] ?? '') === 'success' ? 'report_message' : 'report_error',
            ($result['status'] ?? '') === 'success' ? 'Cashier Office receipt recorded.' : (string) ($result['message'] ?? 'Receipt failed.'));
        return redirect()->to(site_url('cashier/reports'));
    }

    private function inbox(string $audience)
    {
        $requestedPage = filter_var($this->request->getGet('page') ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($requestedPage === false) {
            return $this->response->setStatusCode(400)->setBody('Select a valid report page.');
        }
        $db = Database::connect();
        $query = $db->table('daily_store_reports r')
            ->select('r.*, s.store_name')
            ->join('stores s', 's.id = r.store_id', 'inner');
        if ($audience === 'cashier') {
            $query->groupStart()->whereIn('r.status', ['be_approved', 'cashier_received'])
                ->orGroupStart()->where('r.status', 'be_returned')
                ->where('r.cashier_received_at IS NOT NULL', null, false)->groupEnd()->groupEnd();
        } elseif ($audience === 'accounting') {
            $query->where('r.accounting_furnished_at IS NOT NULL', null, false);
        }
        // Keep alias metadata and audience predicates for the subsequent page query.
        $total = $query->countAllResults(false);
        $pageSize = 100;
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page = min($requestedPage, $lastPage);
        $reports = $query->orderBy('r.business_date', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($pageSize, ($page - 1) * $pageSize)->get()->getResultArray();
        $this->attachReturnNotes($db, $reports);
        $integrity = new DailyStoreReportService($db);
        foreach ($reports as &$report) {
            $report['source_status'] = $integrity->sourceStatus($report);
        }
        unset($report);
        $role = ibems_current_role();
        return view('office/daily_reports', [
            'portalTitle' => $audience === 'be' ? 'Business Enterprise' : ($audience === 'cashier' ? 'Cashier Office' : 'Accounting Portal'),
            'portalSubtitle' => $audience === 'be' ? 'Director' : ($audience === 'cashier' ? ($role === 'CASHIER_HEAD' ? 'Cashier Head' : 'Collecting Officer') : 'Report copy'),
            'homePath' => $audience === 'be' ? 'be/dashboard' : ($audience === 'cashier' ? 'cashier/dashboard' : 'accounting/dashboard'),
            'audience' => $audience,
            'reports' => $reports,
            'pagination' => [
                'page' => $page, 'lastPage' => $lastPage, 'total' => $total,
                'first' => $total === 0 ? 0 : ($page - 1) * $pageSize + 1,
                'last' => min($page * $pageSize, $total),
                'url' => site_url(match ($audience) {
                    'be' => 'be/reports', 'cashier' => 'cashier/reports', default => 'accounting/reports',
                }),
            ],
            'message' => session()->getFlashdata('report_message'),
            'error' => session()->getFlashdata('report_error'),
        ]);
    }

    /** @param list<array<string,mixed>> $reports */
    private function attachReturnNotes(\CodeIgniter\Database\BaseConnection $db, array &$reports): void
    {
        $ids = array_map(static fn (array $report): int => (int) $report['id'], $reports);
        if ($ids === []) {
            return;
        }
        $events = $db->table('daily_store_report_events')->select('report_id, note')
            ->whereIn('report_id', $ids)->where('event_type', 'be_returned')
            ->orderBy('id', 'DESC')->get()->getResultArray();
        $latest = [];
        foreach ($events as $event) {
            $latest[(int) $event['report_id']] ??= (string) ($event['note'] ?? '');
        }
        foreach ($reports as &$report) {
            $report['return_note'] = $latest[(int) $report['id']] ?? null;
        }
        unset($report);
    }
}
