<?php

namespace App\Models;

use CodeIgniter\Model;

class InventoryMovementModel extends Model
{
    protected $table = 'inventory_movements';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['product_id', 'store_id', 'type', 'qty', 'reason', 'txn_id', 'created_at'];
}
