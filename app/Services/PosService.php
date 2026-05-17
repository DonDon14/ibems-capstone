<?php

namespace App\Services;

use App\Models\BalanceModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\TransactionItemModel;
use App\Models\TransactionModel;
use App\Models\UserModel;

class PosService
{
    private CreditService $creditService;
    private ReferenceService $referenceService;

    private const ALLOWED_CUSTOMER_TYPES = ['faculty', 'staff', 'student', 'walk_in'];
    private const ALLOWED_PAYMENT_METHODS = ['cash', 'gcash', 'card', 'bank_transfer', 'other', 'debt', 'advance_payment'];

    public function __construct()
    {
        $this->creditService = new CreditService();
        $this->referenceService = new ReferenceService();
    }

    public function createTransaction(array $payload): array
    {
        $txnModel = new TransactionModel();
        $clientTxnId = trim((string) ($payload['client_txn_id'] ?? ''));
        if ($clientTxnId === '') {
            throw new \RuntimeException('client_txn_id is required.');
        }

        $existing = $txnModel->where('client_txn_id', $clientTxnId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $productModel = new ProductModel();
            $itemModel = new TransactionItemModel();
            $movementModel = new InventoryMovementModel();
            $balanceModel = new BalanceModel();
            $userModel = new UserModel();
            $storeModel = new StoreModel();

            $storeId = (int) ($payload['store_id'] ?? 0);
            $store = $storeModel->where('id', $storeId)->where('is_active', 1)->first();
            if ($store === null) {
                throw new \RuntimeException('Invalid or inactive store.');
            }

            $customerType = strtolower((string) ($payload['customer_type'] ?? ''));
            if (! in_array($customerType, self::ALLOWED_CUSTOMER_TYPES, true)) {
                throw new \RuntimeException('Invalid customer type.');
            }

            $paymentMethod = strtolower((string) ($payload['payment_method'] ?? ''));
            if (! in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
                throw new \RuntimeException('Invalid payment method.');
            }

            $userId = isset($payload['user_id']) && $payload['user_id'] !== null ? (int) $payload['user_id'] : null;
            $user = null;
            if ($customerType === 'walk_in') {
                $userId = null;
            } else {
                if ($userId === null || $userId <= 0) {
                    throw new \RuntimeException('User ID is required for non walk-in transactions.');
                }

                $user = $userModel->where('id', $userId)->where('is_active', 1)->first();
                if ($user === null) {
                    throw new \RuntimeException('User not found or inactive.');
                }

                if (($user['user_type'] ?? null) !== $customerType) {
                    throw new \RuntimeException('Customer type does not match selected user profile.');
                }
            }

            if (in_array($paymentMethod, ['debt', 'advance_payment'], true) && ! in_array($customerType, ['faculty', 'staff'], true)) {
                throw new \RuntimeException('Debt and advance payment are only allowed for faculty and staff.');
            }

            $otherPaymentLabel = trim((string) ($payload['other_payment_label'] ?? ''));
            if ($paymentMethod === 'other' && $otherPaymentLabel === '') {
                throw new \RuntimeException('Other payment label is required.');
            }

            $items = $payload['items'] ?? [];
            if (! is_array($items) || $items === []) {
                throw new \RuntimeException('At least one cart item is required.');
            }

            $resolvedItems = [];
            $computedAmount = 0.0;
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['qty'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    throw new \RuntimeException('Invalid cart item values.');
                }

                $product = $productModel->where('id', $productId)->where('is_active', 1)->first();
                if ($product === null) {
                    throw new \RuntimeException('Product not found or inactive.');
                }

                if ((int) $product['store_id'] !== $storeId) {
                    throw new \RuntimeException('Product does not belong to selected store.');
                }

                if ((int) $product['stock_qty'] < $qty) {
                    throw new \RuntimeException('Insufficient stock for ' . $product['name']);
                }

                $lineTotal = $qty * (float) $product['price'];
                $computedAmount += $lineTotal;
                $resolvedItems[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            if ($computedAmount <= 0) {
                throw new \RuntimeException('Computed transaction amount is invalid.');
            }

            if ($paymentMethod === 'debt' && $userId !== null) {
                $balance = $balanceModel->find($userId);
                if ($balance === null) {
                    throw new \RuntimeException('No balance profile found for this user.');
                }

                $canBorrow = $this->creditService->canBorrow((float) $balance['current_debt'], (float) $balance['credit_limit'], $computedAmount);
                if (! $canBorrow) {
                    throw new \RuntimeException('Insufficient available credit.');
                }
            }

            $txn = [
                'client_txn_id' => $clientTxnId,
                'user_id' => $userId,
                'customer_type' => $customerType,
                'store_id' => $storeId,
                'amount' => $computedAmount,
                'payment_method' => $paymentMethod,
                'other_payment_label' => $otherPaymentLabel !== '' ? $otherPaymentLabel : null,
                'walkin_note' => trim((string) ($payload['walkin_note'] ?? '')) ?: null,
                'status' => 'synced',
                'reference_no' => $this->referenceService->generateReference($storeId),
                'created_at' => date('Y-m-d H:i:s'),
                'synced_at' => date('Y-m-d H:i:s'),
            ];

            $txnId = $txnModel->insert($txn, true);

            foreach ($resolvedItems as $line) {
                $product = $line['product'];
                $qty = $line['qty'];

                $itemModel->insert([
                    'transaction_id' => $txnId,
                    'product_id' => $product['id'],
                    'qty' => $qty,
                    'unit_price' => $product['price'],
                    'line_total' => $line['line_total'],
                ]);

                $productModel->update($product['id'], [
                    'stock_qty' => (int) $product['stock_qty'] - $qty,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $movementModel->insert([
                    'product_id' => $product['id'],
                    'store_id' => $storeId,
                    'type' => 'sale',
                    'qty' => $qty,
                    'reason' => 'POS sale',
                    'txn_id' => $txnId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if ($userId !== null && $paymentMethod === 'debt') {
                $balance = $balanceModel->find($userId);
                $balanceModel->update($userId, [
                    'current_debt' => (float) $balance['current_debt'] + $computedAmount,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if ($userId !== null && $paymentMethod === 'advance_payment') {
                $balance = $balanceModel->find($userId);
                if ($balance !== null) {
                    $newDebt = max(0, (float) $balance['current_debt'] - $computedAmount);
                    $balanceModel->update($userId, [
                        'current_debt' => $newDebt,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaction failed.');
            }

            $db->transCommit();

            return $txnModel->find((int) $txnId);
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
