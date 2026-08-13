<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreDaySessionModel;
use App\Models\StoreModel;
use App\Models\StorePaymentMethodModel;
use App\Models\TransactionItemModel;
use App\Models\TransactionModel;
use App\Models\UserModel;
use Config\Database;
use Config\Services;

class TransactionService
{
    public function createTransaction(array $request, int $actorId, string $role): array
    {
        $validation = Services::validation();
        $validation->setRules([
            'store_id' => 'required|is_natural_no_zero',
            'payment_method' => 'required|max_length[50]',
            'customer_user_id' => 'permit_empty|is_natural_no_zero',
            'customer_type' => 'permit_empty|in_list[walk_in,faculty,staff,student]',
            'debt_pin' => 'permit_empty|regex_match[/^[0-9]{4,6}$/]',
            'items' => 'required',
        ]);

        if (!$validation->run($request)) {
            return $this->error($validation->getErrors()[array_key_first($validation->getErrors())] ?? 'Invalid transaction payload.');
        }

        $storeId = (int) ($request['store_id'] ?? 0);
        $paymentMethod = strtolower(trim((string) ($request['payment_method'] ?? '')));
        $customerUserId = isset($request['customer_user_id']) ? (int) $request['customer_user_id'] : null;
        $customerType = (string) ($request['customer_type'] ?? 'walk_in');
        $debtPin = trim((string) ($request['debt_pin'] ?? ''));
        $cashReceived = isset($request['cash_received']) ? (float) $request['cash_received'] : null;
        $items = $request['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return $this->error('At least one transaction item is required.');
        }

        $itemValidation = Services::validation();
        $itemValidation->setRules([
            'product_id' => 'required|is_natural_no_zero',
            'qty' => 'required|is_natural_no_zero',
        ]);

        $aggregatedItems = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || !$itemValidation->run($item)) {
                return $this->error('Invalid item at line ' . ($index + 1) . '.');
            }

