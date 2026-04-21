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
        'is_active',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'id'         => 'integer',
        'officer_id' => 'integer',
        'is_active'  => 'boolean',
    ];

    protected $useTimestamps = false;

    public function getActiveStores(): array
    {
        return $this->where('is_active', 1)
                    ->findAll();
    }

    public function getStoreByOfficerId(int $officerId): ?array
    {
        return $this->where('officer_id', $officerId)
                    ->first();
    }

    public function getAccessibleStores(int $userId, string $role): array
    {
        $builder = $this->where('is_active', 1);

        if ($role === 'STORE_SYSTEM') {
            $builder->where('officer_id', $userId);
        } elseif ($role !== 'ADMIN') {
            return [];
        }

        return $builder->orderBy('store_name', 'ASC')->findAll();
    }

    public function canUserAccessStore(int $userId, string $role, int $storeId): bool
    {
        $builder = $this->where('id', $storeId)->where('is_active', 1);

        if ($role === 'STORE_SYSTEM') {
            $builder->where('officer_id', $userId);
        } elseif ($role !== 'ADMIN') {
            return false;
        }

        return $builder->countAllResults() > 0;
    }
}
