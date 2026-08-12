<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\StoreModel;
use App\Models\StoreSupervisorModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;

final class StoreOversightService
{
public function storesData(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = trim((string) $request->getGet('q'));
        $status = trim((string) $request->getGet('status'));
        $db = Database::connect();
        $query = $db->table('stores s')
            ->select('s.id, s.store_name, s.logo_url, s.is_active, s.created_at, s.officer_id, u.name AS officer_name, u.email AS officer_email')
            ->join('users u', 'u.id = s.officer_id', 'left');
        $role = ibems_current_role();
        $actorId = (int) session()->get('user_id');
        if ($role === 'STORE_SUPERVISOR') {
            $storeIds = (new StoreSupervisorModel())->getStoreIdsBySupervisor($actorId);
            if ($storeIds === []) {
                return $response->setJSON([
                    'status' => 'success',
                    'data' => [],
                ]);
            }
            $query->whereIn('s.id', $storeIds);
        }

        if ($q !== '') {
            $query->groupStart()
                ->like('s.store_name', $q)
                ->orLike('u.name', $q)
                ->orLike('u.email', $q)
                ->groupEnd();
        }
        if ($status === 'active') {
            $query->where('s.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('s.is_active', false);
        } elseif ($status === 'unassigned') {
            $query->where('s.officer_id IS NULL', null, false);
        }

        $rows = $query->orderBy('s.store_name', 'ASC')->get()->getResultArray();
        $supervisorsMap = $this->buildStoreSupervisorsMap(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));

        return $response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row) use ($supervisorsMap): array {
                $storeId = (int) ($row['id'] ?? 0);
                $row['supervisors'] = $supervisorsMap[$storeId] ?? [];
                $row['supervisor_ids'] = array_map(static fn(array $supervisor): int => (int) ($supervisor['id'] ?? 0), $row['supervisors']);
                return $row;
            }, $rows),
        ]);
    }

