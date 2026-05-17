<?php

namespace App\Models;

use CodeIgniter\Model;

class BalanceModel extends Model
{
    protected $table = 'balances';
    protected $primaryKey = 'user_id';
    protected $returnType = 'array';
    protected $allowedFields = ['user_id', 'credit_limit', 'current_debt', 'updated_at'];
}
