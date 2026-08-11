<?php

namespace App\Models;

use CodeIgniter\Model;

class DeductionBatchModel extends Model
{
    protected $table            = 'deduction_batches';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'period_id',
        'status',
        'total_accounts',
        'total_requested',
        'total_confirmed',
        'total_carryover',
        'legacy_settlement_run_id',
        'notes',
        'created_by',
        'created_at',
        'updated_at',
    ];
}
