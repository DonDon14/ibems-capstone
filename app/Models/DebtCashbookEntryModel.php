<?php

namespace App\Models;

use CodeIgniter\Model;

class DebtCashbookEntryModel extends Model
{
    protected $table            = 'debt_cashbook_entries';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_id',
        'entry_type',
        'direction',
        'amount',
        'debt_before',
        'debt_after',
        'credit_limit_snapshot',
        'available_credit_snapshot',
        'reference_type',
        'reference_id',
        'actor_id',
        'remarks',
        'meta_json',
        'created_at',
        'updated_at',
    ];
}