            $productId = (int) $item['product_id'];
            $qty = (int) $item['qty'];
            $aggregatedItems[$productId] = ($aggregatedItems[$productId] ?? 0) + $qty;
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->error('You cannot create transactions for this store.', 403);
        }

        $daySessionModel = new StoreDaySessionModel();
        $todaySession = $daySessionModel->getToday($storeId);
        if (!$todaySession || (string) ($todaySession['status'] ?? '') !== 'open') {
            return $this->error('Store day is not open. Open today\'s store session before creating transactions.');
        }

        $paymentMethodModel = new StorePaymentMethodModel();
        $paymentMethodModel->ensureDefaults($storeId);
        if ($paymentMethod === '' || !$paymentMethodModel->isAllowedForStore($storeId, $paymentMethod)) {
            return $this->error('Invalid or disabled payment method.');
        }

        $userModel = new UserModel();
        $customer = null;
        if ($customerUserId) {
            $customer = $userModel->getActiveUserById($customerUserId);
            if (!$customer) {
                return $this->error('Selected customer not found.');
            }
            $customerType = (string) ($customer['user_type'] ?? 'walk_in');
        }

        $productModel = new ProductModel();
        $productSnapshot = [];
        $totalAmount = 0.0;
        foreach ($aggregatedItems as $productId => $qty) {
            $product = $productModel->find($productId);
            if (!$product) {
                return $this->error('Product not found.');
            }
            if ((int) ($product['store_id'] ?? 0) !== $storeId) {
                return $this->error('Product does not belong to this store.');
            }
            if ((int) ($product['is_active'] ?? 0) !== 1) {
                return $this->error('Product is inactive: ' . ($product['name'] ?? ('#' . $productId)));
            }

            $price = (float) ($product['price'] ?? 0);
            $lineTotal = $price * $qty;
            $totalAmount += $lineTotal;

            $productSnapshot[$productId] = [
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $price,
                'line_total' => $lineTotal,
            ];
        }

        $changeDue = null;
        if ($paymentMethod === 'cash') {
            if ($cashReceived === null || !is_finite($cashReceived) || $cashReceived < $totalAmount) {
                return $this->error('Cash received must cover the transaction total.');
            }
            $changeDue = round($cashReceived - $totalAmount, 2);
        }

        $balanceModel = new BalanceModel();
        $debtCashbookModel = new DebtCashbookEntryModel();
        $debtBalanceBefore = null;
        if ($paymentMethod === 'debt') {
            if (!$customerUserId) {
                return $this->error('Please select a faculty/staff customer for debt transactions.');
            }
            if (!in_array($customerType, ['faculty', 'staff'], true)) {
                return $this->error('Only faculty/staff can use debt payment.');
            }
            if ($debtPin === '') {
                return $this->error('Enter the customer debt PIN to authorize this debt transaction.');
            }
            if (!$customer || trim((string) ($customer['debt_pin_hash'] ?? '')) === '') {
                return $this->error('Selected customer has no debt PIN set. Ask them to set it in their user portal first.');
            }
            $pinAuthorization = (new DebtPinAuthorizationService())->authorize(
                $customerUserId,
                (string) $customer['debt_pin_hash'],
                $debtPin,
                $actorId,
                $storeId,
                $totalAmount
            );
            if (($pinAuthorization['status'] ?? 'error') !== 'success') {
                return $pinAuthorization;
            }
        }

        $transactionModel = new TransactionModel();
        $transactionItemModel = new TransactionItemModel();
        $movementModel = new InventoryMovementModel();
        $auditLogModel = new AuditLogModel();

        $db = Database::connect();
        $db->transBegin();

        $clientTxnId = uniqid('txn_', true);
        $createdAt = date('Y-m-d H:i:s');

        try {
            if ($paymentMethod === 'debt') {
                $debtBalanceBefore = $balanceModel->getBalanceForUpdate((int) $customerUserId);
                if (!$debtBalanceBefore) {
                    throw new \RuntimeException('Balance record not found.');
                }

                $availableCredit = (float) $debtBalanceBefore['credit_limit']
                    - (float) $debtBalanceBefore['current_debt'];
                if ($availableCredit < $totalAmount) {
                    throw new \RuntimeException('Insufficient credit.');
                }
            }

            $txnId = $transactionModel->insert([
                'client_txn_id' => $clientTxnId,
                'user_id' => $customerUserId,
                'customer_type' => $customerType,
                'store_id' => $storeId,
                'amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'status' => 'completed',
                'created_at' => $createdAt,
            ]);

            if (!$txnId) {
                throw new \RuntimeException('Failed to create transaction.');
            }

            foreach ($productSnapshot as $row) {
                $product = $row['product'];
                $qty = (int) $row['qty'];

                if (!$transactionItemModel->insert([
                    'transaction_id' => $txnId,
                    'product_id' => (int) $product['id'],
                    'qty' => $qty,
                    'unit_price' => $row['unit_price'],
                    'line_total' => $row['line_total'],
                    'created_at' => $createdAt,
                ])) {
                    throw new \RuntimeException('Failed to create transaction item.');
                }

                // Atomic stock deduction to avoid race conditions during concurrent checkout.
                if (!$productModel->deductStockIfAvailable((int) $product['id'], $qty)) {
                    throw new \RuntimeException('Insufficient stock for ' . (string) ($product['name'] ?? 'product') . '.');
                }

                if (!$movementModel->insert([
                    'product_id' => (int) $product['id'],
                    'store_id' => $storeId,
                    'type' => 'sale',
                    'qty' => $qty,
                    'reason' => 'POS sale',
                    'txn_id' => $txnId,
                    'created_at' => $createdAt,
                ])) {
                    throw new \RuntimeException('Failed to create inventory movement.');
                }
            }

            if (!$auditLogModel->insert([
                'actor_id' => $actorId,
                'action' => 'CREATE_TRANSACTION',
                'entity' => 'transactions',
                'entity_id' => $txnId,
                'payload_json' => json_encode([
                    'customer_user_id' => $customerUserId,
                    'customer_type' => $customerType,
                    'store_id' => $storeId,
                    'payment_method' => $paymentMethod,
                    'amount' => $totalAmount,
                    'cash_received' => $cashReceived,
                    'change_due' => $changeDue,
                    'items' => array_values(array_map(static function (int $productId, int $qty): array {
                        return ['product_id' => $productId, 'qty' => $qty];
                    }, array_keys($aggregatedItems), $aggregatedItems)),
                ]),
                'created_at' => $createdAt,
            ])) {
                throw new \RuntimeException('Failed to write audit log.');
            }

            if ($paymentMethod === 'debt') {
                if (!$balanceModel->addDebt((int) $customerUserId, $totalAmount)) {
                    throw new \RuntimeException('Failed to update debt balance.');
                }

                $debtBalanceAfter = $balanceModel->getBalanceByUserId((int) $customerUserId);
                $creditLimit = (float) ($debtBalanceAfter['credit_limit'] ?? $debtBalanceBefore['credit_limit'] ?? 0);
                $debtBefore = (float) ($debtBalanceBefore['current_debt'] ?? 0);
                $debtAfter = (float) ($debtBalanceAfter['current_debt'] ?? ($debtBefore + $totalAmount));
                $availableAfter = max(0, $creditLimit - $debtAfter);

                $debtCashbookModel->insert([
                    'user_id' => $customerUserId,
                    'entry_type' => 'debt_purchase',
                    'direction' => 'debit',
                    'amount' => $totalAmount,
                    'debt_before' => $debtBefore,
                    'debt_after' => $debtAfter,
                    'credit_limit_snapshot' => $creditLimit,
                    'available_credit_snapshot' => $availableAfter,
                    'reference_type' => 'transaction',
                    'reference_id' => $txnId,
                    'actor_id' => $actorId > 0 ? $actorId : null,
                    'remarks' => 'POS debt purchase',
                    'meta_json' => json_encode([
                        'store_id' => $storeId,
                        'payment_method' => 'debt',
                        'transaction_id' => (int) $txnId,
                    ]),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            if (!$db->transStatus()) {
                throw new \RuntimeException('Transaction failed.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->error($e->getMessage() !== '' ? $e->getMessage() : 'Transaction failed.');
        }

        return [
            'status' => 'success',
            'transaction_id' => (int) $txnId,
            'client_txn_id' => $clientTxnId,
            'created_at' => $createdAt,
            'total_amount' => $totalAmount,
            'cash_received' => $cashReceived,
            'change_due' => $changeDue,
            'code' => 200,
        ];
    }

    private function error(string $message, int $code = 400): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
    }
}
