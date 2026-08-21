<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\DeductionBatchItemModel;
use App\Models\DeductionBatchModel;
use App\Models\DeductionPeriodModel;
use Config\Database;
use Throwable;

class DeductionBatchService
{
    public const FAILURE_STATUSES = [
        'not_deducted',
        'employee_not_found',
        'insufficient_salary',
        'duplicate',
        'returned_for_correction',
    ];

    public function prepare(int $periodId, array $requests, int $actorId, ?string $notes = null): array
    {
        $period = (new DeductionPeriodModel())->find($periodId);
        if (!$period || in_array((string) ($period['status'] ?? ''), ['finalized', 'cancelled'], true)) {
            return $this->error('Deduction period is unavailable for preparation.', 404);
        }
        if ($requests === []) {
            return $this->error('Add at least one employee to the deduction batch.');
        }

        $normalized = [];
        foreach ($requests as $request) {
            $userId = (int) ($request['user_id'] ?? 0);
            $amount = round((float) ($request['requested_amount'] ?? 0), 2);
            $choice = strtolower(trim((string) ($request['deduction_choice'] ?? 'partial')));
            $reason = trim((string) ($request['preparation_reason'] ?? ''));
            if ($userId <= 0 || !in_array($choice, ['full', 'partial', 'none'], true)) {
                return $this->error('Each batch row requires an employee and a valid deduction choice.');
            }
            if (($choice === 'none' && ($amount !== 0.0 || $reason === '')) || ($choice !== 'none' && $amount <= 0)) {
                return $this->error('Full or partial deductions require an amount; no deduction requires a reason.');
            }
            if (isset($normalized[$userId])) {
                return $this->error('Each employee may appear only once in a deduction batch.');
            }
            $normalized[$userId] = ['amount' => $amount, 'choice' => $choice, 'reason' => $reason];
        }

        $db = Database::connect();
        $rows = $db->table('balances b')
            ->select('b.user_id, b.current_debt, u.user_type, u.is_active')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->whereIn('b.user_id', array_keys($normalized))
            ->get()
            ->getResultArray();
        $eligible = [];
        foreach ($rows as $row) {
            $eligible[(int) $row['user_id']] = $row;
        }

        $register = (new DebtPeriodRegisterService())->build($period);
        $registerByUser = [];
        foreach ($register as $row) {
            $registerByUser[(int) $row['user_id']] = $row;
        }

        foreach ($normalized as $userId => $request) {
            $row = $eligible[$userId] ?? null;
            if (!$row || !$this->toBool($row['is_active'] ?? false) || !in_array($row['user_type'] ?? '', ['faculty', 'staff'], true)) {
                return $this->error('Every deduction account must be an active faculty/staff employee.');
            }
            $cutoffDebt = round((float) ($registerByUser[$userId]['cutoff_debt'] ?? $row['current_debt'] ?? 0), 2);
            if ($request['amount'] > $cutoffDebt) {
                return $this->error('Requested deduction cannot exceed the employee debt at the period cutoff.');
            }
            if ($request['choice'] === 'full' && $request['amount'] !== $cutoffDebt) {
                return $this->error('A full deduction must equal the employee debt at the period cutoff.');
            }
        }

        $db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $batchModel = new DeductionBatchModel();
            $existingBatch = $batchModel->where('period_id', $periodId)->first();
            if ($existingBatch && ($existingBatch['status'] ?? '') !== 'prepared') {
                throw new \DomainException('Deductions may be edited only while the batch is prepared.');
            }
            if ($existingBatch && (int) ($existingBatch['created_by'] ?? 0) !== $actorId) {
                throw new \DomainException('Only the assigned Accounting Officer may edit this deduction batch.');
            }
            $batchValues = [
                'status' => 'prepared',
                'total_accounts' => count($normalized),
                'total_requested' => array_sum(array_column($normalized, 'amount')),
                'total_confirmed' => 0,
                'total_carryover' => 0,
                'notes' => trim((string) $notes) ?: null,
                'updated_at' => $now,
            ];
            if ($existingBatch) {
                $batchId = (int) $existingBatch['id'];
                $batchModel->update($batchId, $batchValues);
                $db->table('deduction_batch_items')->where('batch_id', $batchId)->delete();
            } else {
                $batchId = $batchModel->insert($batchValues + [
                    'period_id' => $periodId,
                    'created_by' => $actorId > 0 ? $actorId : null,
                    'created_at' => $now,
                ]);
            }
            if (!$batchId) {
                throw new \RuntimeException('Failed to create deduction batch.');
            }

            $itemModel = new DeductionBatchItemModel();
            $itemFields = array_flip($db->getFieldNames('deduction_batch_items'));
            foreach ($normalized as $userId => $request) {
                $snapshot = $registerByUser[$userId] ?? [];
                $itemData = [
                    'batch_id' => $batchId,
                    'user_id' => $userId,
                    'debt_snapshot' => (float) ($snapshot['cutoff_debt'] ?? $eligible[$userId]['current_debt']),
                    'requested_amount' => $request['amount'],
                    'confirmed_amount' => 0,
                    'carryover_amount' => 0,
                    'result_status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $optional = [
                    'opening_debt_snapshot' => (float) ($snapshot['opening_debt'] ?? 0),
                    'period_debits_snapshot' => (float) ($snapshot['period_debits'] ?? 0),
                    'period_credits_snapshot' => (float) ($snapshot['period_credits'] ?? 0),
                    'salary_snapshot' => (float) ($snapshot['salary_reference'] ?? 0),
                    'deduction_choice' => $request['choice'],
                    'preparation_reason' => $request['reason'] ?: null,
                ];
                foreach ($optional as $field => $value) {
                    if (isset($itemFields[$field])) $itemData[$field] = $value;
                }
                if (!$itemModel->insert($itemData)) {
                    throw new \RuntimeException('Failed to create deduction batch item.');
                }
            }

            $this->audit($existingBatch ? 'ACCOUNTING_EDIT_DEDUCTION_BATCH' : 'ACCOUNTING_PREPARE_DEDUCTION_BATCH', 'deduction_batches', (int) $batchId, $actorId, [
                'period_id' => $periodId,
                'total_accounts' => count($normalized),
                'total_requested' => array_sum(array_column($normalized, 'amount')),
            ]);
            $db->transCommit();

            return [
                'status' => 'success',
                'code' => 201,
                'batch' => $batchModel->find((int) $batchId),
            ];
        } catch (Throwable $e) {
            $db->transRollback();
            if ($e instanceof \DomainException) {
                return $this->error($e->getMessage(), 409);
            }
            if ((int) ($db->error()['code'] ?? 0) === 1062 || str_contains(strtolower($e->getMessage()), 'unique')) {
                return $this->error('A deduction batch already exists for this period.', 409);
            }
            log_message('error', 'Deduction batch preparation failed: {message}', ['message' => $e->getMessage()]);

            return $this->error('Failed to prepare deduction batch.', 500);
        }
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'yes', 'on'], true);
    }

    public function confirmResult(int $itemId, array $result, int $actorId): array
    {
        $confirmedAmount = round((float) ($result['confirmed_amount'] ?? 0), 2);
        $reference = trim((string) ($result['result_reference'] ?? ''));
        $reasonCode = strtolower(trim((string) ($result['reason_code'] ?? '')));
        $notes = trim((string) ($result['result_notes'] ?? ''));

        if ($confirmedAmount < 0 || $reference === '') {
            return $this->error('Confirmed amount cannot be negative and an official result reference is required.');
        }

        $itemModel = new DeductionBatchItemModel();
        $item = $itemModel->find($itemId);
        if (!$item) {
            return $this->error('Deduction batch item not found.', 404);
        }

        if (($item['result_status'] ?? 'pending') !== 'pending') {
            $sameResult = round((float) ($item['confirmed_amount'] ?? 0), 2) === $confirmedAmount
                && (string) ($item['result_reference'] ?? '') === $reference;

            return $sameResult
                ? ['status' => 'success', 'code' => 200, 'idempotent' => true, 'item' => $item]
                : $this->error('This deduction result was already confirmed with different values.', 409);
        }

        $batch = (new DeductionBatchModel())->find((int) $item['batch_id']);
        if (!$batch || !in_array((string) ($batch['status'] ?? ''), ['submitted', 'partially_processed'], true)) {
            return $this->error('Submit the deduction batch before confirming payroll results.', 409);
        }

        $requestedAmount = round((float) ($item['requested_amount'] ?? 0), 2);
        if ($confirmedAmount > $requestedAmount) {
            return $this->error('Confirmed amount cannot exceed the requested amount.');
        }
        if ($confirmedAmount < $requestedAmount && !in_array($reasonCode, self::FAILURE_STATUSES, true)) {
            return $this->error('A valid reason code is required for a partial or failed deduction.');
        }

        $db = Database::connect();
        $balanceModel = new BalanceModel();
        $balance = $balanceModel->getBalanceByUserId((int) $item['user_id']);
        $debtBefore = round((float) ($balance['current_debt'] ?? 0), 2);
        if (!$balance || $confirmedAmount > $debtBefore) {
            return $this->error('Confirmed amount exceeds the employee current debt. Reconcile the account before confirming.', 409);
        }

        $debtAfter = round(max(0, $debtBefore - $confirmedAmount), 2);
        $resultStatus = $confirmedAmount <= 0
            ? $reasonCode
            : ($confirmedAmount < $requestedAmount ? 'partially_deducted' : 'fully_deducted');
        $now = date('Y-m-d H:i:s');

        $db->transBegin();
        try {
            if ($confirmedAmount > 0 && !$balanceModel->update((int) $item['user_id'], [
                'current_debt' => $debtAfter,
                'updated_at' => $now,
            ])) {
                throw new \RuntimeException('Failed to update employee debt.');
            }

            if (!$itemModel->update($itemId, [
                'confirmed_amount' => $confirmedAmount,
                'carryover_amount' => $debtAfter,
                'result_status' => $resultStatus,
                'reason_code' => $reasonCode ?: null,
                'result_reference' => $reference,
                'result_notes' => $notes ?: null,
                'confirmed_by' => $actorId > 0 ? $actorId : null,
                'confirmed_at' => $now,
                'updated_at' => $now,
            ])) {
                throw new \RuntimeException('Failed to record deduction result.');
            }

            if ($confirmedAmount > 0) {
                $creditLimit = (float) ($balance['credit_limit'] ?? 0);
                if (!(new DebtCashbookEntryModel())->insert([
                    'user_id' => (int) $item['user_id'],
                    'entry_type' => 'confirmed_salary_deduction',
                    'direction' => 'credit',
                    'amount' => $confirmedAmount,
                    'debt_before' => $debtBefore,
                    'debt_after' => $debtAfter,
                    'credit_limit_snapshot' => $creditLimit,
                    'available_credit_snapshot' => max(0, $creditLimit - $debtAfter),
                    'reference_type' => 'deduction_batch_item',
                    'reference_id' => $itemId,
                    'actor_id' => $actorId > 0 ? $actorId : null,
                    'remarks' => 'Confirmed payroll deduction',
                    'meta_json' => json_encode([
                        'result_reference' => $reference,
                        'result_status' => $resultStatus,
                        'carryover_amount' => $debtAfter,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])) {
                    throw new \RuntimeException('Failed to write debt cashbook entry.');
                }
            }

            $this->audit('ACCOUNTING_CONFIRM_DEDUCTION_RESULT', 'deduction_batch_items', $itemId, $actorId, [
                'batch_id' => (int) $item['batch_id'],
                'user_id' => (int) $item['user_id'],
                'requested_amount' => $requestedAmount,
                'confirmed_amount' => $confirmedAmount,
                'carryover_amount' => $debtAfter,
                'result_status' => $resultStatus,
                'result_reference' => $reference,
            ]);
            $batchStatus = $this->refreshBatchTotals((int) $item['batch_id'], $now);
            if ($batchStatus === 'processed') {
                (new DeductionPeriodModel())->update((int) $batch['period_id'], [
                    'status' => 'processed',
                    'confirmed_by' => $actorId > 0 ? $actorId : null,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                (new DeductionPeriodModel())->update((int) $batch['period_id'], [
                    'status' => 'partially_processed',
                    'updated_at' => $now,
                ]);
            }
            $db->transCommit();

            return [
                'status' => 'success',
                'code' => 200,
                'item' => $itemModel->find($itemId),
                'debt_before' => $debtBefore,
                'debt_after' => $debtAfter,
            ];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Deduction result confirmation failed: {message}', ['message' => $e->getMessage()]);

            return $this->error('Failed to confirm deduction result.', 500);
        }
    }

    public function submit(int $batchId, int $actorId): array
    {
        $batchModel = new DeductionBatchModel();
        $batch = $batchModel->find($batchId);
        if (!$batch || ($batch['status'] ?? '') !== 'prepared') {
            return $this->error('Only a prepared deduction batch may be submitted.', 409);
        }
        if ((int) ($batch['created_by'] ?? 0) !== $actorId) {
            return $this->error('Only the assigned Accounting Officer may submit this deduction batch.', 403);
        }

        $now = date('Y-m-d H:i:s');
        $batchModel->update($batchId, ['status' => 'submitted', 'updated_at' => $now]);
        (new DeductionPeriodModel())->update((int) $batch['period_id'], [
            'status' => 'submitted',
            'reviewed_by' => $actorId > 0 ? $actorId : null,
            'submitted_by' => $actorId > 0 ? $actorId : null,
            'reviewed_at' => $now,
            'submitted_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit('ACCOUNTING_SUBMIT_DEDUCTION_BATCH', 'deduction_batches', $batchId, $actorId, [
            'period_id' => (int) $batch['period_id'],
            'total_accounts' => (int) ($batch['total_accounts'] ?? 0),
            'total_requested' => (float) ($batch['total_requested'] ?? 0),
        ]);

        return ['status' => 'success', 'code' => 200, 'batch' => $batchModel->find($batchId)];
    }

    public function applyPrepared(int $batchId, int $actorId): array
    {
        $batchModel = new DeductionBatchModel();
        $batch = $batchModel->find($batchId);
        if (!$batch || !in_array((string) ($batch['status'] ?? ''), ['prepared', 'submitted'], true)) {
            return $this->error('Only a prepared or previously submitted deduction batch may be applied.', 409);
        }
        if ((int) ($batch['created_by'] ?? 0) !== $actorId) {
            return $this->error('Only the assigned Accounting Officer may apply this deduction batch.', 403);
        }

        $db = Database::connect();
        $items = $db->table('deduction_batch_items')
            ->where('batch_id', $batchId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        if ($items === []) {
            return $this->error('The prepared batch has no deduction items.', 409);
        }

        $db->transBegin();
        try {
            if (($batch['status'] ?? '') === 'prepared') {
                $submitted = $this->submit($batchId, $actorId);
                if (($submitted['status'] ?? 'error') !== 'success') {
                    throw new \RuntimeException((string) ($submitted['message'] ?? 'Failed to apply deduction batch.'));
                }
            }

            foreach ($items as $item) {
                $amount = round((float) ($item['requested_amount'] ?? 0), 2);
                $result = $this->confirmResult((int) $item['id'], [
                    'confirmed_amount' => $amount,
                    'reason_code' => $amount > 0 ? '' : 'not_deducted',
                    'result_reference' => 'IBEMS-' . $batchId . '-' . (int) $item['id'],
                    'result_notes' => $amount > 0 ? 'Applied from prepared deduction batch.' : (string) ($item['preparation_reason'] ?? 'No deduction'),
                ], $actorId);
                if (($result['status'] ?? 'error') !== 'success') {
                    throw new \RuntimeException((string) ($result['message'] ?? 'Failed to apply an employee deduction.'));
                }
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('The deduction batch could not be applied.');
            }
            $db->transCommit();

            return ['status' => 'success', 'code' => 200, 'batch' => $batchModel->find($batchId)];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Prepared deduction batch application failed: {message}', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 409);
        }
    }

    public function reconcile(int $batchId, int $actorId): array
    {
        $batchModel = new DeductionBatchModel();
        $batch = $batchModel->find($batchId);
        if (!$batch || ($batch['status'] ?? '') !== 'processed') {
            return $this->error('Resolve every deduction result before reconciliation.', 409);
        }
        if ((int) ($batch['created_by'] ?? 0) !== $actorId) {
            return $this->error('Only the assigned Accounting Officer may reconcile this deduction batch.', 403);
        }

        $db = Database::connect();
        $totals = $db->table('deduction_batch_items')
            ->selectSum('confirmed_amount', 'confirmed')
            ->selectSum('carryover_amount', 'carryover')
            ->where('batch_id', $batchId)
            ->get()
            ->getRowArray();
        if (round((float) ($totals['confirmed'] ?? 0), 2) !== round((float) ($batch['total_confirmed'] ?? 0), 2)
            || round((float) ($totals['carryover'] ?? 0), 2) !== round((float) ($batch['total_carryover'] ?? 0), 2)) {
            return $this->error('Batch totals do not reconcile with employee results.', 409);
        }

        $now = date('Y-m-d H:i:s');
        $batchModel->update($batchId, ['status' => 'reconciled', 'updated_at' => $now]);
        (new DeductionPeriodModel())->update((int) $batch['period_id'], ['status' => 'reconciled', 'updated_at' => $now]);
        $this->audit('ACCOUNTING_RECONCILE_DEDUCTION_BATCH', 'deduction_batches', $batchId, $actorId, [
            'total_confirmed' => (float) ($batch['total_confirmed'] ?? 0),
            'total_carryover' => (float) ($batch['total_carryover'] ?? 0),
        ]);

        return ['status' => 'success', 'code' => 200, 'batch' => $batchModel->find($batchId)];
    }

    public function finalize(int $batchId, int $actorId): array
    {
        $batchModel = new DeductionBatchModel();
        $batch = $batchModel->find($batchId);
        if (!$batch || ($batch['status'] ?? '') !== 'reconciled') {
            return $this->error('Only a reconciled deduction batch may be finalized.', 409);
        }
        if ((int) ($batch['created_by'] ?? 0) !== $actorId) {
            return $this->error('Only the assigned Accounting Officer may finalize this deduction period.', 403);
        }

        $now = date('Y-m-d H:i:s');
        $batchModel->update($batchId, ['status' => 'finalized', 'updated_at' => $now]);
        (new DeductionPeriodModel())->update((int) $batch['period_id'], [
            'status' => 'finalized',
            'finalized_by' => $actorId > 0 ? $actorId : null,
            'finalized_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit('ACCOUNTING_FINALIZE_DEDUCTION_BATCH', 'deduction_batches', $batchId, $actorId, [
            'period_id' => (int) $batch['period_id'],
            'total_confirmed' => (float) ($batch['total_confirmed'] ?? 0),
            'total_carryover' => (float) ($batch['total_carryover'] ?? 0),
        ]);

        return ['status' => 'success', 'code' => 200, 'batch' => $batchModel->find($batchId)];
    }

    private function refreshBatchTotals(int $batchId, string $now): string
    {
        $db = Database::connect();
        $totals = $db->table('deduction_batch_items')
            ->selectSum('confirmed_amount', 'confirmed')
            ->selectSum('carryover_amount', 'carryover')
            ->select("SUM(CASE WHEN result_status = 'pending' THEN 1 ELSE 0 END) AS pending_count", false)
            ->where('batch_id', $batchId)
            ->get()
            ->getRowArray();

        $status = (int) ($totals['pending_count'] ?? 0) > 0 ? 'partially_processed' : 'processed';
        (new DeductionBatchModel())->update($batchId, [
            'status' => $status,
            'total_confirmed' => (float) ($totals['confirmed'] ?? 0),
            'total_carryover' => (float) ($totals['carryover'] ?? 0),
            'updated_at' => $now,
        ]);

        return $status;
    }

    private function audit(string $action, string $entity, int $entityId, int $actorId, array $payload): void
    {
        if (!(new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'payload_json' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new \RuntimeException('Failed to write deduction audit.');
        }
    }

    private function error(string $message, int $code = 400): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }
}
