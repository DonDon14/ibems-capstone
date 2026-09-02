<?php

namespace App\Services;

use App\Models\StoreModel;
use Config\Database;

final class StoreInventoryQueryService
{
    /** @param array<string, mixed> $filters @return array{code:int,payload:array<string, mixed>} */
    public function movements(int $userId, string $role, array $filters): array
    {
        $storeModel = new StoreModel();
        $stores = $storeModel->getAccessibleStores($userId, $role);
        if ($stores === []) {
            return $this->error(403, 'No accessible store found.');
        }

        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) $stores[0]['id'];
        }
        if (!$storeModel->canUserAccessStore($userId, $role, $storeId)) {
            return $this->error(403, 'You cannot access this store.');
        }

        $type = trim((string) ($filters['type'] ?? ''));
        $productId = (int) ($filters['product_id'] ?? 0);
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 8)));
        $query = Database::connect()->table('inventory_movements im')
            ->select('im.id, im.created_at, im.type, im.qty, im.unit_cost, im.total_cost, im.expected_profit, im.reason, p.id AS product_id, p.name AS product_name, p.sku, p.price')
            ->join('products p', 'p.id = im.product_id', 'inner')
            ->where('im.store_id', $storeId);
        if ($type !== '' && in_array($type, ['sale', 'restock', 'adjustment'], true)) {
            $query->where('im.type', $type);
        }
        if ($productId > 0) {
            $query->where('im.product_id', $productId);
        }
        if ($dateFrom !== '') {
            $query->where('im.created_at >=', ibems_business_day_utc_bounds($dateFrom)['start']);
        }
        if ($dateTo !== '') {
            $query->where('im.created_at <=', ibems_business_day_utc_bounds($dateTo)['end']);
        }

        $rows = $query->orderBy('im.id', 'DESC')->limit($limit)->get()->getResultArray();
        $movements = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'created_at' => $row['created_at'],
            'type' => $row['type'],
            'qty' => (int) $row['qty'],
            'unit_cost' => $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null,
            'total_cost' => $row['total_cost'] !== null ? (float) $row['total_cost'] : null,
            'expected_profit' => $row['expected_profit'] !== null ? (float) $row['expected_profit'] : null,
            'reason' => $row['reason'],
            'product' => [
                'id' => (int) $row['product_id'],
                'name' => $row['product_name'],
                'sku' => $row['sku'],
                'price' => (float) $row['price'],
            ],
        ], $rows);

        return ['code' => 200, 'payload' => ['status' => 'success', 'store_id' => $storeId, 'movements' => $movements]];
    }

    /** @return array{code:int,payload:array{status:string,message:string}} */
    private function error(int $code, string $message): array
    {
        return ['code' => $code, 'payload' => ['status' => 'error', 'message' => $message]];
    }
}