public function storeDetailsData(RequestInterface $request, ResponseInterface $response, int $storeId): ResponseInterface
    {
        if ($storeId <= 0) {
            return $response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store id.',
            ]);
        }
        if (!$this->canAccessStoreForAdminArea($storeId)) {
            return $response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $db = Database::connect();
        $store = $db->table('stores s')
            ->select('s.id, s.store_name, s.logo_url, s.is_active, s.created_at, u.name AS officer_name, u.email AS officer_email')
            ->join('users u', 'u.id = s.officer_id', 'left')
            ->where('s.id', $storeId)
            ->get()
            ->getRowArray();

        if (!$store) {
            return $response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        $summary = $db->table('transactions')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total')
            ->where('store_id', $storeId)
            ->get()
            ->getRowArray();

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todaySummary = $db->table('transactions')
            ->select("COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS sales_total, SUM(CASE WHEN payment_method = 'debt' THEN 1 ELSE 0 END) AS debt_txn_count, COALESCE(SUM(CASE WHEN payment_method = 'debt' THEN amount ELSE 0 END), 0) AS debt_sales_total", false)
            ->where('store_id', $storeId)
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->get()
            ->getRowArray();

        $inventory = $db->table('products p')
            ->select('p.id, p.name, p.stock_qty, p.price, p.image_url, p.is_active, p.low_stock_threshold')
            ->where('p.store_id', $storeId)
            ->orderBy('p.name', 'ASC')
            ->get()
            ->getResultArray();

        $inventorySummary = $db->table('products p')
            ->select('COUNT(*) AS product_count, COALESCE(SUM(CASE WHEN p.is_active = TRUE THEN 1 ELSE 0 END), 0) AS active_products, COALESCE(SUM(CASE WHEN p.is_active = TRUE AND p.stock_qty <= COALESCE(p.low_stock_threshold, 10) THEN 1 ELSE 0 END), 0) AS low_stock_count, COALESCE(SUM(p.stock_qty), 0) AS stock_units')
            ->where('p.store_id', $storeId)
            ->get()
            ->getRowArray();

        $daySession = null;
        $daySessions = [];
        $varianceCases = [];
        if ($db->tableExists('store_day_sessions')) {
            $daySessions = $db->table('store_day_sessions sds')
                ->select('sds.id, sds.store_id, sds.business_date, sds.status, sds.opening_cash, sds.opening_ecash, sds.expected_cash, sds.expected_ecash, sds.counted_cash, sds.counted_ecash, sds.variance_cash, sds.variance_ecash, sds.variance_status, sds.review_status, sds.review_note, sds.reviewed_at, sds.accountability_user_id, sds.accountability_amount, sds.closing_note, sds.opened_at, sds.closed_at, opener.name AS opened_by_name, closer.id AS closed_by_id, closer.name AS closed_by_name, reviewer.name AS reviewed_by_name, accountable.name AS accountability_user_name')
                ->join('users opener', 'opener.id = sds.opened_by', 'left')
                ->join('users closer', 'closer.id = sds.closed_by', 'left')
                ->join('users reviewer', 'reviewer.id = sds.reviewed_by', 'left')
                ->join('users accountable', 'accountable.id = sds.accountability_user_id', 'left')
                ->where('sds.store_id', $storeId)
                ->orderBy('sds.business_date', 'DESC')
                ->orderBy('sds.id', 'DESC')
                ->limit(60)
                ->get()
                ->getResultArray();
            $daySession = $daySessions[0] ?? null;
            $varianceCases = (new StoreDayVarianceCaseService())->getCasesForSessions(
                $db,
                array_column($daySessions, 'id')
            );
        }

        $recentTransactions = $db->table('transactions t')
            ->select('t.id, t.created_at, t.payment_method, t.amount, u.name AS customer_name')
            ->join('users u', 'u.id = t.user_id', 'left')
            ->where('t.store_id', $storeId)
            ->orderBy('t.id', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        $assignedOfficers = [
            [
                'name' => $store['officer_name'] ?: 'No assigned officer',
                'email' => $store['officer_email'] ?: null,
                'role' => 'Primary Store Officer',
            ],
        ];
        foreach ($this->getStoreSupervisors((int) $store['id']) as $supervisor) {
            $assignedOfficers[] = [
                'name' => $supervisor['name'] ?? 'Store Supervisor',
                'email' => $supervisor['email'] ?? null,
                'role' => 'Store Supervisor',
            ];
        }

        $normalizeDaySession = function (array $row) use ($db, $varianceCases): array {
            $sessionId = (int) ($row['id'] ?? 0);
            return [
                'id' => $sessionId,
                'business_date' => (string) ($row['business_date'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'opening_cash' => (float) ($row['opening_cash'] ?? 0),
                'opening_ecash' => (float) ($row['opening_ecash'] ?? 0),
                'expected_cash' => (float) ($row['expected_cash'] ?? 0),
                'expected_ecash' => (float) ($row['expected_ecash'] ?? 0),
                'counted_cash' => $row['counted_cash'] !== null ? (float) $row['counted_cash'] : null,
                'counted_ecash' => $row['counted_ecash'] !== null ? (float) $row['counted_ecash'] : null,
                'variance_cash' => $row['variance_cash'] !== null ? (float) $row['variance_cash'] : null,
                'variance_ecash' => $row['variance_ecash'] !== null ? (float) $row['variance_ecash'] : null,
                'variance_status' => (string) ($row['variance_status'] ?? 'balanced'),
                'review_status' => (string) ($row['review_status'] ?? 'not_required'),
                'review_note' => $row['review_note'] ?? null,
                'reviewed_at' => $row['reviewed_at'] ?? null,
                'reviewed_by_name' => $row['reviewed_by_name'] ?? null,
                'accountability_user_id' => $row['accountability_user_id'] !== null ? (int) $row['accountability_user_id'] : null,
                'accountability_user_name' => $row['accountability_user_name'] ?? null,
                'accountability_amount' => (float) ($row['accountability_amount'] ?? 0),
                'closing_note' => $row['closing_note'] ?? null,
                'opened_at' => $row['opened_at'] ?? null,
                'closed_at' => $row['closed_at'] ?? null,
                'opened_by_name' => $row['opened_by_name'] ?: null,
                'closed_by_id' => $row['closed_by_id'] !== null ? (int) $row['closed_by_id'] : null,
                'closed_by_name' => $row['closed_by_name'] ?: null,
                'variance_case' => $varianceCases[$sessionId] ?? null,
                'eligible_reviewers' => $this->eligibleReviewers(
                    $db,
                    (int) ($row['store_id'] ?? 0),
                    (int) ($row['closed_by_id'] ?? 0)
                ),
            ];
        };

        return $response->setJSON([
            'status' => 'success',
            'store' => [
                'id' => (int) $store['id'],
                'store_name' => $store['store_name'],
                'logo_url' => $store['logo_url'],
                'is_active' => ibems_bool($store['is_active']),
                'created_at' => $store['created_at'],
                'officer_name' => $store['officer_name'],
                'officer_email' => $store['officer_email'],
            ],
            'summary' => [
                'txn_count' => (int) ($summary['txn_count'] ?? 0),
                'sales_total' => (float) ($summary['sales_total'] ?? 0),
                'product_count' => (int) ($inventorySummary['product_count'] ?? count($inventory)),
                'stock_units' => (int) ($inventorySummary['stock_units'] ?? 0),
                'active_products' => (int) ($inventorySummary['active_products'] ?? 0),
                'low_stock_count' => (int) ($inventorySummary['low_stock_count'] ?? 0),
                'today_txn_count' => (int) ($todaySummary['txn_count'] ?? 0),
                'today_sales_total' => (float) ($todaySummary['sales_total'] ?? 0),
                'today_debt_txn_count' => (int) ($todaySummary['debt_txn_count'] ?? 0),
                'today_debt_sales_total' => (float) ($todaySummary['debt_sales_total'] ?? 0),
            ],
            'day_session' => $daySession ? $normalizeDaySession($daySession) : null,
            'day_sessions' => array_map($normalizeDaySession, $daySessions),
            'officers' => $assignedOfficers,
            'inventory' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'stock_qty' => (int) ($row['stock_qty'] ?? 0),
                    'price' => (float) ($row['price'] ?? 0),
                    'image_url' => $row['image_url'] ?? null,
                    'is_active' => ibems_bool($row['is_active'] ?? false),
                    'low_stock_threshold' => (int) ($row['low_stock_threshold'] ?? 10),
                ];
            }, $inventory),
            'recent_transactions' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'created_at' => $row['created_at'],
                    'payment_method' => $row['payment_method'],
                    'amount' => (float) ($row['amount'] ?? 0),
                    'customer_name' => $row['customer_name'] ?: 'Walk-in',
                ];
            }, $recentTransactions),
        ]);
    }

