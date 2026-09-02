<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\PaymentDestinationAccountModel;
use App\Models\StoreDaySessionModel;
use Config\Database;

final class StoreDayOperationService
{
    public function status(int $actorId, string $role, int $storeId): array
    {
        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $businessDate = ibems_business_date();
        $model = new StoreDaySessionModel();
        $todaySession = $model->getByStoreAndDate((int) $store['id'], $businessDate);
        $openSession = $model->getOpenByStore((int) $store['id']);
        $session = $todaySession ?: $openSession;
        $expected = $session ? (new StoreDayExpectedService())->calculate((int) $store['id'], $session) : null;
        $isCurrentBusinessDate = $session !== null && (string) ($session['business_date'] ?? '') === $businessDate;
        $isStaleOpen = $session !== null && (string) ($session['status'] ?? '') === 'open' && !$isCurrentBusinessDate;
        $serializedSession = $session ? $this->serialize($session, $expected) : null;
        if ($serializedSession) {
            $serializedSession['is_current_business_date'] = $isCurrentBusinessDate;
            $serializedSession['is_stale_open'] = $isStaleOpen;
        }

        return $this->result(200, [
            'status' => 'success',
            'store_id' => (int) $store['id'],
            'business_date' => $businessDate,
            'is_opened' => $session !== null && (string) ($session['status'] ?? '') === 'open' && $isCurrentBusinessDate,
            'is_closed' => $todaySession !== null && (string) ($todaySession['status'] ?? '') === 'closed',
            'is_stale_open' => $isStaleOpen,
            'session' => $serializedSession,
        ]);
    }

