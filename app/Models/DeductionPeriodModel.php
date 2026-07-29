<?php

namespace App\Models;

use CodeIgniter\Model;

class DeductionPeriodModel extends Model
{
    protected $table            = 'deduction_periods';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'period_code',
        'label',
        'frequency',
        'date_start',
        'date_end',
        'expected_processing_date',
        'preparation_deadline',
        'status',
        'notes',
        'created_by',
        'reviewed_by',
        'submitted_by',
        'confirmed_by',
        'finalized_by',
        'reviewed_at',
        'submitted_at',
        'confirmed_at',
        'finalized_at',
        'created_at',
        'updated_at',
    ];
}