public function reviewStoreDayVariance(RequestInterface $request, ResponseInterface $response, int $sessionId): ResponseInterface
    {
        if ($sessionId <= 0) {
            return $response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store day session.',
            ]);
        }

        $request = $this->getRequestData($request);
        $action = strtolower(trim((string) ($request['action'] ?? '')));
        $reviewNote = trim((string) ($request['review_note'] ?? ''));
        $allowedActions = ['approve_shortage', 'waive', 'corrected', 'needs_investigation'];
        if (!in_array($action, $allowedActions, true)) {
            return $response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid review action.',
            ]);
        }
        if ($reviewNote === '') {
            return $response->setStatusCode(422)->setJSON([
                'status' => 'error',
                'message' => 'Review note is required.',
            ]);
        }

        $db = Database::connect();
        if (!$db->tableExists('store_day_sessions')) {
            return $response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store day sessions are not available.',
            ]);
        }

        $session = $db->table('store_day_sessions sds')
            ->select('sds.*, s.store_name, closer.name AS closed_by_name')
            ->join('stores s', 's.id = sds.store_id', 'left')
            ->join('users closer', 'closer.id = sds.closed_by', 'left')
            ->where('sds.id', $sessionId)
            ->get()
            ->getRowArray();

        if (!$session) {
            return $response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store day session not found.',
            ]);
        }
        if (!$this->canAccessStoreForAdminArea((int) ($session['store_id'] ?? 0))) {
            return $response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot review this store day.',
            ]);
        }
        if ((string) ($session['status'] ?? '') !== 'closed') {
            return $response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'Only closed store days can be reviewed.',
            ]);
        }
        if ((string) ($session['review_status'] ?? 'not_required') === 'not_required') {
            return $response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'This store day has no variance requiring review.',
            ]);
        }
        if (in_array((string) ($session['review_status'] ?? ''), ['approved', 'waived', 'corrected'], true)) {
            return $response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'This variance has already been finalized.',
            ]);
        }

        $actorId = (int) session()->get('user_id');
        if (!(new StoreDayReviewPolicy())->isIndependentReviewer($actorId, $session)) {
            $eligibleReviewers = $this->eligibleReviewers(
                $db,
                (int) ($session['store_id'] ?? 0),
                (int) ($session['closed_by'] ?? 0)
            );
            $eligibleNames = array_column($eligibleReviewers, 'name');
            return $response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'message' => 'The operator who closed a store day cannot review the same store day.'
                    . ($eligibleNames !== [] ? ' Eligible reviewers: ' . implode(', ', $eligibleNames) . '.' : ' No eligible reviewer is currently assigned.'),
                'eligible_reviewers' => $eligibleReviewers,
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $varianceCash = (float) ($session['variance_cash'] ?? 0);
        $varianceEcash = (float) ($session['variance_ecash'] ?? 0);
        $shortageAmount = round(max(0, -$varianceCash) + max(0, -$varianceEcash), 2);
        $statusMap = [
            'approve_shortage' => 'approved',
            'waive' => 'waived',
            'corrected' => 'corrected',
            'needs_investigation' => 'needs_investigation',
        ];
        $newReviewStatus = $statusMap[$action];
        $accountabilityUserId = null;
        $accountabilityAmount = 0.0;

        if ($action === 'approve_shortage') {
            if ((string) ($session['variance_status'] ?? '') !== 'shortage' || $shortageAmount <= 0) {
                return $response->setStatusCode(422)->setJSON([
                    'status' => 'error',
                    'message' => 'Only shortage variances can be approved as operator accountability.',
                ]);
            }
            $accountabilityUserId = (int) ($session['closed_by'] ?? 0);
            if ($accountabilityUserId <= 0) {
                return $response->setStatusCode(422)->setJSON([
                    'status' => 'error',
                    'message' => 'No closing operator is attached to this store day.',
                ]);
            }
            $accountabilityAmount = $shortageAmount;
        }

        $db->transStart();

        (new StoreDayVarianceCaseService())->recordReview(
            $db,
            $session,
            $actorId,
            $action,
            $newReviewStatus,
            $reviewNote,
            $now
        );

        if ($action === 'approve_shortage') {
            $balanceModel = new BalanceModel();
            $balance = $balanceModel->where('user_id', $accountabilityUserId)->first();
            if (!$balance) {
                $balanceModel->insert([
                    'user_id' => $accountabilityUserId,
                    'credit_limit' => 0,
                    'current_debt' => 0,
                    'updated_at' => $now,
                ]);
                $balance = $balanceModel->find($accountabilityUserId);
            }

            $debtBefore = (float) ($balance['current_debt'] ?? 0);
            $creditLimit = (float) ($balance['credit_limit'] ?? 0);
            $debtAfter = round($debtBefore + $shortageAmount, 2);
            $balanceModel->update($accountabilityUserId, [
                'current_debt' => $debtAfter,
                'updated_at' => $now,
            ]);

            (new DebtCashbookEntryModel())->insert([
                'user_id' => $accountabilityUserId,
                'entry_type' => 'operator_shortage',
                'direction' => 'debit',
                'amount' => $shortageAmount,
                'debt_before' => $debtBefore,
                'debt_after' => $debtAfter,
                'credit_limit_snapshot' => $creditLimit,
                'available_credit_snapshot' => max(0, $creditLimit - $debtAfter),
                'reference_type' => 'store_day_session',
                'reference_id' => $sessionId,
                'actor_id' => $actorId > 0 ? $actorId : null,
                'remarks' => 'Approved store day cash shortage. ' . $reviewNote,
                'meta_json' => json_encode([
                    'store_id' => (int) ($session['store_id'] ?? 0),
                    'store_name' => (string) ($session['store_name'] ?? ''),
                    'business_date' => (string) ($session['business_date'] ?? ''),
                    'variance_cash' => $varianceCash,
                    'variance_ecash' => $varianceEcash,
                    'source' => 'store_variance_review',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $db->table('store_day_sessions')
            ->where('id', $sessionId)
            ->update([
                'review_status' => $newReviewStatus,
                'reviewed_by' => $actorId > 0 ? $actorId : null,
                'reviewed_at' => $now,
                'review_note' => $reviewNote,
                'accountability_user_id' => $accountabilityUserId,
                'accountability_amount' => $accountabilityAmount,
                'updated_at' => $now,
            ]);

        (new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action === 'approve_shortage' ? 'ADMIN_APPROVE_STORE_SHORTAGE' : 'ADMIN_REVIEW_STORE_VARIANCE',
            'entity' => 'store_day_sessions',
            'entity_id' => $sessionId,
            'payload_json' => json_encode([
                'review_action' => $action,
                'review_status' => $newReviewStatus,
                'store_id' => (int) ($session['store_id'] ?? 0),
                'store_name' => (string) ($session['store_name'] ?? ''),
                'business_date' => (string) ($session['business_date'] ?? ''),
                'variance_cash' => $varianceCash,
                'variance_ecash' => $varianceEcash,
                'accountability_user_id' => $accountabilityUserId,
                'accountability_amount' => $accountabilityAmount,
            ]),
            'created_at' => $now,
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to review store day variance.',
            ]);
        }

        return $response->setJSON([
            'status' => 'success',
            'review_status' => $newReviewStatus,
            'accountability_user_id' => $accountabilityUserId,
            'accountability_amount' => $accountabilityAmount,
        ]);
    }

    private function getRequestData(RequestInterface $request): array
    {
        $json = $request->getJSON(true);
        if (is_array($json)) {
            return $json;
        }

        return (array) $request->getPost();
    }

    public function uploadVarianceCaseAttachment(RequestInterface $request, ResponseInterface $response, int $caseId): ResponseInterface
    {
        $db = Database::connect();
        $case = $this->accessibleVarianceCase($db, $caseId);
        if (!$case) return $response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Variance case not found or inaccessible.']);

        $file = $request->getFile('evidence_file');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->hasMoved()) {
            return $response->setStatusCode(422)->setJSON(['status' => 'error', 'message' => 'A valid evidence file is required.']);
        }
        $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $mime = strtolower((string) $file->getMimeType());
        if (!isset($allowed[$mime]) || $file->getSize() <= 0 || $file->getSize() > 5 * 1024 * 1024) {
            return $response->setStatusCode(422)->setJSON(['status' => 'error', 'message' => 'Evidence must be a PDF, JPG, or PNG file up to 5 MB.']);
        }

        $actorId = (int) session()->get('user_id');
        $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
        $directory = WRITEPATH . 'private/variance-evidence/' . $caseId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return $response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Evidence storage is unavailable.']);
        }
        $file->move($directory, $storedName);
        $path = $directory . DIRECTORY_SEPARATOR . $storedName;
        $now = date('Y-m-d H:i:s');
        $description = mb_substr(trim((string) $request->getPost('description')), 0, 255);
        $db->transStart();
        $db->table('store_day_variance_case_attachments')->insert([
            'case_id' => $caseId, 'uploaded_by' => $actorId, 'original_name' => basename((string) $file->getClientName()),
            'stored_name' => $storedName, 'mime_type' => $mime, 'file_size' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'description' => $description !== '' ? $description : null, 'created_at' => $now,
        ]);
        $attachmentId = (int) $db->insertID();
        $this->appendVarianceEvent($db, $caseId, $actorId, 'evidence_attached', $description ?: 'Evidence file attached.', ['attachment_id' => $attachmentId, 'mime_type' => $mime, 'file_size' => filesize($path)], $now);
        $db->transComplete();
        if (!$db->transStatus()) { @unlink($path); return $response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Failed to record evidence.']); }
        return $response->setJSON(['status' => 'success', 'attachment_id' => $attachmentId]);
    }

    public function downloadVarianceCaseAttachment(ResponseInterface $response, int $attachmentId): ResponseInterface
    {
        $db = Database::connect();
        $attachment = $db->table('store_day_variance_case_attachments a')->select('a.*, c.store_id')->join('store_day_variance_cases c', 'c.id = a.case_id')->where('a.id', $attachmentId)->get()->getRowArray();
        if (!$attachment || !$this->canAccessStoreForAdminArea((int) $attachment['store_id'])) return $response->setStatusCode(404)->setBody('Evidence not found.');
        $path = WRITEPATH . 'private/variance-evidence/' . (int) $attachment['case_id'] . DIRECTORY_SEPARATOR . basename((string) $attachment['stored_name']);
        if (!is_file($path) || !hash_equals((string) $attachment['sha256'], hash_file('sha256', $path))) return $response->setStatusCode(410)->setBody('Evidence file is missing or failed integrity verification.');
        return $response->download($path, null)->setFileName((string) $attachment['original_name']);
    }

    public function handoffVarianceCase(RequestInterface $request, ResponseInterface $response, int $caseId): ResponseInterface
    {
        $db = Database::connect(); $case = $this->accessibleVarianceCase($db, $caseId);
        if (!$case) return $response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Variance case not found or inaccessible.']);
        $data = $this->getRequestData($request); $toUserId = (int) ($data['to_user_id'] ?? 0); $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
        $eligible = array_column($this->eligibleReviewers($db, (int) $case['store_id'], (int) $case['closed_by']), 'id');
        $actorId = (int) session()->get('user_id'); $now = date('Y-m-d H:i:s');
        if ($toUserId === $actorId || !in_array($toUserId, $eligible, true) || $note === '') return $response->setStatusCode(422)->setJSON(['status' => 'error', 'message' => 'Choose a different eligible independent reviewer and provide a handoff note.']);
        $db->transStart();
        $db->table('store_day_variance_case_handoffs')->insert(['case_id' => $caseId, 'from_user_id' => $actorId, 'to_user_id' => $toUserId, 'note' => $note, 'status' => 'pending', 'created_at' => $now]);
        $db->table('store_day_variance_cases')->where('id', $caseId)->update(['owner_user_id' => $toUserId, 'updated_at' => $now]);
        $this->appendVarianceEvent($db, $caseId, $actorId, 'reviewer_handoff', $note, ['to_user_id' => $toUserId], $now);
        $db->transComplete();
        if (!$db->transStatus()) return $response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Failed to record reviewer handoff.']);
        return $response->setJSON(['status' => 'success']);
    }

    private function accessibleVarianceCase($db, int $caseId): ?array
    {
        $case = $db->table('store_day_variance_cases c')->select('c.*, sds.closed_by')->join('store_day_sessions sds', 'sds.id = c.store_day_session_id')->where('c.id', $caseId)->get()->getRowArray();
        return $case && $this->canAccessStoreForAdminArea((int) $case['store_id']) ? $case : null;
    }

    private function appendVarianceEvent($db, int $caseId, int $actorId, string $type, string $note, array $evidence, string $now): void
    {
        $db->table('store_day_variance_case_events')->insert(['case_id' => $caseId, 'actor_id' => $actorId, 'event_type' => $type, 'note' => $note, 'evidence_json' => json_encode($evidence), 'created_at' => $now]);
    }

    private function eligibleReviewers($db, int $storeId, int $closingOperatorId): array
    {
        $ids = [];
        foreach ($db->table('users')->select('id')->where('is_active', true)->where('role', 'ADMIN')->get()->getResultArray() as $row) {
            $ids[(int) $row['id']] = true;
        }
        if ($db->tableExists('user_roles')) {
            foreach ($db->table('user_roles')->select('user_id')->where('role', 'ADMIN')->get()->getResultArray() as $row) {
                $ids[(int) $row['user_id']] = true;
            }
        }
        if ($db->tableExists('store_supervisors')) {
            foreach ($db->table('store_supervisors')->select('user_id')->where('store_id', $storeId)->get()->getResultArray() as $row) {
                $ids[(int) $row['user_id']] = true;
            }
        }
        unset($ids[$closingOperatorId], $ids[0]);
        if ($ids === []) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
        ], $db->table('users')->select('id, name, email')->where('is_active', true)->whereIn('id', array_keys($ids))->orderBy('name', 'ASC')->get()->getResultArray());
    }