    public function open(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $openingCash = (float) ($request['opening_cash'] ?? $request['opening_balance'] ?? 0);
        $openingEcash = (float) ($request['opening_ecash'] ?? 0);
        $legacyOpeningEcash = $openingEcash;
        $paymentAccountOpenings = is_array($request['payment_account_openings'] ?? null) ? $request['payment_account_openings'] : [];
        $note = trim((string) ($request['note'] ?? ''));
        $businessDate = ibems_business_date();

        if ($openingCash < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Opening cash must be 0 or greater.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $db = Database::connect();
        $validatedAccountOpenings = [];
        $openingEcash = 0.0;
        if ($db->tableExists('payment_destination_accounts')) {
            foreach ($paymentAccountOpenings as $opening) {
                $accountId = (int) ($opening['destination_account_id'] ?? 0);
                $amount = round((float) ($opening['opening_balance'] ?? 0), 2);
                $account = $accountId > 0 ? (new PaymentDestinationAccountModel())->find($accountId) : null;
                if (!$account || (int) $account['store_id'] !== (int) $store['id'] || !ibems_bool($account['is_active'] ?? false) || $amount < 0) {
                    return $this->result(400, ['status' => 'error', 'message' => 'Every receiving-account opening balance must be valid and 0 or greater.']);
                }
                $validatedAccountOpenings[] = ['destination_account_id' => $accountId, 'opening_balance' => $amount];
                $openingEcash += $amount;
            }
        }
        if ($validatedAccountOpenings === []) $openingEcash = max(0, $legacyOpeningEcash);
        $paymentAccountOpenings = $validatedAccountOpenings;

        $model = new StoreDaySessionModel();
        $openSession = $model->getOpenByStore((int) $store['id']);
        if ($openSession && (string) ($openSession['business_date'] ?? '') !== $businessDate) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Another store day is still open. Close it before opening today.',
            ]);
        }

        $existing = $model->getByStoreAndDate((int) $store['id'], $businessDate);
        if ($existing && (string) ($existing['status'] ?? '') === 'open') {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Today is already open for this store.',
            ]);
        }

        if ($existing && (string) ($existing['status'] ?? '') === 'closed' && $role !== 'ADMIN') {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'Today is already closed. Only admin can reopen it.',
            ]);
        }

        $isReopen = $existing && (string) ($existing['status'] ?? '') === 'closed';
        $previousClose = null;
        if ($isReopen) {
            if ($note === '') {
                return $this->result(400, ['status' => 'error', 'message' => 'A reopen reason is required.']);
            }
            $previousClose = [
                'expected_cash' => ($existing['expected_cash'] ?? null) !== null ? (float) $existing['expected_cash'] : null,
                'expected_ecash' => ($existing['expected_ecash'] ?? null) !== null ? (float) $existing['expected_ecash'] : null,
                'counted_cash' => ($existing['counted_cash'] ?? null) !== null ? (float) $existing['counted_cash'] : null,
                'counted_ecash' => ($existing['counted_ecash'] ?? null) !== null ? (float) $existing['counted_ecash'] : null,
                'variance_cash' => ($existing['variance_cash'] ?? null) !== null ? (float) $existing['variance_cash'] : null,
                'variance_ecash' => ($existing['variance_ecash'] ?? null) !== null ? (float) $existing['variance_ecash'] : null,
                'variance_status' => (string) ($existing['variance_status'] ?? ''),
                'review_status' => (string) ($existing['review_status'] ?? ''),
                'closing_note' => (string) ($existing['closing_note'] ?? ''),
                'closed_by' => ($existing['closed_by'] ?? null) !== null ? (int) $existing['closed_by'] : null,
                'closed_at' => (string) ($existing['closed_at'] ?? ''),
            ];
            $openingCash = (float) ($existing['opening_cash'] ?? 0);
            $openingEcash = (float) ($existing['opening_ecash'] ?? 0);
            $session = $model->reopenDay((int) $existing['id'], $actorId);
        } else {
            $session = $model->openDay((int) $store['id'], $businessDate, $openingCash, $openingEcash, $actorId, $note);
        }
        if (!$isReopen && $db->tableExists('store_day_payment_account_balances')) {
            foreach ($paymentAccountOpenings as $opening) {
                $accountId = (int) ($opening['destination_account_id'] ?? 0);
                $account = $accountId > 0 ? (new PaymentDestinationAccountModel())->find($accountId) : null;
                if (!$account || (int) $account['store_id'] !== (int) $store['id'] || !ibems_bool($account['is_active'] ?? false)) continue;
                $amount = max(0, round((float) ($opening['opening_balance'] ?? 0), 2));
                $payload = ['store_day_session_id' => (int) $session['id'], 'destination_account_id' => $accountId,
                    'account_name_snapshot' => (string) $account['account_name'], 'account_number_snapshot' => (string) $account['account_number'],
                    'opening_balance' => $amount, 'updated_at' => date('Y-m-d H:i:s')];
                $existingBalance = $db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) $session['id'])->where('destination_account_id', $accountId)->get()->getRowArray();
                if ($existingBalance) $db->table('store_day_payment_account_balances')->where('id', (int) $existingBalance['id'])->update($payload);
                else { $payload['created_at'] = date('Y-m-d H:i:s'); $db->table('store_day_payment_account_balances')->insert($payload); }
            }
        }
        $expected = (new StoreDayExpectedService())->calculate((int) $store['id'], $session);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $isReopen ? 'REOPEN_STORE_DAY_SESSION' : 'OPEN_STORE_DAY_SESSION',
            'entity' => 'store_day_sessions',
            'entity_id' => (int) ($session['id'] ?? 0),
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'opening_cash' => $openingCash,
                'opening_ecash' => $openingEcash,
                'reason' => $isReopen ? $note : null,
                'previous_close' => $previousClose,
                'original_opening_balances_preserved' => $isReopen,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->result(200, [
            'status' => 'success',
            'session' => $this->serialize($session, $expected),
        ]);
    }

    public function close(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $countedCash = (float) ($request['counted_cash'] ?? 0);
        $countedEcash = (float) ($request['counted_ecash'] ?? 0);
        $legacyCountedEcash = $countedEcash;
        $paymentAccountCounts = is_array($request['payment_account_counts'] ?? null) ? $request['payment_account_counts'] : [];
        $unassignedPaymentCounts = is_array($request['unassigned_payment_counts'] ?? null) ? $request['unassigned_payment_counts'] : [];
        $hasStructuredElectronicCounts = array_key_exists('payment_account_counts', $request) || array_key_exists('unassigned_payment_counts', $request);
        $note = trim((string) ($request['note'] ?? ''));

        if ($countedCash < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Counted cash must be 0 or greater.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StoreDaySessionModel();
        $session = $model->getOpenByStore((int) $store['id']);
        if (!$session || (string) ($session['status'] ?? '') !== 'open') {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'There is no open day session to close for this store.',
            ]);
        }
        $businessDate = (string) ($session['business_date'] ?? ibems_business_date());
        if ($businessDate !== ibems_business_date()) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'A stale store day must be resolved by another assigned supervisor or an Administrator.',
            ]);
        }

        $expected = (new StoreDayExpectedService())->calculate((int) $store['id'], $session);
        $expectedCash = (float) ($expected['expected_cash_on_hand'] ?? 0);
        $expectedEcash = (float) ($expected['expected_ecash_on_hand'] ?? 0);
        $expectedAccounts = [];
        foreach (($expected['payment_account_balances'] ?? []) as $account) $expectedAccounts[(int) $account['id']] = $account;
        $validatedAccountCounts = [];
        $countedEcash = 0.0;
        foreach ($paymentAccountCounts as $count) {
            $accountId = (int) ($count['destination_account_id'] ?? 0);
            $counted = round((float) ($count['counted_balance'] ?? 0), 2);
            if (!isset($expectedAccounts[$accountId]) || $counted < 0) {
                return $this->result(400, ['status' => 'error', 'message' => 'Every receiving-account ending balance must be valid and 0 or greater.']);
            }
            $validatedAccountCounts[] = ['destination_account_id' => $accountId, 'counted_balance' => $counted];
            $countedEcash += $counted;
        }
        $expectedUnassigned = [];
        foreach (($expected['unassigned_payment_balances'] ?? []) as $row) $expectedUnassigned[(string) $row['key']] = $row;
        $validatedUnassignedCounts = [];
        foreach ($unassignedPaymentCounts as $count) {
            $key = (string) ($count['key'] ?? '');
            $counted = round((float) ($count['counted_balance'] ?? 0), 2);
            if (!isset($expectedUnassigned[$key]) || $counted < 0) {
                return $this->result(400, ['status' => 'error', 'message' => 'Every legacy payment ending balance must be valid and 0 or greater.']);
            }
            $validatedUnassignedCounts[] = ['key' => $key, 'counted_balance' => $counted];
            $countedEcash += $counted;
        }
        if ($hasStructuredElectronicCounts && count($validatedUnassignedCounts) !== count($expectedUnassigned)) {
            return $this->result(400, ['status' => 'error', 'message' => 'Count every legacy payment bucket before closing the store day.']);
        }
        $unassignedPaymentCounts = $validatedUnassignedCounts;
        if (!$hasStructuredElectronicCounts || ($expectedAccounts === [] && $expectedUnassigned === [])) $countedEcash = max(0, $legacyCountedEcash);
        if ($hasStructuredElectronicCounts && count($validatedAccountCounts) !== count($expectedAccounts)) {
            return $this->result(400, ['status' => 'error', 'message' => 'Count every receiving account before closing the store day.']);
        }
        $paymentAccountCounts = $validatedAccountCounts;
        $varianceCash = round($countedCash - $expectedCash, 2);
        $varianceEcash = round($countedEcash - $expectedEcash, 2);
        $totalVariance = round($varianceCash + $varianceEcash, 2);
        $varianceStatus = 'balanced';
        if ($totalVariance < 0) {
            $varianceStatus = 'shortage';
        } elseif ($totalVariance > 0) {
            $varianceStatus = 'overage';
        }
        $reviewStatus = $varianceStatus === 'balanced' ? 'not_required' : 'pending';

        if ($reviewStatus === 'pending' && $note === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Closing note is required when there is a cash or e-cash variance.',
            ]);
        }

        $now = date('Y-m-d H:i:s');

        $model->update((int) $session['id'], [
            'status' => 'closed',
            'expected_cash' => $expectedCash,
            'expected_ecash' => $expectedEcash,
            'counted_cash' => $countedCash,
            'counted_ecash' => $countedEcash,
            'variance_cash' => $varianceCash,
            'variance_ecash' => $varianceEcash,
            'variance_status' => $varianceStatus,
            'review_status' => $reviewStatus,
            'closing_note' => $note !== '' ? $note : null,
            'closed_by' => $actorId > 0 ? $actorId : null,
            'closed_at' => $now,
            'updated_at' => $now,
        ]);

        $db = Database::connect();
        if ($db->tableExists('store_day_payment_account_balances')) {
            foreach ($paymentAccountCounts as $count) {
                $accountId = (int) ($count['destination_account_id'] ?? 0);
                if (!isset($expectedAccounts[$accountId])) continue;
                $account = $expectedAccounts[$accountId];
                $counted = max(0, round((float) ($count['counted_balance'] ?? 0), 2));
                $expectedBalance = round((float) ($account['expected_balance'] ?? 0), 2);
                $payload = ['store_day_session_id' => (int) $session['id'], 'destination_account_id' => $accountId,
                    'account_name_snapshot' => (string) $account['account_name'], 'account_number_snapshot' => (string) $account['account_number'],
                    'opening_balance' => 0, 'expected_balance' => $expectedBalance, 'counted_balance' => $counted,
                    'variance' => round($counted - $expectedBalance, 2), 'updated_at' => $now];
                $existingAccountBalance = $db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) $session['id'])->where('destination_account_id', $accountId)->get()->getRowArray();
                if ($existingAccountBalance) $db->table('store_day_payment_account_balances')->where('id', (int) $existingAccountBalance['id'])->update($payload);
                else { $payload['created_at'] = $now; $db->table('store_day_payment_account_balances')->insert($payload); }
            }
        }

        $closed = $model->find((int) $session['id']) ?? $session;
        if ($reviewStatus === 'pending') {
            (new StoreDayVarianceCaseService())->ensureCase(
                Database::connect(),
                $closed,
                $actorId > 0 ? $actorId : null,
                $now
            );
        }
        $closedExpected = (new StoreDayExpectedService())->calculate((int) $store['id'], $closed);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => 'CLOSE_STORE_DAY_SESSION',
            'entity' => 'store_day_sessions',
            'entity_id' => (int) $session['id'],
            'payload_json' => json_encode([
                'store_id' => (int) $store['id'],
                'business_date' => $businessDate,
                'expected_cash' => $expectedCash,
                'expected_ecash' => $expectedEcash,
                'counted_cash' => $countedCash,
                'counted_ecash' => $countedEcash,
                'variance_cash' => $varianceCash,
                'variance_ecash' => $varianceEcash,
                'variance_status' => $varianceStatus,
                'review_status' => $reviewStatus,
                'unassigned_payment_counts' => $unassignedPaymentCounts,
                'note' => $note,
            ]),
            'created_at' => $now,
        ]);

        return $this->result(200, [
            'status' => 'success',
            'session' => $this->serialize($closed, $closedExpected),
        ]);
    }

    private function serialize(array $session, ?array $expected = null): array
    {
        $expected ??= (new StoreDayExpectedService())->calculate((int) ($session['store_id'] ?? 0), $session);
        $openedBy = $session['opened_by'] ?? null;
        $closedBy = $session['closed_by'] ?? null;

        return [
            'id' => (int) ($session['id'] ?? 0),
            'store_id' => (int) ($session['store_id'] ?? 0),
            'business_date' => (string) ($session['business_date'] ?? ibems_business_date()),
            'status' => (string) ($session['status'] ?? 'open'),
            'opening_cash' => (float) ($session['opening_cash'] ?? 0),
            'opening_ecash' => (float) ($session['opening_ecash'] ?? 0),
            'opening_note' => (string) ($session['opening_note'] ?? ''),
            'opened_by' => $openedBy !== null ? (int) $openedBy : null,
            'opened_at' => (string) ($session['opened_at'] ?? ''),
            'expected_cash' => ($session['expected_cash'] ?? null) !== null ? (float) $session['expected_cash'] : (float) ($expected['expected_cash_on_hand'] ?? 0),
            'expected_ecash' => ($session['expected_ecash'] ?? null) !== null ? (float) $session['expected_ecash'] : (float) ($expected['expected_ecash_on_hand'] ?? 0),
            'counted_cash' => ($session['counted_cash'] ?? null) !== null ? (float) $session['counted_cash'] : null,
            'counted_ecash' => ($session['counted_ecash'] ?? null) !== null ? (float) $session['counted_ecash'] : null,
            'variance_cash' => ($session['variance_cash'] ?? null) !== null ? (float) $session['variance_cash'] : null,
            'variance_ecash' => ($session['variance_ecash'] ?? null) !== null ? (float) $session['variance_ecash'] : null,
            'variance_status' => (string) ($session['variance_status'] ?? 'balanced'),
            'review_status' => (string) ($session['review_status'] ?? 'not_required'),
            'closing_note' => (string) ($session['closing_note'] ?? ''),
            'closed_by' => $closedBy !== null ? (int) $closedBy : null,
            'closed_at' => (string) ($session['closed_at'] ?? ''),
            'cash_sales' => (float) ($expected['cash_sales'] ?? 0),
            'ecash_sales' => (float) ($expected['ecash_sales'] ?? 0),
            'debt_sales' => (float) ($expected['debt_sales'] ?? 0),
            'cash_debt_payments' => (float) ($expected['cash_debt_payments'] ?? 0),
            'ecash_debt_payments' => (float) ($expected['ecash_debt_payments'] ?? 0),
            'cash_in' => (float) ($expected['cash_in'] ?? 0),
            'cash_out' => (float) ($expected['cash_out'] ?? 0),
            'ecash_in' => (float) ($expected['ecash_in'] ?? 0),
            'ecash_out' => (float) ($expected['ecash_out'] ?? 0),
            'expected_cash_on_hand' => (float) ($expected['expected_cash_on_hand'] ?? 0),
            'expected_ecash_on_hand' => (float) ($expected['expected_ecash_on_hand'] ?? 0),
            'expected_total_on_hand' => (float) ($expected['expected_total_on_hand'] ?? 0),
            'payment_account_balances' => array_values($expected['payment_account_balances'] ?? []),
            'unassigned_payment_balances' => array_values($expected['unassigned_payment_balances'] ?? []),
            'payment_method_sales' => array_values($expected['payment_method_sales'] ?? []),
            'payment_method_collections' => array_values($expected['payment_method_collections'] ?? []),
        ];
    }

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
