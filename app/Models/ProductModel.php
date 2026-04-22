<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table            = 'products';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'store_id',
        'sku',
        'name',
        'variant_label',
        'category',
        'image_url',
        'barcode',
        'price',
        'stock_qty',
        'is_active',
        'updated_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'id'        => 'integer',
        'store_id'  => 'integer',
        'price'     => 'float',
        'stock_qty' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $useTimestamps = false;

    public function getActiveProductsByStore(int $storeId): array
    {
        return $this->where('store_id', $storeId)
                    ->where('is_active', 1)
                    ->findAll();
    }

    public function getBySku(int $storeId, string $sku): ?array
    {
        return $this->where('store_id', $storeId)
                    ->where('sku', $sku)
                    ->first();
    }

    public function hasEnoughStock(int $productId, int $qty): bool
    {
        $product = $this->find($productId);

        if (!$product) {
            return false;
        }

        return $product['stock_qty'] >= $qty;
    }

    public function deductStock(int $productId, int $qty): bool
    {
        return $this->set('stock_qty', 'stock_qty - ' . $qty, false)
                    ->where('id', $productId)
                    ->update();
    }

    public function addStock(int $productId, int $qty): bool
    {
        return $this->set('stock_qty', 'stock_qty + ' . $qty, false)
                    ->where('id', $productId)
                    ->update();
    }
}
