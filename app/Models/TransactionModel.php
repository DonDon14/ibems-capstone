<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transactions';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'client_txn_id',
        'user_id',
        'customer_type',
        'store_id',
        'amount',
        'payment_method',
        'status',
        'created_at',
    ];
}