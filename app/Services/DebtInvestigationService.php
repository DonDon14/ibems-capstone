<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\DebtInvestigationModel;
use App\Models\TransactionModel;
use Config\Database;
use Throwable;

class DebtInvestigationService
{
    public const ISSUE_TYPES = ['incorrect_amount', 'unauthorized_purchase', 'duplicate_charge', 'wrong_employee', 'other'];
    public const ACTIONS = ['no_change', 'partial_reversal', 'full_reversal'];

    /** @return array{status:string,investigations:array,transactions:array} */
    public function data(int $userId = 0): array
    {
        $db = Database::connect();
        $investigations = $db->table('debt_investigations di')
            ->select('di.*, u.employee_id, u.name, t.client_txn_id, opener.name AS opened_by_name, recommender.name AS recommended_by_name, approver.name AS approved_by_name')
            ->join('users u', 'u.id = di.user_id', 'inner')
            ->join('transactions t', 't.id = di.transaction_id', 'left')
            ->join('users opener', 'opener.id = di.opened_by', 'left')
            ->join('users recommender', 'recommender.id = di.recommended_by', 'left')
            ->join('users approver', 'approver.id = di.approved_by', 'left')
            ->orderBy('di.id', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        $transactions = [];
        if ($userId > 0) {
            $transactions = $db->table('transactions')
                ->select('id, client_txn_id, amount, store_id, created_at')
                ->where('user_id', $userId)
                ->where('payment_method', 'debt')
                ->orderBy('id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();
        }

        return ['status' => 'success', 'investigations' => $investigations, 'transactions' => $transactions];
    }

    public function open(array $input, int $actorId): array
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $transactionId = (int) ($input['transaction_id'] ?? 0);
        $issueType = strtolower(trim((string) ($input['issue_type'] ?? '')));
        $summary = trim((string) ($input['summary'] ?? ''));
        if ($userId <= 0 || !in_array($issueType, self::ISSUE_TYPES, true) || mb_strlen($summary) < 10) {
            return $this->error('Employee, valid issue type, and a summary of at least 10 characters are required.');
        }

        if ($transactionId > 0) {
            $transaction = (new TransactionModel())->find($transactionId);
            if (!$transaction || (int) ($transaction['user_id'] ?? 0) !== $userId || ($transaction['payment_method'] ?? '') !== 'debt') {
                return $this->error('The selected transaction is not a debt purchase for this employee.');
            }
        }

        $now = date('Y-m-d H:i:s');
        $model = new DebtInvestigationModel();
        $id = $model->insert([
            'user_id' => $userId,
            'transaction_id' => $transactionId > 0 ? $transactionId : null,
            'status' => 'open',
            'issue_type' => $issueType,
            'summary' => $summary,
            'evidence_summary' => trim((string) ($input['evidence_summary'] ?? '')) ?: null,
            'opened_by' => $actorId > 0 ? $actorId : null,
            'investigator_id' => $actorId > 0 ? $actorId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$id) {
            return $this->error('Failed to open debt investigation.', 500);
        }
        $this->audit('OPEN_DEBT_INVESTIGATION', (int) $id, $actorId, ['user_id' => $userId, 'transaction_id' => $transactionId ?: null]);

        return ['status' => 'success', 'code' => 201, 'investigation' => $model->find((int) $id)];
    }

    public function recommend(int $investigationId, array $input, int $actorId): array
    {
        $model = new DebtInvestigationModel();
        $row = $model->find($investigationId);
        $action = strtolower(trim((string) ($input['recommended_action'] ?? '')));
        $amount = round((float) ($input['recommended_amount'] ?? 0), 2);
        $findings = trim((string) ($input['findings'] ?? ''));
        $evidence = trim((string) ($input['evidence_summary'] ?? ''));
        if (!$row || !in_array((string) ($row['status'] ?? ''), ['open', 'investigating'], true)) {
            return $this->error('Investigation is unavailable for recommendation.', 409);
        }
        if (!in_array($action, self::ACTIONS, true) || mb_strlen($findings) < 10 || $evidence === '') {
            return $this->error('Valid action, findings of at least 10 characters, and evidence summary are required.');
        }

        $transactionAmount = null;
        if (!empty($row['transaction_id'])) {
            $transaction = (new TransactionModel())->find((int) $row['transaction_id']);
            $transactionAmount = (float) ($transaction['amount'] ?? 0);
        }
        if ($action === 'no_change') {
            $amount = 0;
        } elseif ($amount <= 0 || ($transactionAmount !== null && $amount > $transactionAmount)) {
            return $this->error('Recommended reversal must be positive and cannot exceed the source transaction.');
        }

        $now = date('Y-m-d H:i:s');
        $model->update($investigationId, [
            'status' => 'recommended',
            'evidence_summary' => $evidence,
            'findings' => $findings,
            'recommended_action' => $action,
            'recommended_amount' => $amount,
            'recommended_by' => $actorId > 0 ? $actorId : null,
            'recommended_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit('RECOMMEND_DEBT_INVESTIGATION', $investigationId, $actorId, ['action' => $action, 'amount' => $amount]);

        return ['status' => 'success', 'code' => 200, 'investigation' => $model->find($investigationId)];
    }

    public function approveAndPost(int $investigationId, int $actorId): array
    {
        $model = new DebtInvestigationModel();
        $row = $model->find($investigationId);
        if (!$row || ($row['status'] ?? '') !== 'recommended') {
            return $this->error('Only a recommended investigation may be approved.', 409);
        }
        if ((int) ($row['recommended_by'] ?? 0) === $actorId) {
            return $this->error('A different Accounting user must approve this recommendation.', 403);
        }

        $action = (string) ($row['recommended_action'] ?? '');
        $amount = round((float) ($row['recommended_amount'] ?? 0), 2);
        $balanceModel = new BalanceModel();
        $balance = $balanceModel->getBalanceByUserId((int) $row['user_id']);
        if (!$balance) {
            return $this->error('Employee debt balance was not found.', 404);
        }
        $before = round((float) $balance['current_debt'], 2);
        if ($amount > $before) {
            return $this->error('Recommended reversal exceeds the employee current debt and must be investigated again.', 409);
        }

        $db = Database::connect();
        $db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $after = round(max(0, $before - $amount), 2);
            $cashbookId = null;
            if ($action !== 'no_change' && $amount > 0) {
                if (!$balanceModel->update((int) $row['user_id'], ['current_debt' => $after, 'updated_at' => $now])) {
                    throw new \RuntimeException('Failed to update employee debt.');
                }
                $cashbookId = (new DebtCashbookEntryModel())->insert([
                    'user_id' => (int) $row['user_id'],
                    'entry_type' => 'investigation_reversal',
                    'direction' => 'credit',
                    'amount' => $amount,
                    'debt_before' => $before,
                    'debt_after' => $after,
                    'credit_limit_snapshot' => (float) ($balance['credit_limit'] ?? 0),
                    'available_credit_snapshot' => max(0, (float) ($balance['credit_limit'] ?? 0) - $after),
                    'reference_type' => 'debt_investigation',
                    'reference_id' => $investigationId,
                    'actor_id' => $actorId,
                    'remarks' => 'Accounting-approved debt investigation reversal',
                    'meta_json' => json_encode(['transaction_id' => $row['transaction_id'] ?? null, 'recommended_by' => $row['recommended_by']]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if (!$cashbookId) {
                    throw new \RuntimeException('Failed to post reversal entry.');
                }
            }

            $model->update($investigationId, [
                'status' => 'closed',
                'approved_by' => $actorId,
                'posted_by' => $actorId,
                'reversal_cashbook_entry_id' => $cashbookId,
                'approved_at' => $now,
                'posted_at' => $now,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit('APPROVE_AND_POST_DEBT_REVERSAL', $investigationId, $actorId, [
                'action' => $action, 'amount' => $amount, 'debt_before' => $before, 'debt_after' => $after, 'cashbook_entry_id' => $cashbookId,
            ]);
            $db->transCommit();

            return ['status' => 'success', 'code' => 200, 'investigation' => $model->find($investigationId), 'debt_before' => $before, 'debt_after' => $after];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Debt investigation posting failed: {message}', ['message' => $e->getMessage()]);
            return $this->error('Failed to approve and post debt reversal.', 500);
        }
    }

    private function audit(string $action, int $id, int $actorId, array $payload): void
    {
        if (!(new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null, 'action' => $action,
            'entity' => 'debt_investigations', 'entity_id' => $id,
            'payload_json' => json_encode($payload), 'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new \RuntimeException('Failed to write debt investigation audit.');
        }
    }

    private function error(string $message, int $code = 400): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }
}
