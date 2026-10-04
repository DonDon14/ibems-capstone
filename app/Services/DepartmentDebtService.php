<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

class DepartmentDebtService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function currentPeriod(int $departmentId, ?string $date = null): ?array
    {
        $month = substr($date ?: ibems_business_date(), 0, 7) . '-01';
        return $this->db->table('department_debt_periods')
            ->where('department_id', $departmentId)
            ->where('period_month', $month)
            ->get()->getRowArray() ?: null;
    }

    public function recordPurchase(
        int $departmentId,
        int $transactionId,
        float $amount,
        ?int $requesterUserId,
        string $requesterName,
        int $approverUserId,
        int $actorId,
        int $storeId,
        ?string $createdAt = null
    ): int {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Department debt amount must be greater than zero.');
        }
        $department = $this->db->table('departments')->where('id', $departmentId)->where('is_active', true)->get()->getRowArray();
        if (!$department) {
            throw new RuntimeException('The selected department is inactive or unavailable.');
        }
        if (!(new DepartmentAuthorizationService($this->db))->userCanApprove($departmentId, $approverUserId)) {
            throw new RuntimeException('The selected department approver is no longer authorized.');
        }

        // Transaction timestamps are stored in UTC; allocations use Manila business months.
        $businessDate = $createdAt === null ? ibems_business_date()
            : (new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $period = $this->currentPeriod($departmentId, $businessDate);
        if (!$period || (string) ($period['status'] ?? '') !== 'open') {
            throw new RuntimeException('This department has no open debt allocation for the current month.');
        }

        $amountSql = number_format($amount, 2, '.', '');
        $updated = $this->db->table('department_debt_periods')
            ->set('used_amount', 'used_amount + ' . $amountSql, false)
            ->set('outstanding_amount', 'outstanding_amount + ' . $amountSql, false)
            ->set('updated_at', ibems_now()->format('Y-m-d H:i:s'))
            ->where('id', (int) $period['id'])
            ->where('status', 'open')
            ->where('used_amount + ' . $amountSql . ' <= allocation_amount', null, false)
            ->update();
        if (!$updated || $this->db->affectedRows() !== 1) {
            throw new RuntimeException('The department monthly debt allocation is insufficient for this charge.');
        }

        $after = $this->db->table('department_debt_periods')->where('id', (int) $period['id'])->get()->getRowArray();
        $createdAt ??= ibems_now()->format('Y-m-d H:i:s');
        $entryPayload = [
            'department_id' => $departmentId,
            'period_id' => (int) $period['id'],
            'entry_type' => 'purchase',
            'direction' => 'debit',
            'amount' => $amount,
            'allocation_before' => (float) $after['allocation_amount'],
            'allocation_after' => (float) $after['allocation_amount'],
            'used_before' => (float) $after['used_amount'] - $amount,
            'used_after' => (float) $after['used_amount'],
            'outstanding_before' => (float) $after['outstanding_amount'] - $amount,
            'outstanding_after' => (float) $after['outstanding_amount'],
            'transaction_id' => $transactionId,
            'requester_user_id' => $requesterUserId && $requesterUserId > 0 ? $requesterUserId : null,
            'requester_name' => $requesterName !== '' ? $requesterName : null,
            'approved_by_user_id' => $approverUserId,
            'actor_id' => $actorId > 0 ? $actorId : null,
            'remarks' => 'POS department debt purchase',
            'meta_json' => json_encode(['store_id' => $storeId, 'transaction_id' => $transactionId]),
            'created_at' => $createdAt,
        ];
        if (!$this->db->table('department_debt_entries')->insert($entryPayload)) {
            throw new RuntimeException('Failed to record the department debt ledger entry.');
        }
        $entryId = (int) $this->db->insertID();
        if (!$this->db->table('department_debt_transactions')->insert([
            'transaction_id' => $transactionId,
            'department_id' => $departmentId,
            'period_id' => (int) $period['id'],
            'entry_id' => $entryId,
            'debt_amount' => $amount,
            'requester_user_id' => $requesterUserId && $requesterUserId > 0 ? $requesterUserId : null,
            'requester_name' => $requesterName !== '' ? $requesterName : null,
            'approved_by_user_id' => $approverUserId,
            'created_at' => $createdAt,
        ])) {
            throw new RuntimeException('Failed to link the department charge to the transaction.');
        }
        return $entryId;
    }

    public function setAllocation(int $departmentId, string $periodMonth, float $allocation, string $status, string $reason, int $actorId): array
    {
        $periodMonth = trim($periodMonth);
        $allocation = round($allocation, 2);
        $status = strtolower(trim($status));
        if ($departmentId <= 0 || !$this->validMonth($periodMonth)) {
            return $this->error('Select a valid department and month.');
        }
        $periodMonth .= '-01';
        if (!is_finite($allocation) || $allocation < 0 || !in_array($status, ['open', 'closed', 'suspended'], true)) {
            return $this->error('Enter a valid allocation and status.');
        }
        $this->db->transBegin();
        try {
            $periodId = $this->applyAllocation($departmentId, $periodMonth, $allocation, $status, trim($reason), $actorId);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Allocation update failed.');
            }
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 200, 'period_id' => $periodId];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error($e->getMessage() ?: 'Unable to save the department allocation.', $this->exceptionCode($e));
        }
    }

    public function setAllocations(array $departmentIds, string $periodMonth, float $allocation, string $status, string $reason, int $actorId): array
    {
        if ($departmentIds === [] || count($departmentIds) > 250) {
            return $this->error('Select between 1 and 250 departments and a valid month.');
        }
        $normalizedIds = [];
        foreach ($departmentIds as $departmentId) {
            if ((!is_int($departmentId) && !(is_string($departmentId) && ctype_digit($departmentId))) || (int) $departmentId <= 0) {
                return $this->error('Every selected department must have a valid numeric ID.');
            }
            $normalizedIds[] = (int) $departmentId;
        }
        $departmentIds = array_values(array_unique($normalizedIds));
        $periodMonth = trim($periodMonth);
        $allocation = round($allocation, 2);
        $status = strtolower(trim($status));
        $reason = trim($reason);

        if (!$this->validMonth($periodMonth)) {
            return $this->error('Select between 1 and 250 departments and a valid month.');
        }
        if (!is_finite($allocation) || $allocation < 0 || !in_array($status, ['open', 'closed', 'suspended'], true)) {
            return $this->error('Enter a valid allocation and status.');
        }
        if ($reason === '') {
            return $this->error('An audit reason is required for a batch allocation.');
        }

        $periodMonth .= '-01';
        $periodIds = [];
        $this->db->transBegin();
        try {
            $batchReference = 'DEPT-ALLOC-' . strtoupper(bin2hex(random_bytes(6)));
            foreach ($departmentIds as $departmentId) {
                $periodIds[$departmentId] = $this->applyAllocation($departmentId, $periodMonth, $allocation, $status, $reason, $actorId, $batchReference);
            }
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Batch allocation update failed.');
            }
            $this->db->transCommit();
            return [
                'status' => 'success',
                'code' => 200,
                'updated_count' => count($periodIds),
                'period_ids' => $periodIds,
                'batch_reference' => $batchReference,
            ];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error($e->getMessage() ?: 'Unable to save the batch allocation.', $this->exceptionCode($e));
        }
    }

    private function applyAllocation(int $departmentId, string $periodMonth, float $allocation, string $status, string $reason, int $actorId, ?string $batchReference = null): int
    {
        $department = $this->db->table('departments')->where('id', $departmentId)->get()->getRowArray();
        if (!$department) {
            throw new RuntimeException('A selected department was not found.', 404);
        }
        if ($status === 'open' && !ibems_bool($department['is_active'] ?? false)) {
            throw new RuntimeException('Inactive departments cannot receive an open debt allocation.', 409);
        }

        $existing = $this->db->table('department_debt_periods')->where('department_id', $departmentId)->where('period_month', $periodMonth)->get()->getRowArray();
        if ($existing && $allocation < (float) $existing['used_amount']) {
            throw new RuntimeException('The allocation for ' . (string) ($department['code'] ?? $departmentId) . ' is lower than the amount already used.', 409);
        }
        if ($existing && ($allocation !== (float) $existing['allocation_amount'] || $status !== (string) $existing['status']) && $reason === '') {
            throw new RuntimeException('A reason is required when changing an existing allocation.', 400);
        }

        $now = ibems_now()->format('Y-m-d H:i:s');
        if ($existing) {
            $updated = $this->db->table('department_debt_periods')
                ->where('id', (int) $existing['id'])
                ->where('used_amount <=', $allocation)
                ->update([
                    'allocation_amount' => $allocation,
                    'status' => $status,
                    'notes' => $reason !== '' ? $reason : ($existing['notes'] ?? null),
                    'configured_by' => $actorId > 0 ? $actorId : null,
                    'updated_at' => $now,
                ]);
            if (!$updated) {
                throw new RuntimeException('Unable to update the department allocation.');
            }
            if ($this->db->affectedRows() !== 1) {
                $current = $this->db->table('department_debt_periods')->where('id', (int) $existing['id'])->get()->getRowArray();
                $isSafeNoOp = $current
                    && (float) $current['used_amount'] <= $allocation
                    && (float) $current['allocation_amount'] === $allocation
                    && (string) $current['status'] === $status;
                if (!$isSafeNoOp) {
                    throw new RuntimeException('Allocation cannot be lower than the amount already used for this month.', 409);
                }
            }
            $periodId = (int) $existing['id'];
            $used = (float) $existing['used_amount'];
            $outstanding = (float) $existing['outstanding_amount'];
            $beforeAllocation = (float) $existing['allocation_amount'];
        } else {
            if (!$this->db->table('department_debt_periods')->insert([
                'department_id' => $departmentId,
                'period_month' => $periodMonth,
                'allocation_amount' => $allocation,
                'used_amount' => 0,
                'outstanding_amount' => 0,
                'status' => $status,
                'notes' => $reason !== '' ? $reason : null,
                'configured_by' => $actorId > 0 ? $actorId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ])) {
                throw new RuntimeException('Unable to create the department allocation.');
            }
            $periodId = (int) $this->db->insertID();
            $used = 0.0;
            $outstanding = 0.0;
            $beforeAllocation = 0.0;
        }

        if (!$this->db->table('department_debt_entries')->insert([
            'department_id' => $departmentId,
            'period_id' => $periodId,
            'entry_type' => $existing ? 'allocation_adjustment' : 'allocation_created',
            'direction' => 'neutral',
            'amount' => abs($allocation - $beforeAllocation),
            'allocation_before' => $beforeAllocation,
            'allocation_after' => $allocation,
            'used_before' => $used,
            'used_after' => $used,
            'outstanding_before' => $outstanding,
            'outstanding_after' => $outstanding,
            'actor_id' => $actorId > 0 ? $actorId : null,
            'remarks' => $reason !== '' ? $reason : 'Monthly allocation created',
            'meta_json' => json_encode(['status_before' => $existing['status'] ?? null, 'status_after' => $status, 'batch' => $batchReference !== null, 'batch_reference' => $batchReference]),
            'created_at' => $now,
        ])) {
            throw new RuntimeException('Unable to record the department allocation ledger entry.');
        }
        $this->audit('ACCOUNTING_SET_DEPARTMENT_ALLOCATION', $departmentId, $actorId, [
            'period_month' => $periodMonth,
            'allocation_before' => $beforeAllocation,
            'allocation_after' => $allocation,
            'status_before' => $existing['status'] ?? null,
            'status_after' => $status,
            'reason' => $reason,
            'batch' => $batchReference !== null,
            'batch_reference' => $batchReference,
        ]);
        return $periodId;
    }

    private function exceptionCode(Throwable $e): int
    {
        return in_array((int) $e->getCode(), [400, 404, 409], true) ? (int) $e->getCode() : 500;
    }

    public function recordSettlement(int $departmentId, int $periodId, float $amount, string $referenceNo, string $remarks, int $actorId, mixed $requestKey = null): array
    {
        $amount = round($amount, 2);
        if ($departmentId <= 0 || $periodId <= 0 || !is_finite($amount) || $amount <= 0 || trim($referenceNo) === '' || trim($remarks) === '') {
            return $this->error('Department, period, positive amount, reference number, and remarks are required.') + ['retry_safe' => true];
        }
        if (!is_string($requestKey) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $requestKey)) {
            return $this->error('A settlement request key is required. Reload the page before submitting.') + ['retry_safe' => true];
        }
        if (!$this->db->tableExists('department_settlement_receipts')) return $this->error('Settlement retry protection is unavailable. Apply the pending migration before recording payments.', 503) + ['retry_safe' => true];
        $key = hash('sha256', $actorId . ':' . $requestKey);
        $requestHash = hash('sha256', json_encode([$actorId, $departmentId, $periodId, number_format($amount, 2, '.', ''), trim($referenceNo), trim($remarks)], JSON_THROW_ON_ERROR));
        $this->db->transBegin();
        try {
            // A write claim precedes any snapshot reads, including on SQLite.
            if (!$this->db->table('department_debt_periods')->set('outstanding_amount', 'outstanding_amount', false)->where('id', $periodId)->where('department_id', $departmentId)->update()) throw new RuntimeException('Unable to lock the department period.');
            $replay = $this->settlementReplay($key, $requestHash);
            if ($replay !== null) {
                $this->db->transRollback();
                return $replay;
            }
            $period = $this->db->table('department_debt_periods')->where('id', $periodId)->where('department_id', $departmentId)->get()->getRowArray();
            if (!$period) {
                throw new RuntimeException('Department debt period not found.', 404);
            }
            $amountSql = number_format($amount, 2, '.', '');
            $ok = $this->db->table('department_debt_periods')
                ->set('outstanding_amount', 'outstanding_amount - ' . $amountSql, false)
                ->set('updated_at', ibems_now()->format('Y-m-d H:i:s'))
                ->where('id', $periodId)
                ->where('outstanding_amount >= ' . $amountSql, null, false)
                ->update();
            if (!$ok || $this->db->affectedRows() !== 1) {
                throw new RuntimeException('Settlement exceeds the outstanding department debt.', 409);
            }
            $after = $this->db->table('department_debt_periods')->where('id', $periodId)->get()->getRowArray();
            $now = ibems_now()->format('Y-m-d H:i:s');
            if (!$this->db->table('department_debt_entries')->insert([
                'department_id' => $departmentId,
                'period_id' => $periodId,
                'entry_type' => 'settlement',
                'direction' => 'credit',
                'amount' => $amount,
                'allocation_before' => (float) $after['allocation_amount'],
                'allocation_after' => (float) $after['allocation_amount'],
                'used_before' => (float) $after['used_amount'],
                'used_after' => (float) $after['used_amount'],
                'outstanding_before' => (float) $after['outstanding_amount'] + $amount,
                'outstanding_after' => (float) $after['outstanding_amount'],
                'actor_id' => $actorId > 0 ? $actorId : null,
                'reference_no' => trim($referenceNo),
                'remarks' => trim($remarks),
                'created_at' => $now,
            ])) throw new RuntimeException('Unable to record the settlement ledger entry.');
            $entryId = (int) $this->db->insertID();
            $this->audit('ACCOUNTING_RECORD_DEPARTMENT_SETTLEMENT', $departmentId, $actorId, [
                'period_id' => $periodId,
                'amount' => $amount,
                'reference_no' => trim($referenceNo),
                'remarks' => trim($remarks),
            ]);
            $response = ['status' => 'success', 'code' => 200, 'settlement_entry_id' => $entryId];
            if (!$this->db->table('department_settlement_receipts')->insert([
                'request_key' => $key, 'request_hash' => $requestHash, 'settlement_entry_id' => $entryId,
                'response_json' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => $now,
            ])) throw new RuntimeException('Unable to record the settlement receipt.');
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Settlement failed.');
            }
            if (!$this->db->transCommit()) throw new RuntimeException('Settlement commit failed.');
            return $response;
        } catch (Throwable $e) {
            $this->db->transRollback();
            // Unique-key races must be read only after rollback (PostgreSQL aborts the failed transaction).
            try {
                $replay = $this->settlementReplay($key, $requestHash);
                if ($replay !== null) return $replay;
            } catch (Throwable) { /* Return the safe retry response below. */ }
            $safeRejection = in_array((int) $e->getCode(), [404, 409], true);
            return $this->error($safeRejection ? $e->getMessage() : 'Unable to confirm the department settlement. Retry its original details safely.', 409) + ['retry_safe' => $safeRejection];
        }
    }

    private function settlementReplay(string $key, string $requestHash): ?array
    {
        $receipt = $this->db->table('department_settlement_receipts')->where('request_key', $key)->get()->getRowArray();
        if (!$receipt) return null;
        if (!hash_equals((string) $receipt['request_hash'], $requestHash)) return $this->error('This settlement request key was already used with different details.', 409);
        return json_decode((string) $receipt['response_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function audit(string $action, int $departmentId, int $actorId, array $payload): void
    {
        if (!$this->db->table('audit_logs')->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'entity' => 'departments',
            'entity_id' => $departmentId,
            'payload_json' => json_encode($payload),
            'created_at' => ibems_now()->format('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Failed to write department debt audit.');
        }
    }

    private function error(string $message, int $code = 400): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }

    private function validMonth(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
            return false;
        }
        return checkdate((int) $matches[2], 1, (int) $matches[1]);
    }
}
