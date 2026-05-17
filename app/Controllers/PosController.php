<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\TransactionItemModel;
use App\Models\TransactionModel;
use App\Models\UserModel;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PosService;

class PosController extends BaseController
{
    public function index()
    {
        $storeModel = new StoreModel();
        $productModel = new ProductModel();
        $user = current_user();
        $stores = [];
        $products = [];

        if (($user['role'] ?? null) === 'STORE_SYSTEM') {
            $stores = $storeModel
                ->where('officer_id', current_user_id())
                ->where('is_active', 1)
                ->findAll();

            if ($stores !== []) {
                $storeIds = array_map(static fn($s) => (int) $s['id'], $stores);
                $products = $productModel
                    ->whereIn('store_id', $storeIds)
                    ->where('is_active', 1)
                    ->findAll();
            }
        } else {
            $stores = $storeModel->where('is_active', 1)->findAll();
            $products = $productModel->where('is_active', 1)->findAll();
        }

        return view('pos/index', [
            'title' => 'POS',
            'stores' => $stores,
            'products' => $products,
        ]);
    }

    public function scan()
    {
        $qrToken = trim((string) $this->request->getPost('qr_token'));
        if ($qrToken === '') {
            return $this->response->setStatusCode(422)->setJSON(['message' => 'QR token is required.']);
        }

        $user = (new UserModel())->where('qr_token', $qrToken)->where('is_active', 1)->first();
        if ($user === null) {
            return $this->response->setStatusCode(404)->setJSON(['message' => 'QR token not found.']);
        }

        return $this->response->setJSON([
            'id' => $user['id'],
            'name' => $user['name'],
            'user_type' => $user['user_type'],
            'can_debt' => in_array($user['user_type'], ['faculty', 'staff'], true),
        ]);
    }

    public function createTransaction()
    {
        $payload = [
            'client_txn_id' => (string) $this->request->getPost('client_txn_id'),
            'user_id' => $this->request->getPost('user_id') !== null && $this->request->getPost('user_id') !== '' ? (int) $this->request->getPost('user_id') : null,
            'customer_type' => (string) $this->request->getPost('customer_type'),
            'store_id' => (int) $this->request->getPost('store_id'),
            'payment_method' => (string) $this->request->getPost('payment_method'),
            'other_payment_label' => $this->request->getPost('other_payment_label') ?: null,
            'walkin_note' => $this->request->getPost('walkin_note') ?: null,
            'items' => json_decode((string) $this->request->getPost('items_json'), true) ?? [],
        ];

        $user = current_user();
        if (($user['role'] ?? null) === 'STORE_SYSTEM') {
            $storeId = (int) $payload['store_id'];
            $allowedStore = (new StoreModel())
                ->where('id', $storeId)
                ->where('officer_id', current_user_id())
                ->where('is_active', 1)
                ->first();
            if ($allowedStore === null) {
                return $this->response->setStatusCode(403)->setJSON(['message' => 'You can only transact for your assigned store.']);
            }
        }

        if ($payload['client_txn_id'] === '') {
            $payload['client_txn_id'] = bin2hex(random_bytes(16));
        }

        try {
            $transaction = (new PosService())->createTransaction($payload);
            (new NotificationService())->sendTransactionEmail(
                $transaction['user_id'] !== null ? (int) $transaction['user_id'] : null,
                (int) $transaction['id'],
                (string) $transaction['reference_no']
            );
            (new AuditService())->log(current_user_id(), 'CREATE', 'transactions', (string) $transaction['id'], $payload);

            return $this->response->setJSON([
                'message' => 'Transaction saved.',
                'reference_no' => $transaction['reference_no'],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(422)->setJSON(['message' => $e->getMessage()]);
        }
    }

    public function receipt(string $referenceNo)
    {
        $transaction = (new TransactionModel())->where('reference_no', $referenceNo)->first();
        if ($transaction === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Receipt not found');
        }

        $items = (new TransactionItemModel())->where('transaction_id', $transaction['id'])->findAll();

        return view('pos/receipt', [
            'title' => 'Receipt',
            'transaction' => $transaction,
            'items' => $items,
        ]);
    }
}
