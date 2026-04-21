<?php

namespace App\Controllers;

use App\Models\TransactionModel;
use App\Models\TransactionItemModel;
use App\Models\ProductModel;
use App\Models\BalanceModel;
use CodeIgniter\RESTful\ResourceController;

class PosController extends ResourceController
{
    public function createTransaction()
    {
        $db = \Config\Database::connect();
        $db->transStart();

        $request = $this->request->getJSON(true);

        $userId        = $request['user_id'] ?? null;
        $customerType  = $request['customer_type'];
        $storeId       = $request['store_id'];
        $items         = $request['items'];
        $paymentMethod = $request['payment_method'];

        $productModel  = new ProductModel();
        $balanceModel  = new BalanceModel();
        $txnModel      = new TransactionModel();
        $itemModel     = new TransactionItemModel();

        $totalAmount = 0;

        // 1. Validate items and compute total
        foreach ($items as $item) {
            $product = $productModel->find($item['product_id']);

            if (!$product) {
                return $this->fail("Product not found");
            }

            if (!$productModel->hasEnoughStock($product['id'], $item['qty'])) {
                return $this->fail("Insufficient stock for " . $product['name']);
            }

            $lineTotal = $product['price'] * $item['qty'];
            $totalAmount += $lineTotal;
        }

        // 2. Credit check (if debt)
        if ($paymentMethod === 'debt') {
            if (!$userId) {
                return $this->fail("Walk-in cannot use debt");
            }

            if (!$balanceModel->canUseCredit($userId, $totalAmount)) {
                return $this->fail("Insufficient credit");
            }
        }

        // 3. Create transaction
        $txnId = $txnModel->insert([
            'client_txn_id' => uniqid(),
            'user_id'       => $userId,
            'customer_type' => $customerType,
            'store_id'      => $storeId,
            'amount'        => $totalAmount,
            'payment_method'=> $paymentMethod,
            'status'        => 'completed',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // 4. Save items + deduct stock
        foreach ($items as $item) {
            $product = $productModel->find($item['product_id']);

            $itemModel->insert([
                'transaction_id' => $txnId,
                'product_id'     => $product['id'],
                'qty'            => $item['qty'],
                'unit_price'     => $product['price'],
                'line_total'     => $product['price'] * $item['qty'],
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            $productModel->deductStock($product['id'], $item['qty']);
        }

        // 5. Add debt if needed
        if ($paymentMethod === 'debt') {
            $balanceModel->addDebt($userId, $totalAmount);
        }

        $db->transComplete();

        return $this->respond([
            'status' => 'success',
            'transaction_id' => $txnId,
            'total_amount' => $totalAmount
        ]);
    }
}