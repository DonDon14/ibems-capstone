<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreModel extends Model
{
    protected $table            = 'stores';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'store_name',
        'officer_id',
        'logo_url',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
        'reactivated_at',
        'reactivated_by',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'id'         => 'integer',
        'officer_id' => '?integer',
    ];

    protected $useTimestamps = false;

    public function getActiveStores(): array
    {
        return $this->where('is_active', true)
                    ->findAll();
    }

    public function getStoreByOfficerId(int $officerId): ?array
    {
        return $this->where('officer_id', $officerId)
                    ->first();
    }

    public function getAccessibleStores(int $userId, string $role): array
    {
        $builder = $this->where('is_active', true);

        if ($role === 'STORE_SYSTEM') {
            $builder->where('officer_id', $userId);
        } elseif ($role === 'STORE_SUPERVISOR') {
            $storeIds = (new StoreSupervisorModel())->getStoreIdsBySupervisor($userId);
            if ($storeIds === []) {
                return [];
            }
            $builder->whereIn('id', $storeIds);
        } elseif ($role !== 'ADMIN') {
            return [];
        }

        return $builder->orderBy('store_name', 'ASC')->findAll();
    }

    public function canUserAccessStore(int $userId, string $role, int $storeId): bool
    {
        $builder = $this->where('id', $storeId)->where('is_active', true);

        if ($role === 'STORE_SYSTEM') {
            $builder->where('officer_id', $userId);
        } elseif ($role === 'STORE_SUPERVISOR') {
            if ($userId <= 0) {
                return false;
            }
            $assigned = (new StoreSupervisorModel())
                ->where('store_id', $storeId)
                ->where('user_id', $userId)
                ->countAllResults();
            if ($assigned <= 0) {
                return false;
            }
        } elseif ($role !== 'ADMIN') {
            return false;
        }

        return $builder->countAllResults() > 0;
    }
}
