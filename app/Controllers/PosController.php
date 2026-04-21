<?php

namespace App\Controllers;

use App\Models\TransactionModel;
use App\Models\TransactionItemModel;
use App\Models\ProductModel;
use App\Models\BalanceModel;
use App\Models\InventoryMovementModel;
use App\Models\AuditLogModel;
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
        $db->transStart();

        if (!session()->get('logged_in')) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized. Please login.'
            ];
        }

        $userId        = session()->get('user_id');
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

        if (!$customerType || !$storeId || !$paymentMethod || empty($items)) {
            return [
                'status' => 'error',
                'message' => 'Missing required transaction data.'
            ];
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

            if (!$productModel->hasEnoughStock($product['id'], $qty)) {
                return [
                    'status' => 'error',
                    'message' => 'Insufficient stock for ' . $product['name']
                ];
            }

            $totalAmount += $product['price'] * $qty;
        }

        if ($paymentMethod === 'debt') {
            if (!$userId) {
                return [
                    'status' => 'error',
                    'message' => 'Walk-in cannot use debt.'
                ];
            }

            if (!$balanceModel->canUseCredit($userId, $totalAmount)) {
                return [
                    'status' => 'error',
                    'message' => 'Insufficient credit.'
                ];
            }
        }

        $txnId = $txnModel->insert([
            'client_txn_id'   => uniqid(),
            'user_id'         => $userId,
            'customer_type'   => $customerType,
            'store_id'        => $storeId,
            'amount'          => $totalAmount,
            'payment_method'  => $paymentMethod,
            'status'          => 'completed',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        foreach ($items as $item) {
            $product = $productModel->find($item['product_id']);
            $qty = $item['qty'];

            $itemModel->insert([
                'transaction_id' => $txnId,
                'product_id'     => $product['id'],
                'qty'            => $qty,
                'unit_price'     => $product['price'],
                'line_total'     => $product['price'] * $qty,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            $productModel->deductStock($product['id'], $qty);

            $movementModel->insert([
                'product_id' => $product['id'],
                'store_id'   => $storeId,
                'type'       => 'sale',
                'qty'        => $qty,
                'reason'     => 'POS sale',
                'txn_id'     => $txnId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $auditLogModel->insert([
            'actor_id' => session()->get('user_id'),
            'action'       => 'CREATE_TRANSACTION',
            'entity'       => 'transactions',
            'entity_id'    => $txnId,
            'payload_json' => json_encode([
                'customer_type'  => $customerType,
                'store_id'       => $storeId,
                'payment_method' => $paymentMethod,
                'amount'         => $totalAmount,
                'items'          => $items,
            ]),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        if ($paymentMethod === 'debt') {
            $balanceModel->addDebt($userId, $totalAmount);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
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
