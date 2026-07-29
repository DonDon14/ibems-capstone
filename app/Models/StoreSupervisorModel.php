<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreSupervisorModel extends Model
{
    protected $table            = 'store_supervisors';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'store_id',
        'user_id',
        'created_at',
        'updated_at',
    ];

    public function getSupervisorIdsByStore(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }

        $rows = $this->select('user_id')
            ->where('store_id', $storeId)
            ->findAll();

        return array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $rows)));
    }

    public function getStoreIdsBySupervisor(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = $this->select('store_id')
            ->where('user_id', $userId)
            ->findAll();

        return array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['store_id'] ?? 0), $rows)));
    }

    public function syncStoreSupervisors(int $storeId, array $supervisorIds): void
    {
        if ($storeId <= 0) {
            return;
        }

        $supervisorIds = array_values(array_unique(array_filter(array_map('intval', $supervisorIds), static fn(int $id): bool => $id > 0)));
        $existingRows = $this->where('store_id', $storeId)->findAll();
        $existingIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $existingRows)));

        $toDelete = array_values(array_diff($existingIds, $supervisorIds));
        $toInsert = array_values(array_diff($supervisorIds, $existingIds));

        if ($toDelete !== []) {
            $this->where('store_id', $storeId)->whereIn('user_id', $toDelete)->delete();
        }

        $now = date('Y-m-d H:i:s');
        foreach ($toInsert as $userId) {
            $this->insert([
                'store_id' => $storeId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
