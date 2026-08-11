<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreCategoryModel extends Model
{
    protected $table            = 'store_categories';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'store_id',
        'name',
        'sort_order',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;

    public function getActiveByStore(int $storeId): array
    {
        return $this->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    public function ensureCategory(int $storeId, string $name): int
    {
        $clean = trim($name);
        if ($clean === '') {
            $clean = 'General';
        }

        $existing = $this->where('store_id', $storeId)
            ->where('name', $clean)
            ->first();

        if ($existing) {
            if ((int) ($existing['is_active'] ?? 0) !== 1) {
                $this->update((int) $existing['id'], [
                    'is_active' => true,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return (int) $existing['id'];
        }

        return (int) $this->insert([
            'store_id' => $storeId,
            'name' => $clean,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