private function canAccessStoreForAdminArea(int $storeId): bool
    {
        $role = ibems_current_role();
        if ($role === 'ADMIN') {
            return true;
        }
        if ($role !== 'STORE_SUPERVISOR') {
            return false;
        }

        return (new StoreModel())->canUserAccessStore((int) session()->get('user_id'), $role, $storeId);
    }

private function getStoreSupervisors(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }

        $db = Database::connect();
        if (!$db->tableExists('store_supervisors')) {
            return [];
        }

        return $db->table('store_supervisors ss')
            ->select('u.id, u.name, u.email, u.employee_id')
            ->join('users u', 'u.id = ss.user_id', 'inner')
            ->where('ss.store_id', $storeId)
            ->where('u.is_active', true)
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();
    }

private function buildStoreSupervisorsMap(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn(int $id): bool => $id > 0)));
        if ($storeIds === []) {
            return [];
        }

        $db = Database::connect();
        if (!$db->tableExists('store_supervisors')) {
            return [];
        }

        $rows = $db->table('store_supervisors ss')
            ->select('ss.store_id, u.id, u.name, u.email, u.employee_id')
            ->join('users u', 'u.id = ss.user_id', 'inner')
            ->whereIn('ss.store_id', $storeIds)
            ->where('u.is_active', true)
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $storeId = (int) ($row['store_id'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }
            if (!isset($map[$storeId])) {
                $map[$storeId] = [];
            }
            $map[$storeId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'employee_id' => $row['employee_id'] ?? null,
            ];
        }

        return $map;
    }
}
