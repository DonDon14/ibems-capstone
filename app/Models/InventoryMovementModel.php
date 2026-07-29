<?php

namespace App\Models;

use CodeIgniter\Model;

class InventoryMovementModel extends Model
{
    protected $table            = 'inventory_movements';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'product_id',
        'store_id',
        'type',
        'qty',
        'unit_cost',
        'total_cost',
        'expected_profit',
        'reason',
        'txn_id',
        'created_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'store_id' => 'integer',
        'qty' => 'integer',
        'unit_cost' => '?float',
        'total_cost' => '?float',
        'expected_profit' => '?float',
    ];

    protected $useTimestamps = false;
}
