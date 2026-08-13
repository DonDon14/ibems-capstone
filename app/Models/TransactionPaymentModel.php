<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionPaymentModel extends Model
{
    protected $table = 'transaction_payments';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'payment_method',
        'destination_account_id',
        'destination_account_name',
        'destination_account_number',
        'amount',
        'cash_received',
        'change_due',
        'created_at',
    ];
}
