<?php

namespace App\Models;

use CodeIgniter\Model;

class DeductionBatchItemModel extends Model
{
    protected $table            = 'deduction_batch_items';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'batch_id',
        'user_id',
        'debt_snapshot',
        'requested_amount',
        'confirmed_amount',
        'carryover_amount',
        'result_status',
        'reason_code',
        'result_reference',
        'result_notes',
        'confirmed_by',
        'confirmed_at',
        'created_at',
        'updated_at',
    ];
}
