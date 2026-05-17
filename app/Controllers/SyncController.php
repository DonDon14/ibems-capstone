<?php

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PosService;

class SyncController extends BaseController
{
    public function syncTransactionsBatch()
    {
        $input = $this->request->getJSON(true);
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];

        if ($items === []) {
            return $this->response->setStatusCode(422)->setJSON([
                'message' => 'No sync items provided.',
                'results' => [],
            ]);
        }

        $posService = new PosService();
        $notificationService = new NotificationService();
        $auditService = new AuditService();
        $results = [];

        foreach ($items as $item) {
            $clientTxnId = trim((string) ($item['client_txn_id'] ?? ''));
            if ($clientTxnId === '') {
                $results[] = [
                    'client_txn_id' => null,
                    'status' => 'failed',
                    'reason' => 'Missing client_txn_id',
                ];
                continue;
            }

            try {
                $payload = [
                    'client_txn_id' => $clientTxnId,
                    'user_id' => isset($item['user_id']) && $item['user_id'] !== '' ? (int) $item['user_id'] : null,
                    'customer_type' => (string) ($item['customer_type'] ?? ''),
                    'store_id' => (int) ($item['store_id'] ?? 0),
                    'payment_method' => (string) ($item['payment_method'] ?? ''),
                    'other_payment_label' => $item['other_payment_label'] ?? null,
                    'walkin_note' => $item['walkin_note'] ?? null,
                    'items' => is_array($item['items'] ?? null) ? $item['items'] : [],
                ];

                $transaction = $posService->createTransaction($payload);
                $notificationService->sendTransactionEmail(
                    $transaction['user_id'] !== null ? (int) $transaction['user_id'] : null,
                    (int) $transaction['id'],
                    (string) $transaction['reference_no']
                );

                $auditService->log(current_user_id(), 'SYNC', 'transactions', (string) $transaction['id'], [
                    'client_txn_id' => $clientTxnId,
                ]);

                $results[] = [
                    'client_txn_id' => $clientTxnId,
                    'status' => 'synced',
                    'reference_no' => $transaction['reference_no'],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'client_txn_id' => $clientTxnId,
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $this->response->setJSON([
            'results' => $results,
        ]);
    }
}
