<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\PaymentDestinationAccountModel;
use App\Models\StoreCashMovementModel;
use App\Models\StoreDaySessionModel;
use App\Models\StoreOpeningBalanceModel;
use App\Models\StorePaymentMethodModel;
use Config\Database;

final class StoreCashOperationService
{
    public function openingStatus(int $actorId, string $role, int $storeId): array
    {
        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StoreOpeningBalanceModel();
        $row = $model->getInitialByStore((int) $store['id']);

        return $this->result(200, [
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'business_date' => (string) ($row['business_date'] ?? ibems_business_date()),
            'is_opened' => (bool) $row,
            'opening' => $row ? [
                'id' => (int) $row['id'],
                'opening_balance' => (float) $row['opening_balance'],
                'note' => (string) ($row['note'] ?? ''),
                'opened_by' => $row['opened_by'] !== null ? (int) $row['opened_by'] : null,
                'opened_at' => (string) ($row['opened_at'] ?? ''),
            ] : null,
        ]);
    }

    public function setOpening(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $openingBalance = (float) ($request['opening_balance'] ?? 0);
        $note = trim((string) ($request['note'] ?? ''));

        if ($openingBalance < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Opening balance must be 0 or greater.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $openedBy = $actorId;
        $model = new StoreOpeningBalanceModel();
        $existing = $model->getInitialByStore((int) $store['id']);
        if ($existing) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Initial opening balance is already set. Use cash in/out for adjustments.',
            ]);
        }

        $row = $model->createInitialOpening((int) $store['id'], $openingBalance, $openedBy, $note);

        return $this->result(200, [
            'status' => 'success',
            'opening' => [
                'id' => (int) ($row['id'] ?? 0),
                'business_date' => (string) ($row['business_date'] ?? ibems_business_date()),
                'opening_balance' => (float) ($row['opening_balance'] ?? $openingBalance),
                'note' => (string) ($row['note'] ?? ''),
                'opened_by' => $row['opened_by'] !== null ? (int) $row['opened_by'] : $openedBy,
                'opened_at' => (string) ($row['opened_at'] ?? ''),
            ],
        ]);
    }

    public function resetOpening(array $request, int $actorId, string $role): array
    {
        if ($role !== 'ADMIN') {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'Only admin can reset initial opening balance.',
            ]);
        }

        $storeId = (int) ($request['store_id'] ?? 0);
        $openingBalance = (float) ($request['opening_balance'] ?? 0);
        $note = trim((string) ($request['note'] ?? ''));

        if ($note === '') {
            return $this->result(422, [
                'status' => 'error',
                'message' => 'An override reason is required to reset the initial opening balance.',
            ]);
        }

        if ($openingBalance < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Initial opening balance must be 0 or greater.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StoreOpeningBalanceModel();
        $existing = $model->getInitialByStore((int) $store['id']);
        if (!$existing) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'No initial opening balance found. Set it first.',
            ]);
        }

        $now = date('Y-m-d H:i:s');

        $model->update((int) $existing['id'], [
            'opening_balance' => $openingBalance,
            'note' => $note !== '' ? $note : null,
            'opened_by' => $actorId > 0 ? $actorId : null,
            'opened_at' => $now,
            'updated_at' => $now,
        ]);

        $updated = $model->find((int) $existing['id']) ?? $existing;

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'RESET_INITIAL_OPENING_BALANCE',
            'entity' => 'store_opening_balances',
            'entity_id' => (int) $existing['id'],
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'before' => [
                    'opening_balance' => (float) ($existing['opening_balance'] ?? 0),
                    'note' => (string) ($existing['note'] ?? ''),
                    'opened_by' => $existing['opened_by'] !== null ? (int) $existing['opened_by'] : null,
                    'opened_at' => (string) ($existing['opened_at'] ?? ''),
                ],
                'after' => [
                    'opening_balance' => (float) ($updated['opening_balance'] ?? $openingBalance),
                    'note' => (string) ($updated['note'] ?? ''),
                    'opened_by' => $updated['opened_by'] !== null ? (int) $updated['opened_by'] : null,
                    'opened_at' => (string) ($updated['opened_at'] ?? $now),
                ],
            ]),
            'created_at' => $now,
        ]);

        return $this->result(200, [
            'status' => 'success',
            'opening' => [
                'id' => (int) ($updated['id'] ?? $existing['id']),
                'business_date' => (string) ($updated['business_date'] ?? $existing['business_date']),
                'opening_balance' => (float) ($updated['opening_balance'] ?? $openingBalance),
                'note' => (string) ($updated['note'] ?? ''),
                'opened_by' => $updated['opened_by'] !== null ? (int) $updated['opened_by'] : ($actorId > 0 ? $actorId : null),
                'opened_at' => (string) ($updated['opened_at'] ?? $now),
            ],
        ]);
    }

    public function movements(int $actorId, string $role, array $filters): array
    {
        $storeId = (int) ($filters['store_id'] ?? 0);
        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $fromDate = trim((string) ($filters['date_from'] ?? ''));
        $toDate = trim((string) ($filters['date_to'] ?? ''));
        $todayDate = ibems_business_date();
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $fromDate)) {
            $fromDate = $todayDate;
        }
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $toDate)) {
            $toDate = $todayDate;
        }
        if ($fromDate > $toDate) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'date_from cannot be later than date_to.',
            ]);
        }

        $limit = (int) ($filters['limit'] ?? 200);
        $model = new StoreCashMovementModel();
        $rows = $model->getByStoreAndRange((int) $store['id'], $fromDate, $toDate, $limit);

        return $this->result(200, [
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'range' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'movements' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'business_date' => (string) ($row['business_date'] ?? ''),
                    'channel' => (string) ($row['channel'] ?? 'cash'),
                    'movement_type' => (string) ($row['movement_type'] ?? 'cash_in'),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'reason' => (string) ($row['reason'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $rows),
        ]);
    }

    public function createMovement(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $amount = (float) ($request['amount'] ?? 0);
        $reason = trim((string) ($request['reason'] ?? ''));
        $channel = strtolower(trim((string) ($request['channel'] ?? 'cash')));
        $movementType = strtolower(trim((string) ($request['movement_type'] ?? 'cash_in')));
        $businessDate = trim((string) ($request['business_date'] ?? ibems_business_date()));

        if (!in_array($channel, ['cash', 'ecash'], true)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid channel. Use cash or ecash.',
            ]);
        }

        if (!in_array($movementType, ['cash_in', 'cash_out'], true)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid movement type. Use cash_in or cash_out.',
            ]);
        }

        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $businessDate)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'business_date must be YYYY-MM-DD.',
            ]);
        }

        if ($amount <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Amount must be greater than 0.',
            ]);
        }

        if ($reason === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Reason is required.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $sessionModel = new StoreDaySessionModel();
        $daySession = $sessionModel->getByStoreAndDate((int) $store['id'], $businessDate);
        if (!$daySession || (string) ($daySession['status'] ?? '') !== 'open') {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Cash movements require an open store day for the selected business date.',
            ]);
        }

        $model = new StoreCashMovementModel();
        $movementId = $model->insert([
            'store_id' => (int) $store['id'],
            'business_date' => $businessDate,
            'channel' => $channel,
            'movement_type' => $movementType,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $actorId > 0 ? $actorId : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$movementId) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to save cash movement.',
            ]);
        }

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'CREATE_CASH_MOVEMENT',
            'entity' => 'store_cash_movements',
            'entity_id' => $movementId,
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'channel' => $channel,
                'movement_type' => $movementType,
                'amount' => $amount,
                'reason' => $reason,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->result(200, [
            'status' => 'success',
            'movement' => [
                'id' => (int) $movementId,
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'channel' => $channel,
                'movement_type' => $movementType,
                'amount' => $amount,
                'reason' => $reason,
            ],
        ]);
    }

    public function createDebtRepayment(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $debtorId = (int) ($request['user_id'] ?? 0);
        $amount = round((float) ($request['amount'] ?? 0), 2);
        $hasExplicitPaymentMethod = array_key_exists('payment_method', $request);
        $paymentMethod = strtolower(trim((string) ($request['payment_method'] ?? $request['channel'] ?? 'cash')));
        $channel = $paymentMethod === 'cash' ? 'cash' : 'ecash';
        $destinationAccountId = (int) ($request['destination_account_id'] ?? 0);
        $referenceNo = trim((string) ($request['reference_no'] ?? ''));
        $remarks = trim((string) ($request['remarks'] ?? ''));
        $businessDate = ibems_business_date();

        if ($debtorId <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Select a debtor before recording payment.',
            ]);
        }

        if ($amount <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Payment amount must be greater than 0.',
            ]);
        }

        if ($paymentMethod === 'debt' || $paymentMethod === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Select a valid collection payment method.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $methodRow = $hasExplicitPaymentMethod
            ? (new StorePaymentMethodModel())->where('store_id', (int) $store['id'])->where('code', $paymentMethod)->where('is_active', true)->first()
            : ['code' => $paymentMethod];
        if (!$methodRow || $paymentMethod === 'debt') {
            return $this->result(400, ['status' => 'error', 'message' => 'Select an active cash or electronic payment method.']);
        }
        if ($channel === 'ecash' && $hasExplicitPaymentMethod) {
            $destination = $destinationAccountId > 0 ? (new PaymentDestinationAccountModel())->find($destinationAccountId) : null;
            if (!$destination || (int) ($destination['store_id'] ?? 0) !== (int) $store['id'] || (int) ($destination['payment_method_id'] ?? 0) !== (int) ($methodRow['id'] ?? 0) || !ibems_bool($destination['is_active'] ?? false)) {
                return $this->result(400, ['status' => 'error', 'message' => 'Select an active receiving account for this electronic collection.']);
            }
        }

        $sessionModel = new StoreDaySessionModel();
        $daySession = $sessionModel->getByStoreAndDate((int) $store['id'], $businessDate);
        if (!$daySession || (string) ($daySession['status'] ?? '') !== 'open') {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Open today\'s store day before recording debt payments.',
            ]);
        }

        $db = Database::connect();
        $debtor = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.user_type, u.is_active, b.credit_limit, b.current_debt')
            ->join('balances b', 'b.user_id = u.id', 'inner')
            ->where('u.id', $debtorId)
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->get()
            ->getRowArray();

        if (!$debtor) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Active faculty/staff debtor not found.',
            ]);
        }

        $currentDebt = round((float) ($debtor['current_debt'] ?? 0), 2);
        $creditLimit = round((float) ($debtor['credit_limit'] ?? 0), 2);
        if ($currentDebt <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'This debtor has no outstanding debt.',
            ]);
        }

        if ($amount > $currentDebt) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Payment cannot exceed the current debt.',
            ]);
        }

        $newDebt = round($currentDebt - $amount, 2);
        $now = date('Y-m-d H:i:s');
        $reasonParts = [
            'Debt repayment',
            'Method: ' . ($paymentMethod === 'ecash' ? 'electronic' : $paymentMethod),
            $destinationAccountId > 0 ? 'Account: ' . $destinationAccountId : '',
            (string) ($debtor['employee_id'] ?? ''),
            (string) ($debtor['name'] ?? ''),
        ];
        if ($referenceNo !== '') {
            $reasonParts[] = 'Ref: ' . $referenceNo;
        }
        $reason = trim(implode(' | ', array_filter($reasonParts, static fn ($part) => trim($part) !== '')));

        $balanceModel = new BalanceModel();
        $cashMovementModel = new StoreCashMovementModel();
        $auditLogModel = new AuditLogModel();

        $db->transStart();

        $balanceModel->update($debtorId, [
            'current_debt' => $newDebt,
            'updated_at' => $now,
        ]);

        $movementId = $cashMovementModel->insert([
            'store_id' => (int) $store['id'],
            'business_date' => $businessDate,
            'channel' => $channel,
            'movement_type' => 'cash_in',
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $actorId > 0 ? $actorId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->addDebtCashbookEntry(
            $debtorId,
            'store_repayment',
            $amount,
            $currentDebt,
            $newDebt,
            $creditLimit,
            $actorId > 0 ? $actorId : null,
            $remarks !== '' ? $remarks : $reason,
            'store_cash_movement',
            $movementId ? (int) $movementId : null,
            [
                'source' => 'store_direct_repayment',
                'store_id' => (int) $store['id'],
                'store_name' => (string) ($store['store_name'] ?? 'Store'),
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
                'reference_no' => $referenceNo,
            ]
        );

        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'STORE_DEBT_REPAYMENT',
            'entity' => 'balances',
            'entity_id' => $debtorId,
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'user_id' => $debtorId,
                'previous_debt' => $currentDebt,
                'paid_amount' => $amount,
                'new_debt' => $newDebt,
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
                'reference_no' => $referenceNo,
                'cash_movement_id' => $movementId ? (int) $movementId : null,
            ]),
            'created_at' => $now,
        ]);

        $db->transComplete();

        if (!$db->transStatus() || !$movementId) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to record debt payment.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'payment' => [
                'movement_id' => (int) $movementId,
                'store_id' => (int) $store['id'],
                'user_id' => $debtorId,
                'debtor_name' => (string) ($debtor['name'] ?? ''),
                'employee_id' => (string) ($debtor['employee_id'] ?? ''),
                'channel' => $channel,
                'payment_method' => $paymentMethod,
                'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
                'amount' => $amount,
                'previous_debt' => $currentDebt,
                'new_debt' => $newDebt,
                'reference_no' => $referenceNo,
                'created_at' => $now,
            ],
        ]);
    }

    private function addDebtCashbookEntry(
        int $userId,
        string $entryType,
        float $amount,
        float $debtBefore,
        float $debtAfter,
        float $creditLimit,
        ?int $actorId = null,
        ?string $remarks = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = []
    ): void {
        $cashbookModel = new DebtCashbookEntryModel();
        $cashbookModel->insert([
            'user_id' => $userId,
            'entry_type' => $entryType,
            'direction' => $debtAfter >= $debtBefore ? 'debit' : 'credit',
            'amount' => abs($amount),
            'debt_before' => $debtBefore,
            'debt_after' => $debtAfter,
            'credit_limit_snapshot' => $creditLimit,
            'available_credit_snapshot' => max(0, $creditLimit - $debtAfter),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actorId,
            'remarks' => $remarks,
            'meta_json' => $meta !== [] ? json_encode($meta) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
