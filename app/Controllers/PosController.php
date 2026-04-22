<?php

namespace App\Controllers;

use App\Models\TransactionModel;
use App\Models\TransactionItemModel;
use App\Models\ProductModel;
use App\Models\BalanceModel;
use App\Models\InventoryMovementModel;
use App\Models\AuditLogModel;
use App\Models\StoreModel;
use App\Models\StorePaymentMethodModel;
use App\Models\StoreOpeningBalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;

class PosController extends ResourceController
{
    public function createTransaction()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();

        $result = $this->processTransaction($request);

        return $this->response->setJSON($result);
    }

    public function testTransaction()
    {
        $data = [
            'user_id' => null,
            'customer_type' => 'walk_in',
            'store_id' => 1,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => 1,
                    'qty' => 2,
                ]
            ]
        ];

        $result = $this->processTransaction($data);

        return $this->response->setJSON($result);
    }

    private function processTransaction(array $request)
    {
        $db = \Config\Database::connect();

        if (!session()->get('logged_in')) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized. Please login.'
            ];
        }

        $actorId       = (int) session()->get('user_id');
        $role          = (string) session()->get('role');
        $customerUserId = isset($request['customer_user_id']) ? (int) $request['customer_user_id'] : null;
        $customerType  = $request['customer_type'] ?? 'walk_in';
        $storeId       = $request['store_id'] ?? null;
        $items         = $request['items'] ?? [];
        $paymentMethod = $request['payment_method'] ?? null;

        $productModel   = new ProductModel();
        $balanceModel   = new BalanceModel();
        $txnModel       = new TransactionModel();
        $itemModel      = new TransactionItemModel();
        $movementModel  = new InventoryMovementModel();
        $auditLogModel  = new AuditLogModel();
        $storeModel     = new StoreModel();
        $paymentMethodModel = new StorePaymentMethodModel();
        $openingBalanceModel = new StoreOpeningBalanceModel();
        $cashbookModel = new DebtCashbookEntryModel();
        $userModel      = new UserModel();
        $debtBalanceBefore = null;

        if (!$customerType || !$storeId || !$paymentMethod || empty($items)) {
            return [
                'status' => 'error',
                'message' => 'Missing required transaction data.'
            ];
        }

        if (!$storeModel->canUserAccessStore($actorId, $role, (int) $storeId)) {
            return [
                'status' => 'error',
                'message' => 'You cannot create transactions for this store.'
            ];
        }

        $opening = $openingBalanceModel->getInitialByStore((int) $storeId);
        if (!$opening) {
            return [
                'status' => 'error',
                'message' => 'Initial opening balance is not set. Please set it first.'
            ];
        }

        $paymentMethodModel->ensureDefaults((int) $storeId);
        $paymentMethod = strtolower(trim((string) $paymentMethod));
        if ($paymentMethod === '' || !$paymentMethodModel->isAllowedForStore((int) $storeId, $paymentMethod)) {
            return [
                'status' => 'error',
                'message' => 'Invalid or disabled payment method.'
            ];
        }

        if ($customerUserId) {
            $customer = $userModel->getActiveUserById($customerUserId);
            if (!$customer) {
                return [
                    'status' => 'error',
                    'message' => 'Selected customer not found.'
                ];
            }

            $customerType = $customer['user_type'] ?? 'walk_in';
        }

        $totalAmount = 0;

        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            $qty = $item['qty'] ?? 0;

            if (!$productId || $qty <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid item data.'
                ];
            }

            $product = $productModel->find($productId);

            if (!$product) {
                return [
                    'status' => 'error',
                    'message' => 'Product not found.'
                ];
            }

            if ((int) $product['store_id'] !== (int) $storeId) {
                return [
                    'status' => 'error',
                    'message' => 'Product does not belong to this store.'
                ];
            }

            if (!$productModel->hasEnoughStock($product['id'], $qty)) {
                return [
                    'status' => 'error',
                    'message' => 'Insufficient stock for ' . $product['name']
                ];
            }

            $totalAmount += $product['price'] * $qty;
        }

        if ($paymentMethod === 'debt') {
            if (!$customerUserId) {
                return [
                    'status' => 'error',
                    'message' => 'Please select a faculty/staff customer for debt transactions.'
                ];
            }

            if (!in_array($customerType, ['faculty', 'staff'], true)) {
                return [
                    'status' => 'error',
                    'message' => 'Only faculty/staff can use debt payment.'
                ];
            }

            if (!$balanceModel->canUseCredit($customerUserId, $totalAmount)) {
                return [
                    'status' => 'error',
                    'message' => 'Insufficient credit.'
                ];
            }
            $debtBalanceBefore = $balanceModel->getBalanceByUserId($customerUserId);
        }

        $db->transBegin();

        try {
        $txnId = $txnModel->insert([
            'client_txn_id'   => uniqid(),
            'user_id'         => $customerUserId,
            'customer_type'   => $customerType,
            'store_id'        => $storeId,
            'amount'          => $totalAmount,
            'payment_method'  => $paymentMethod,
            'status'          => 'completed',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        if (!$txnId) {
            throw new \RuntimeException('Failed to create transaction.');
        }

        foreach ($items as $item) {
            $product = $productModel->find($item['product_id']);
            $qty = $item['qty'];

            $itemInserted = $itemModel->insert([
                'transaction_id' => $txnId,
                'product_id'     => $product['id'],
                'qty'            => $qty,
                'unit_price'     => $product['price'],
                'line_total'     => $product['price'] * $qty,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            if (!$itemInserted) {
                throw new \RuntimeException('Failed to create transaction item.');
            }

            $deducted = $productModel->deductStock($product['id'], $qty);
            if (!$deducted) {
                throw new \RuntimeException('Failed to deduct product stock.');
            }

            $movementInserted = $movementModel->insert([
                'product_id' => $product['id'],
                'store_id'   => $storeId,
                'type'       => 'sale',
                'qty'        => $qty,
                'reason'     => 'POS sale',
                'txn_id'     => $txnId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$movementInserted) {
                throw new \RuntimeException('Failed to create inventory movement.');
            }
        }

        $auditInserted = $auditLogModel->insert([
            'actor_id' => $actorId,
            'action'       => 'CREATE_TRANSACTION',
            'entity'       => 'transactions',
            'entity_id'    => $txnId,
            'payload_json' => json_encode([
                'customer_user_id' => $customerUserId,
                'customer_type'  => $customerType,
                'store_id'       => $storeId,
                'payment_method' => $paymentMethod,
                'amount'         => $totalAmount,
                'items'          => $items,
            ]),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        if (!$auditInserted) {
            throw new \RuntimeException('Failed to write audit log.');
        }

        if ($paymentMethod === 'debt') {
            $debtAdded = $balanceModel->addDebt($customerUserId, $totalAmount);
            if (!$debtAdded) {
                throw new \RuntimeException('Failed to update debt balance.');
            }

            $debtBalanceAfter = $balanceModel->getBalanceByUserId($customerUserId);
            $creditLimit = (float) ($debtBalanceAfter['credit_limit'] ?? $debtBalanceBefore['credit_limit'] ?? 0);
            $debtBefore = (float) ($debtBalanceBefore['current_debt'] ?? 0);
            $debtAfter = (float) ($debtBalanceAfter['current_debt'] ?? ($debtBefore + $totalAmount));
            $availableAfter = max(0, $creditLimit - $debtAfter);

            $cashbookModel->insert([
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
                    'store_id' => (int) $storeId,
                    'payment_method' => 'debt',
                    'transaction_id' => (int) $txnId,
                ]),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if (!$db->transStatus()) {
            throw new \RuntimeException('Transaction failed.');
        }
        $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return [
                'status' => 'error',
                'message' => 'Transaction failed.'
            ];
        }

        return [
            'status' => 'success',
            'transaction_id' => $txnId,
            'total_amount' => $totalAmount
        ];
    }
}
