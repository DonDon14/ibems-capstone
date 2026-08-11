<?php

namespace App\Models;

use CodeIgniter\Model;

class StorePaymentMethodModel extends Model
{
    protected $table            = 'store_payment_methods';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'store_id',
        'code',
        'label',
        'icon_class',
        'sort_order',
        'is_active',
        'is_system_reserved',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;

    public function getActiveByStore(int $storeId): array
    {
        return $this->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('label', 'ASC')
            ->findAll();
    }

    public function getAllByStore(int $storeId): array
    {
        return $this->where('store_id', $storeId)
            ->orderBy('is_active', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('label', 'ASC')
            ->findAll();
    }

    public function ensureDefaults(int $storeId): void
    {
        $defaults = [
            ['code' => 'cash', 'label' => 'Cash', 'icon_class' => 'bi bi-cash', 'sort_order' => 10, 'is_system_reserved' => false],
            ['code' => 'gcash', 'label' => 'GCash', 'icon_class' => 'bi bi-wallet2', 'sort_order' => 20, 'is_system_reserved' => false],
            ['code' => 'card', 'label' => 'Card', 'icon_class' => 'bi bi-credit-card-2-front', 'sort_order' => 30, 'is_system_reserved' => false],
            ['code' => 'bank_transfer', 'label' => 'Bank Transfer', 'icon_class' => 'bi bi-bank', 'sort_order' => 40, 'is_system_reserved' => false],
            ['code' => 'advance_payment', 'label' => 'Advance Payment', 'icon_class' => 'bi bi-cash-coin', 'sort_order' => 50, 'is_system_reserved' => false],
            ['code' => 'other', 'label' => 'Other', 'icon_class' => 'bi bi-three-dots', 'sort_order' => 90, 'is_system_reserved' => false],
            ['code' => 'debt', 'label' => 'Debt', 'icon_class' => 'bi bi-credit-card', 'sort_order' => 100, 'is_system_reserved' => true],
        ];

        foreach ($defaults as $row) {
            $existing = $this->where('store_id', $storeId)
                ->where('code', $row['code'])
                ->first();

            if ($existing) {
                continue;
            }

            $this->insert([
                'store_id' => $storeId,
                'code' => $row['code'],
                'label' => $row['label'],
                'icon_class' => $row['icon_class'],
                'sort_order' => $row['sort_order'],
                'is_active' => true,
                'is_system_reserved' => $row['is_system_reserved'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function normalizeCode(string $value): string
    {
        $code = strtolower(trim($value));
        $code = preg_replace('/[^a-z0-9\_]+/', '_', $code) ?? '';
        $code = trim($code, '_');
        return $code;
    }

    public function isAllowedForStore(int $storeId, string $code): bool
    {
        $method = $this->where('store_id', $storeId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        return (bool) $method;
    }
}

