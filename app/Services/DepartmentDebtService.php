<?php

namespace App\Services;

use App\Models\AuditLogModel;
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
        $month = substr($date ?: date('Y-m-d'), 0, 7) . '-01';
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

        $period = $this->currentPeriod($departmentId, $createdAt);
        if (!$period || (string) ($period['status'] ?? '') !== 'open') {
            throw new RuntimeException('This department has no open debt allocation for the current month.');
        }

        $amountSql = number_format($amount, 2, '.', '');
        $updated = $this->db->table('department_debt_periods')
            ->set('used_amount', 'used_amount + ' . $amountSql, false)
            ->set('outstanding_amount', 'outstanding_amount + ' . $amountSql, false)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->where('id', (int) $period['id'])
            ->where('status', 'open')
            ->where('used_amount + ' . $amountSql . ' <= allocation_amount', null, false)
            ->update();
        if (!$updated || $this->db->affectedRows() !== 1) {
            throw new RuntimeException('The department monthly debt allocation is insufficient for this charge.');
        }

        $after = $this->db->table('department_debt_periods')->where('id', (int) $period['id'])->get()->getRowArray();
        $createdAt ??= date('Y-m-d H:i:s');
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
        $department = $this->db->table('departments')->where('id', $departmentId)->get()->getRowArray();
        if (!$department) {
            return $this->error('Department not found.', 404);
        }
        if ($status === 'open' && !ibems_bool($department['is_active'] ?? false)) {
            return $this->error('An inactive department cannot have an open debt allocation.', 409);
        }

        $existing = $this->db->table('department_debt_periods')->where('department_id', $departmentId)->where('period_month', $periodMonth)->get()->getRowArray();
        if ($existing && $allocation < (float) $existing['used_amount']) {
            return $this->error('Allocation cannot be lower than the amount already used for this month.', 409);
        }
        if ($existing && ($allocation !== (float) $existing['allocation_amount'] || $status !== (string) $existing['status']) && trim($reason) === '') {
            return $this->error('A reason is required when changing an existing allocation.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
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
                        throw new RuntimeException('Allocation cannot be lower than the amount already used for this month.');
                    }
                }
                $periodId = (int) $existing['id'];
                $used = (float) $existing['used_amount'];
                $outstanding = (float) $existing['outstanding_amount'];
                $beforeAllocation = (float) $existing['allocation_amount'];
            } else {
                $this->db->table('department_debt_periods')->insert([
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
                ]);
                $periodId = (int) $this->db->insertID();
                $used = 0.0;
                $outstanding = 0.0;
                $beforeAllocation = 0.0;
            }
            $this->db->table('department_debt_entries')->insert([
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
                'meta_json' => json_encode(['status_before' => $existing['status'] ?? null, 'status_after' => $status]),
                'created_at' => $now,
            ]);
            $this->audit('ACCOUNTING_SET_DEPARTMENT_ALLOCATION', $departmentId, $actorId, [
                'period_month' => $periodMonth,
                'allocation_before' => $beforeAllocation,
                'allocation_after' => $allocation,
                'status_before' => $existing['status'] ?? null,
                'status_after' => $status,
                'reason' => $reason,
            ]);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Allocation update failed.');
            }
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 200, 'period_id' => $periodId];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error($e->getMessage() ?: 'Unable to save the department allocation.', 500);
        }
    }

    public function recordSettlement(int $departmentId, int $periodId, float $amount, string $referenceNo, string $remarks, int $actorId): array
    {
        $amount = round($amount, 2);
        if ($departmentId <= 0 || $periodId <= 0 || !is_finite($amount) || $amount <= 0 || trim($referenceNo) === '' || trim($remarks) === '') {
            return $this->error('Department, period, positive amount, reference number, and remarks are required.');
        }
        $this->db->transBegin();
        try {
            $period = $this->db->table('department_debt_periods')->where('id', $periodId)->where('department_id', $departmentId)->get()->getRowArray();
            if (!$period) {
                throw new RuntimeException('Department debt period not found.');
            }
            $amountSql = number_format($amount, 2, '.', '');
            $ok = $this->db->table('department_debt_periods')
                ->set('outstanding_amount', 'outstanding_amount - ' . $amountSql, false)
                ->set('updated_at', date('Y-m-d H:i:s'))
                ->where('id', $periodId)
                ->where('outstanding_amount >= ' . $amountSql, null, false)
                ->update();
            if (!$ok || $this->db->affectedRows() !== 1) {
                throw new RuntimeException('Settlement exceeds the outstanding department debt.');
            }
            $after = $this->db->table('department_debt_periods')->where('id', $periodId)->get()->getRowArray();
            $now = date('Y-m-d H:i:s');
            $this->db->table('department_debt_entries')->insert([
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
            ]);
            $this->audit('ACCOUNTING_RECORD_DEPARTMENT_SETTLEMENT', $departmentId, $actorId, [
                'period_id' => $periodId,
                'amount' => $amount,
                'reference_no' => trim($referenceNo),
                'remarks' => trim($remarks),
            ]);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Settlement failed.');
            }
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 200];
        } catch (Throwable $e) {
            $this->db->transRollback();
            return $this->error($e->getMessage() ?: 'Unable to record the department settlement.', 409);
        }
    }

    private function audit(string $action, int $departmentId, int $actorId, array $payload): void
    {
        if (!(new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'entity' => 'departments',
            'entity_id' => $departmentId,
            'payload_json' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
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
