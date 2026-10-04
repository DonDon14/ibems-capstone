<?php

namespace App\Services;

use App\Models\StoreModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/** Versioned daily-report handoff; recorded transaction data is frozen at submission. */
final class DailyStoreReportService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array<string,mixed> */
    public function submit(int $sessionId, int $actorId, string $role, string $note = ''): array
    {
        $preview = $this->preview($sessionId, $actorId, $role);
        if ($preview['status'] !== 'success') {
            return $preview;
        }
        $session = $preview['session'];
        $storeId = (int) $preview['store_id'];
        $date = (string) $preview['business_date'];
        $storeSales = $preview['totals'];
        $evidence = $preview['evidence'];
        $latest = $this->db->table('daily_store_reports')->where('store_id', $storeId)
            ->where('business_date', $date)->orderBy('revision', 'DESC')->get(1)->getRowArray();
        if ($latest && $latest['status'] !== 'be_returned') {
            return $this->error('A current report for this store day has already been submitted.', 409);
        }

        $now = ibems_now()->format('Y-m-d H:i:s');
        $source = [
            'session_id' => $sessionId,
            'closed_at' => $session['closed_at'] ?? null,
            'closed_by' => $session['closed_by'] ?? null,
            'expected_cash' => $session['expected_cash'] ?? null,
            'expected_ecash' => $session['expected_ecash'] ?? null,
            'counted_cash' => $session['counted_cash'] ?? null,
            'counted_ecash' => $session['counted_ecash'] ?? null,
            'variance_cash' => $session['variance_cash'] ?? null,
            'variance_ecash' => $session['variance_ecash'] ?? null,
            'review_status' => $session['review_status'] ?? null,
            'sales_evidence' => $evidence,
        ];
        $this->db->transBegin();
        try {
            $this->db->table('daily_store_reports')->insert([
                'store_day_session_id' => $sessionId,
                'store_id' => $storeId,
                'business_date' => $date,
                'revision' => (int) ($latest['revision'] ?? 0) + 1,
                'status' => 'submitted',
                'transaction_count' => (int) $storeSales['transactions'],
                'sales_total' => $storeSales['sales_cents'] / 100,
                'cash_total' => $storeSales['cash_cents'] / 100,
                'debt_total' => $storeSales['debt_cents'] / 100,
                'other_total' => $storeSales['other_cents'] / 100,
                'source_json' => json_encode($source, JSON_THROW_ON_ERROR),
                'submission_note' => trim($note) ?: null,
                'submitted_by' => $actorId,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            if ($id <= 0) {
                throw new \RuntimeException('Report insert failed.');
            }
            $this->event($id, 'submitted', $actorId, trim($note) ?: null, $now);
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Report transaction failed.');
            }
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 201, 'report' => $this->find($id)];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error('Daily report could not be submitted. Reload and retry.', 409);
        }
    }

    /** Read-only calculation used again at submission; never grants a posting authority. @return array<string,mixed> */
    public function preview(int $sessionId, int $actorId, string $role): array
    {
        if ($actorId <= 0 || $role !== 'STORE_SUPERVISOR') {
            return $this->error('Only an assigned Store Supervisor may submit a daily report.', 403);
        }
        $session = $this->db->table('store_day_sessions')->where('id', $sessionId)->get()->getRowArray();
        if (!$session || $session['status'] !== 'closed') {
            return $this->error('Close the store day before submitting its report.', 409);
        }
        $storeId = (int) $session['store_id'];
        if (!(new StoreModel())->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->error('You are not assigned to this store.', 403);
        }

        $date = (string) $session['business_date'];
        $sales = (new CashierOfficeSalesService($this->db))->daily($date);
        $storeSales = null;
        foreach ($sales['stores'] as $row) {
            if ((int) $row['id'] === $storeId) {
                $storeSales = $row;
                break;
            }
        }
        if ($storeSales === null) {
            return $this->error('Store sales could not be reconciled to the closed day.', 409);
        }
        $evidence = $this->salesEvidence($storeId, $date);
        if ($evidence['transaction_count'] !== (int) $storeSales['transactions']
            || $evidence['sales_cents'] !== (int) $storeSales['sales_cents']
            || $evidence['payment_cents'] !== $evidence['sales_cents']
            || $evidence['unbalanced_transaction_ids'] !== []
            || (int) $storeSales['cash_cents'] + (int) $storeSales['debt_cents'] + (int) $storeSales['other_cents'] !== (int) $storeSales['sales_cents']) {
            return $this->error('Sales and payment records do not reconcile. Correct the source records before submitting.', 409);
        }
        return [
            'status' => 'success',
            'code' => 200,
            'session' => $session,
            'store_id' => $storeId,
            'business_date' => $date,
            'totals' => $storeSales,
            'evidence' => $evidence,
        ];
    }

    /** @return array<string,mixed> */
    public function review(int $reportId, int $actorId, bool $approve, string $note, string $role): array
    {
        $note = trim($note);
        if ($role !== 'BE_DIRECTOR') {
            return $this->error('Only the BE Director may review store reports.', 403);
        }
        if ($actorId <= 0 || (!$approve && $note === '')) {
            return $this->error('A reviewer and a reason for return are required.', 400);
        }
        $report = $this->find($reportId);
        if ($report && (int) $report['submitted_by'] === $actorId) {
            return $this->error('The report submitter cannot review the same report.', 403);
        }
        if ($approve && $report && $this->sourceStatus($report) !== 'verified') {
            return $this->error('Source sales changed after submission. Return this report for a new revision before approving.', 409);
        }
        $correctingApproved = !$approve && $report
            && in_array($report['status'], ['be_approved', 'cashier_received'], true);
        if ($correctingApproved && $this->sourceStatus($report) === 'verified') {
            return $this->error('An approved report can be returned for correction only when its source cannot be verified.', 409);
        }
        $now = ibems_now()->format('Y-m-d H:i:s');
        $status = $approve ? 'be_approved' : 'be_returned';
        $this->db->transBegin();
        try {
            $changes = ['status' => $status, 'updated_at' => $now];
            if (!$correctingApproved) {
                $changes += [
                    'be_reviewed_by' => $actorId,
                    'be_reviewed_at' => $now,
                    'be_review_note' => $note ?: null,
                    'accounting_furnished_at' => $approve ? $now : null,
                ];
            }
            $this->db->table('daily_store_reports')->where('id', $reportId)
                ->where('status', $correctingApproved ? $report['status'] : 'submitted')->update($changes);
            if ($this->db->affectedRows() !== 1) {
                throw new \DomainException('This report is no longer eligible for the requested BE decision.');
            }
            $this->event($reportId, $status, $actorId, $note ?: null, $now);
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 200, 'report' => $this->find($reportId)];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error($e instanceof \DomainException ? $e->getMessage() : 'Review failed.', 409);
        }
    }

    /** @return array<string,mixed> */
    public function receive(int $reportId, int $actorId, string $role): array
    {
        if (!in_array($role, ['CASHIER_HEAD', 'COLLECTING_OFFICER'], true)) {
            return $this->error('Only the School Cashier Office may receive reports.', 403);
        }
        if ($actorId <= 0) {
            return $this->error('A receiving Cashier Office user is required.', 400);
        }
        $report = $this->find($reportId);
        if ($report && $report['status'] === 'be_approved' && $this->sourceStatus($report) !== 'verified') {
            return $this->error('Source sales changed after BE approval. Ask the BE Director to return this report for correction.', 409);
        }
        $now = ibems_now()->format('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $this->db->table('daily_store_reports')->where('id', $reportId)->where('status', 'be_approved')->update([
                'status' => 'cashier_received',
                'cashier_received_by' => $actorId,
                'cashier_received_at' => $now,
                'updated_at' => $now,
            ]);
            if ($this->db->affectedRows() !== 1) {
                throw new \DomainException('Only a BE-approved report may be received.');
            }
            $this->event($reportId, 'cashier_received', $actorId, null, $now);
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 200, 'report' => $this->find($reportId)];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error($e instanceof \DomainException ? $e->getMessage() : 'Receipt failed.', 409);
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $reportId): ?array
    {
        return $this->db->table('daily_store_reports')->where('id', $reportId)->get()->getRowArray();
    }

    /** @return array{transaction_count:int,sales_cents:int,payment_cents:int,cash_cents:int,debt_cents:int,other_cents:int,unbalanced_transaction_ids:list<int>,sha256:string} */
    private function salesEvidence(int $storeId, string $date): array
    {
        $bounds = ibems_business_day_utc_bounds($date);
        $rows = $this->db->table('transactions t')
            ->select('t.id AS transaction_id, t.amount AS sale_amount, t.payment_method AS sale_method, tp.id AS payment_id, tp.payment_method AS payment_method, tp.amount AS payment_amount')
            ->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left')
            ->where('t.store_id', $storeId)->where('t.status', 'completed')
            ->where('t.created_at >=', $bounds['start'])->where('t.created_at <=', $bounds['end'])
            ->orderBy('t.id', 'ASC')->orderBy('tp.id', 'ASC')->get()->getResultArray();
        $salesCents = $paymentCents = 0;
        $tenders = ['cash_cents' => 0, 'debt_cents' => 0, 'other_cents' => 0];
        $perTransaction = [];
        $hash = hash_init('sha256');
        foreach ($rows as $row) {
            $id = (int) $row['transaction_id'];
            $saleCents = (int) round((float) $row['sale_amount'] * 100);
            $hasPayment = $row['payment_id'] !== null;
            $tenderCents = $hasPayment ? (int) round((float) $row['payment_amount'] * 100) : $saleCents;
            if (!isset($perTransaction[$id])) {
                $perTransaction[$id] = ['sale' => $saleCents, 'paid' => 0];
                $salesCents += $saleCents;
            }
            $perTransaction[$id]['paid'] += $tenderCents;
            $paymentCents += $tenderCents;
            $method = strtolower((string) ($hasPayment ? $row['payment_method'] : $row['sale_method']));
            $bucket = $method === 'cash' ? 'cash_cents' : ($method === 'debt' ? 'debt_cents' : 'other_cents');
            $tenders[$bucket] += $tenderCents;
            hash_update($hash, implode('|', [
                $id, $saleCents, $hasPayment ? (int) $row['payment_id'] : 0,
                $method, $tenderCents,
            ]) . "\n");
        }
        $unbalanced = [];
        foreach ($perTransaction as $id => $amounts) {
            if ($amounts['sale'] !== $amounts['paid']) {
                $unbalanced[] = (int) $id;
            }
        }
        return [
            'transaction_count' => count($perTransaction),
            'sales_cents' => $salesCents,
            'payment_cents' => $paymentCents,
            'cash_cents' => $tenders['cash_cents'],
            'debt_cents' => $tenders['debt_cents'],
            'other_cents' => $tenders['other_cents'],
            'unbalanced_transaction_ids' => $unbalanced,
            'sha256' => hash_final($hash),
        ];
    }

    /** @param array<string,mixed> $report @return 'verified'|'changed'|'unverifiable' */
    public function sourceStatus(array $report): string
    {
        $source = json_decode((string) ($report['source_json'] ?? ''), true);
        $submitted = is_array($source) ? ($source['sales_evidence'] ?? null) : null;
        if (!is_array($submitted) || !isset($submitted['sha256'])) {
            return 'unverifiable';
        }
        $current = $this->salesEvidence((int) $report['store_id'], (string) $report['business_date']);
        $matches = $current['unbalanced_transaction_ids'] === []
            && hash_equals((string) $submitted['sha256'], $current['sha256'])
            && $current['transaction_count'] === (int) $report['transaction_count']
            && $current['sales_cents'] === (int) round((float) $report['sales_total'] * 100)
            && $current['cash_cents'] === (int) round((float) $report['cash_total'] * 100)
            && $current['debt_cents'] === (int) round((float) $report['debt_total'] * 100)
            && $current['other_cents'] === (int) round((float) $report['other_total'] * 100);
        return $matches ? 'verified' : 'changed';
    }

    private function event(int $reportId, string $type, int $actorId, ?string $note, string $now): void
    {
        if (!$this->db->table('daily_store_report_events')->insert([
            'report_id' => $reportId,
            'event_type' => $type,
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => $now,
        ])) {
            throw new \RuntimeException('Report event insert failed.');
        }
    }

    /** @return array{status:string,code:int,message:string} */
    private function error(string $message, int $code): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }
}
