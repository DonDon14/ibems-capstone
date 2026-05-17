<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table = 'products';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['store_id', 'sku', 'name', 'category', 'image_path', 'price', 'stock_qty', 'is_active', 'created_at', 'updated_at'];
}
