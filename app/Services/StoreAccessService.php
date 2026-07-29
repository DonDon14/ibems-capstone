<?php

namespace App\Services;

use App\Models\StoreModel;

/**
 * Centralizes active-store scoping for portal and API workflows.
 *
 * This service returns data rather than HTTP responses so controllers remain
 * responsible for status codes and response contracts.
 */
class StoreAccessService
{
    public function __construct(private ?StoreModel $storeModel = null)
    {
        $this->storeModel ??= new StoreModel();
    }

    public function accessibleStores(int $userId, string $role): array
    {
        if ($userId <= 0) {
            return [];
        }

        return $this->storeModel->getAccessibleStores($userId, strtoupper(trim($role)));
    }

    public function resolve(int $userId, string $role, int $requestedStoreId = 0): ?array
    {
        $normalizedRole = strtoupper(trim($role));
        $stores = $this->accessibleStores($userId, $normalizedRole);
        if ($stores === []) {
            return null;
        }

        $storeId = $requestedStoreId > 0
            ? $requestedStoreId
            : (int) ($stores[0]['id'] ?? 0);

        if ($storeId <= 0 || !$this->storeModel->canUserAccessStore($userId, $normalizedRole, $storeId)) {
            return null;
        }

        foreach ($stores as $store) {
            if ((int) ($store['id'] ?? 0) === $storeId) {
                return $store;
            }
        }

        return [
            'id' => $storeId,
            'store_name' => 'Store',
        ];
    }
}
