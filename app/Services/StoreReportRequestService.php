<?php

namespace App\Services;

use App\Models\StoreModel;

final class StoreReportRequestService
{
    public function summary(int $userId, string $role, array $filters): array
    {
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);

        if ($stores === []) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'No accessible store found.',
            ]);
        }

        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }

        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $period = strtolower(trim((string) ($filters['period'] ?? 'month')));
        if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
            $period = 'month';
        }

        $dateFromInput = trim((string) ($filters['date_from'] ?? ''));
        $dateToInput = trim((string) ($filters['date_to'] ?? ''));
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));

        switch ($period) {
            case 'today':
                $fromDate = $today->format('Y-m-d');
                $toDate = $today->format('Y-m-d');
                break;
            case 'week':
                $fromDate = $today->modify('monday this week')->format('Y-m-d');
                $toDate = $today->modify('sunday this week')->format('Y-m-d');
                break;
            case 'custom':
                if (
                    !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateFromInput) ||
                    !preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateToInput)
                ) {
                    return $this->result(400, [
                        'status' => 'error',
                        'message' => 'Custom range requires date_from and date_to in YYYY-MM-DD format.',
                    ]);
                }
                $fromDate = $dateFromInput;
                $toDate = $dateToInput;
                break;
            case 'month':
            default:
                $fromDate = $today->modify('first day of this month')->format('Y-m-d');
                $toDate = $today->modify('last day of this month')->format('Y-m-d');
                break;
        }

        if ($fromDate > $toDate) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'date_from cannot be later than date_to.',
            ]);
        }

        $storeName = 'Store';
        foreach ($stores as $store) {
            if ((int) ($store['id'] ?? 0) === $storeId) {
                $storeName = (string) ($store['store_name'] ?? $storeName);
                break;
            }
        }

        $summary = (new StoreReportService())->summary($storeId, $storeName, $period, $fromDate, $toDate);

        return $this->result(200, $summary);
    }

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
