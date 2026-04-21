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
}