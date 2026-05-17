<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreModel extends Model
{
    protected $table = 'stores';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['store_name', 'officer_id', 'is_active', 'created_at', 'updated_at'];
}
