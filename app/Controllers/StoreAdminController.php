<?php

namespace App\Controllers;

use App\Models\StoreSupervisorModel;
use App\Models\StoreModel;
use App\Services\StoreOversightService;
use CodeIgniter\Controller;
use Config\Database;

class StoreAdminController extends Controller
{
    public function dashboard()
    {
        return view('store-admin/dashboard');
    }

    public function dashboardData()
    {
        $db = Database::connect();
        $actorId = (int) session()->get('user_id');
        $storeIds = (new StoreSupervisorModel())->getStoreIdsBySupervisor($actorId);
        $emptyPayload = [
            'status' => 'success',
            'summary' => [
                'assigned_store_count' => 0,
                'active_store_count' => 0,
                'open_day_count' => 0,
                'pending_review_count' => 0,
                'pending_shortage_total' => 0,
                'today_sales_total' => 0,
            ],
            'stores' => [],
            'pending_reviews' => [],
        ];

        if ($storeIds === []) {
            return $this->response->setJSON($emptyPayload);
        }

        $stores = $db->table('stores s')
            ->select('s.id, s.store_name, s.logo_url, s.is_active, u.name AS officer_name, u.email AS officer_email')
            ->join('users u', 'u.id = s.officer_id', 'left')
            ->whereIn('s.id', $storeIds)
            ->orderBy('s.store_name', 'ASC')
            ->get()
            ->getResultArray();

        $todayDate = date('Y-m-d');
        $todayStart = $todayDate . ' 00:00:00';
        $todayEnd = $todayDate . ' 23:59:59';
        $todayRows = $db->table('transactions')
            ->select('store_id, COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->whereIn('store_id', $storeIds)
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->groupBy('store_id')
            ->get()
            ->getResultArray();
        $todayByStore = [];
        foreach ($todayRows as $row) {
            $todayByStore[(int) ($row['store_id'] ?? 0)] = [
                'txn_count' => (int) ($row['txn_count'] ?? 0),
                'sales_total' => (float) ($row['sales_total'] ?? 0),
            ];
        }

        $latestSessionsByStore = [];
        $pendingRows = [];
        if ($db->tableExists('store_day_sessions')) {
            $sessionRows = $db->table('store_day_sessions sds')
                ->select('sds.id, sds.store_id, sds.business_date, sds.status, sds.expected_cash, sds.expected_ecash, sds.counted_cash, sds.counted_ecash, sds.variance_cash, sds.variance_ecash, sds.variance_status, sds.review_status, sds.closed_at, sds.accountability_amount, s.store_name, closer.name AS closed_by_name, c.case_ref, c.owner_user_id, owner.name AS owner_name, h.status AS handoff_status, h.due_at AS handoff_due_at')
                ->join('stores s', 's.id = sds.store_id', 'left')
                ->join('users closer', 'closer.id = sds.closed_by', 'left')
                ->join('store_day_variance_cases c', 'c.store_day_session_id = sds.id', 'left')
                ->join('users owner', 'owner.id = c.owner_user_id', 'left')
                ->join('store_day_variance_case_handoffs h', 'h.id = (SELECT MAX(h2.id) FROM store_day_variance_case_handoffs h2 WHERE h2.case_id = c.id)', 'left', false)
                ->whereIn('sds.store_id', $storeIds)
                ->orderBy('sds.business_date', 'DESC')
                ->orderBy('sds.id', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($sessionRows as $row) {
                $storeId = (int) ($row['store_id'] ?? 0);
                if ($storeId > 0 && !isset($latestSessionsByStore[$storeId])) {
                    $latestSessionsByStore[$storeId] = $row;
                }
                $reviewStatus = (string) ($row['review_status'] ?? 'not_required');
                if ((string) ($row['status'] ?? '') === 'closed' && $reviewStatus !== 'not_required' && !in_array($reviewStatus, ['approved', 'waived', 'corrected'], true)) {
                    $pendingRows[] = $row;
                }
            }
        }

        $activeStoreCount = 0;
        $openDayCount = 0;
        $todaySalesTotal = 0.0;
        $storePayload = [];
        foreach ($stores as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            $isActive = ibems_bool($store['is_active'] ?? false);
            $session = $latestSessionsByStore[$storeId] ?? null;
            $today = $todayByStore[$storeId] ?? ['txn_count' => 0, 'sales_total' => 0.0];
            $todaySalesTotal += (float) $today['sales_total'];
            if ($isActive) {
                $activeStoreCount++;
            }
            $dayStatus = $this->dayStatusForDate($session, $todayDate);
            if ($dayStatus === 'open') {
                $openDayCount++;
            }

            $storePayload[] = [
                'id' => $storeId,
                'store_name' => (string) ($store['store_name'] ?? ''),
                'logo_url' => $store['logo_url'] ?? null,
                'is_active' => $isActive,
                'officer_name' => $store['officer_name'] ?: 'No assigned officer',
                'officer_email' => $store['officer_email'] ?? null,
                'today_txn_count' => (int) $today['txn_count'],
                'today_sales_total' => (float) $today['sales_total'],
                'day_status' => $dayStatus,
                'business_date' => $session['business_date'] ?? null,
                'review_status' => $session ? (string) ($session['review_status'] ?? 'not_required') : 'not_required',
                'variance_status' => $session ? (string) ($session['variance_status'] ?? 'balanced') : 'balanced',
            ];
        }

        $pendingShortageTotal = 0.0;
        $pendingReviews = array_map(static function (array $row) use (&$pendingShortageTotal): array {
            $varianceCash = (float) ($row['variance_cash'] ?? 0);
            $varianceEcash = (float) ($row['variance_ecash'] ?? 0);
            $shortageAmount = round(max(0, -$varianceCash) + max(0, -$varianceEcash), 2);
            $pendingShortageTotal += $shortageAmount;

            return [
                'id' => (int) ($row['id'] ?? 0),
                'store_id' => (int) ($row['store_id'] ?? 0),
                'store_name' => (string) ($row['store_name'] ?? 'Store'),
                'business_date' => (string) ($row['business_date'] ?? ''),
                'closed_at' => $row['closed_at'] ?? null,
                'closed_by_name' => $row['closed_by_name'] ?: 'Unknown',
                'expected_cash' => (float) ($row['expected_cash'] ?? 0),
                'expected_ecash' => (float) ($row['expected_ecash'] ?? 0),
                'counted_cash' => $row['counted_cash'] !== null ? (float) $row['counted_cash'] : null,
                'counted_ecash' => $row['counted_ecash'] !== null ? (float) $row['counted_ecash'] : null,
                'variance_cash' => $row['variance_cash'] !== null ? (float) $row['variance_cash'] : null,
                'variance_ecash' => $row['variance_ecash'] !== null ? (float) $row['variance_ecash'] : null,
                'variance_status' => (string) ($row['variance_status'] ?? 'balanced'),
                'review_status' => (string) ($row['review_status'] ?? 'pending'),
                'shortage_amount' => $shortageAmount,
                'case_ref' => $row['case_ref'] ?? null,
                'owner_name' => $row['owner_name'] ?? null,
                'handoff_overdue' => (string) ($row['handoff_status'] ?? '') === 'pending' && (string) ($row['handoff_due_at'] ?? '') !== '' && strtotime((string) $row['handoff_due_at']) < time(),
            ];
        }, $pendingRows);

        return $this->response->setJSON([
            'status' => 'success',
            'summary' => [
                'assigned_store_count' => count($stores),
                'active_store_count' => $activeStoreCount,
                'open_day_count' => $openDayCount,
                'pending_review_count' => count($pendingReviews),
                'pending_shortage_total' => $pendingShortageTotal,
                'today_sales_total' => $todaySalesTotal,
            ],
            'stores' => $storePayload,
            'pending_reviews' => $pendingReviews,
        ]);
    }

    protected function dayStatusForDate(?array $session, string $todayDate): string
    {
        $sessionDate = (string) ($session['business_date'] ?? '');
        $sessionStatus = (string) ($session['status'] ?? '');
        if ($sessionDate === $todayDate) {
            return $sessionStatus !== '' ? $sessionStatus : 'not_started';
        }

        return $sessionStatus === 'open' ? 'stale_open' : 'not_started';
    }

    public function stores()
    {
        return view('store-admin/stores');
    }

    public function storesData()
    {
        return (new StoreOversightService())->storesData($this->request, $this->response);
    }

    public function storeDetails(int $storeId)
    {
        if ($storeId <= 0 || !(new StoreModel())->canUserAccessStore((int) session()->get('user_id'), 'STORE_SUPERVISOR', $storeId)) {
            return redirect()->to('/store-admin/stores');
        }

        return view('store-admin/store-details', ['storeId' => $storeId]);
    }

    public function storeDetailsData(int $storeId)
    {
        return (new StoreOversightService())->storeDetailsData($this->request, $this->response, $storeId);
    }

    public function reviewStoreDayVariance(int $sessionId)
    {
        return (new StoreOversightService())->reviewStoreDayVariance($this->request, $this->response, $sessionId);
    }

    public function uploadVarianceCaseAttachment(int $caseId) { return (new StoreOversightService())->uploadVarianceCaseAttachment($this->request, $this->response, $caseId); }
    public function downloadVarianceCaseAttachment(int $attachmentId) { return (new StoreOversightService())->downloadVarianceCaseAttachment($this->response, $attachmentId); }
    public function handoffVarianceCase(int $caseId) { return (new StoreOversightService())->handoffVarianceCase($this->request, $this->response, $caseId); }
    public function acknowledgeVarianceCase(int $caseId) { return (new StoreOversightService())->acknowledgeVarianceCase($this->response, $caseId); }
}
