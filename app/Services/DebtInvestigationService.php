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
                ->whereIn('payment_method', ['debt', 'split'])
                ->orderBy('id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();
            $transactions = array_values(array_filter(array_map(function (array $transaction) use ($userId): ?array {
                $debt = $this->sourceDebtCeiling((int) $transaction['id'], $userId);
                if ($debt === null) return null;
                $transaction['amount'] = $debt;
                return $transaction;
            }, $transactions)));
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
            if ($this->sourceDebtCeiling($transactionId, $userId) === null) {
                return $this->error('The selected transaction is not a debt purchase for this employee.');
            }
        }

        $now = ibems_now()->format('Y-m-d H:i:s');
        $model = new DebtInvestigationModel();
        $db = Database::connect();
        $db->transBegin();
        try {
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
            throw new \RuntimeException('Failed to open debt investigation.');
        }
        $this->audit('OPEN_DEBT_INVESTIGATION', (int) $id, $actorId, ['user_id' => $userId, 'transaction_id' => $transactionId ?: null]);
        if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Investigation commit failed.');
        } catch (Throwable $exception) {
            $db->transRollback();
            return $this->error('Failed to open debt investigation.', 500);
        }

        return ['status' => 'success', 'code' => 201, 'investigation' => $model->find((int) $id)];
    }

    public function recommend(int $investigationId, array $input, int $actorId): array
    {
        $model = new DebtInvestigationModel();
        $row = $model->find($investigationId);
        $action = strtolower(trim((string) ($input['recommended_action'] ?? '')));
        $rawAmount = $input['recommended_amount'] ?? 0;
        $amount = (is_string($rawAmount) || is_int($rawAmount) || is_float($rawAmount))
            && preg_match('/^\d+(?:\.\d{1,2})?$/D', trim((string) $rawAmount)) === 1
            ? (float) $rawAmount : null;
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
            $transactionAmount = $this->sourceDebtCeiling((int) $row['transaction_id'], (int) $row['user_id']) ?? 0.0;
        }
        if ($action === 'no_change') {
            $amount = 0;
        } elseif ($amount === null || !is_finite($amount) || $amount <= 0 || ($transactionAmount !== null && $amount > $transactionAmount)) {
            return $this->error('Recommended reversal must be positive and cannot exceed the source transaction.');
        }

        $now = ibems_now()->format('Y-m-d H:i:s');
        $db = Database::connect();
        $db->transBegin();
        try {
        $updated = $db->table('debt_investigations')->where('id', $investigationId)->whereIn('status', ['open', 'investigating'])->update([
            'status' => 'recommended',
            'evidence_summary' => $evidence,
            'findings' => $findings,
            'recommended_action' => $action,
            'recommended_amount' => $amount,
            'recommended_by' => $actorId > 0 ? $actorId : null,
            'recommended_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$updated || $db->affectedRows() !== 1) {
            $db->transRollback();
            return $this->error('Investigation is unavailable for recommendation.', 409);
        }
        $this->audit('RECOMMEND_DEBT_INVESTIGATION', $investigationId, $actorId, ['action' => $action, 'amount' => $amount]);
        if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Recommendation commit failed.');
        } catch (Throwable $exception) {
            $db->transRollback();
            return $this->error('Failed to recommend debt investigation.', 500);
        }

        return ['status' => 'success', 'code' => 200, 'investigation' => $model->find($investigationId)];
    }

    public function approveAndPost(int $investigationId, int $actorId): array
    {
        $db = Database::connect();
        $model = new DebtInvestigationModel();
        // This lookup supplies the lock key only; all approval decisions are repeated under it.
        $row = $model->find($investigationId);
        if (!$row) return $this->error('Investigation was not found.', 404);
        $db->transBegin();
        try {
            if (!$db->table('balances')->set('current_debt', 'current_debt', false)->where('user_id', (int) $row['user_id'])->update()) {
                throw new \RuntimeException('Failed to lock employee debt.');
            }
            $row = $model->find($investigationId);
            if (!$row || ($row['status'] ?? '') !== 'recommended') {
                $db->transRollback();
                return $this->error('Only a recommended investigation may be approved.', 409);
            }
            if ((int) ($row['recommended_by'] ?? 0) === $actorId) {
                $db->transRollback();
                return $this->error('A different Accounting user must approve this recommendation.', 403);
            }

            $action = (string) ($row['recommended_action'] ?? '');
            $amount = round((float) ($row['recommended_amount'] ?? 0), 2);
            if (!in_array($action, self::ACTIONS, true) || !is_finite($amount) || ($action === 'no_change' ? $amount != 0 : $amount <= 0)) {
                $db->transRollback();
                return $this->error('The recommendation amount is invalid.', 409);
            }
            if (!empty($row['posted_at']) || !empty($row['reversal_cashbook_entry_id']) || $db->table('debt_cashbook_entries')->where('entry_type', 'investigation_reversal')->where('reference_type', 'debt_investigation')->where('reference_id', $investigationId)->countAllResults() > 0) {
                $db->transRollback();
                return $this->error('This investigation has already been finalized.', 409);
            }
            if ($action !== 'no_change' && !empty($row['transaction_id'])) {
                $ceiling = $this->sourceDebtCeiling((int) $row['transaction_id'], (int) $row['user_id']);
                $posted = $this->postedSourceReversals((int) $row['transaction_id'], (int) $row['user_id']);
                if ($ceiling === null || (int) round(($posted + $amount) * 100) > (int) round($ceiling * 100)) {
                    $db->transRollback();
                    return $this->error('Combined reversals cannot exceed the original debt charge.', 409);
                }
            }
            $balanceModel = new BalanceModel();
            $balance = $balanceModel->getBalanceByUserId((int) $row['user_id']);
            if (!$balance) {
                $db->transRollback();
                return $this->error('Employee debt balance was not found.', 404);
            }
            $before = round((float) $balance['current_debt'], 2);
            if ($amount > $before) {
                $db->transRollback();
                return $this->error('Recommended reversal exceeds the employee current debt and must be investigated again.', 409);
            }

            $now = ibems_now()->format('Y-m-d H:i:s');
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

            $updated = $db->table('debt_investigations')->where('id', $investigationId)->where('status', 'recommended')->update([
                'status' => 'closed',
                'approved_by' => $actorId,
                'posted_by' => $actorId,
                'reversal_cashbook_entry_id' => $cashbookId,
                'approved_at' => $now,
                'posted_at' => $now,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$updated || $db->affectedRows() !== 1) throw new \RuntimeException('Investigation was already finalized.');
            $this->audit('APPROVE_AND_POST_DEBT_REVERSAL', $investigationId, $actorId, [
                'action' => $action, 'amount' => $amount, 'debt_before' => $before, 'debt_after' => $after, 'cashbook_entry_id' => $cashbookId,
            ]);
            if (!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Reversal commit failed.');

            return ['status' => 'success', 'code' => 200, 'investigation' => $model->find($investigationId), 'debt_before' => $before, 'debt_after' => $after];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Debt investigation posting failed: {message}', ['message' => $e->getMessage()]);
            return $this->error('Failed to approve and post debt reversal.', 500);
        }
    }

    private function sourceDebtCeiling(int $transactionId, int $userId): ?float
    {
        $db = Database::connect();
        $transaction = (new TransactionModel())->find($transactionId);
        if (!$transaction || (int) $transaction['user_id'] !== $userId || !in_array($transaction['payment_method'], ['debt', 'split'], true)) return null;
        $limits = [];
        $original = $db->table('debt_cashbook_entries')->selectSum('amount', 'total')->where('user_id', $userId)->where('entry_type', 'debt_purchase')->where('direction', 'debit')->where('reference_type', 'transaction')->where('reference_id', $transactionId)->get()->getRowArray();
        if ($original['total'] !== null) $limits[] = (float) $original['total'];
        $hasPayments = false;
        if ($db->tableExists('transaction_payments')) {
            $payments = $db->table('transaction_payments')->where('transaction_id', $transactionId)->get()->getResultArray();
            $hasPayments = $payments !== [];
            if ($hasPayments) $limits[] = array_sum(array_map(static fn ($p) => $p['payment_method'] === 'debt' ? (float) $p['amount'] : 0.0, $payments));
        }
        if ($limits === [] && !$hasPayments && $transaction['payment_method'] === 'debt') $limits[] = (float) $transaction['amount'];
        $ceiling = $limits === [] ? null : min($limits);
        return $ceiling !== null && is_finite($ceiling) && $ceiling > 0 ? $ceiling : null;
    }

    private function postedSourceReversals(int $transactionId, int $userId): float
    {
        $db = Database::connect();
        $cases = $db->table('debt_investigations')->select('id, reversal_cashbook_entry_id')->where('transaction_id', $transactionId)->where('user_id', $userId)->get()->getResultArray();
        $ids = array_map(static fn ($r) => (int) $r['id'], $cases);
        $linked = array_filter(array_map(static fn ($r) => (int) ($r['reversal_cashbook_entry_id'] ?? 0), $cases));
        if ($ids === []) return 0.0;
        $query = $db->table('debt_cashbook_entries')->selectSum('amount', 'total')->where('user_id', $userId)->where('entry_type', 'investigation_reversal')->where('direction', 'credit')->groupStart()->groupStart()->where('reference_type', 'debt_investigation')->whereIn('reference_id', $ids)->groupEnd();
        if ($linked !== []) $query->orWhereIn('id', array_values($linked));
        return (float) ($query->groupEnd()->get()->getRowArray()['total'] ?? 0);
    }

    private function audit(string $action, int $id, int $actorId, array $payload): void
    {
        if (!(new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null, 'action' => $action,
            'entity' => 'debt_investigations', 'entity_id' => $id,
            'payload_json' => json_encode($payload), 'created_at' => ibems_now()->format('Y-m-d H:i:s'),
        ])) {
            throw new \RuntimeException('Failed to write debt investigation audit.');
        }
    }

    private function error(string $message, int $code = 400): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }
}
