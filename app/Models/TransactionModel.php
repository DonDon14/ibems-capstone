<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transactions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'client_txn_id', 'user_id', 'customer_type', 'store_id', 'amount', 'payment_method', 'other_payment_label', 'walkin_note', 'status', 'reference_no', 'created_at', 'synced_at',
    ];
}
