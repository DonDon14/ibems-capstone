<?php

namespace App\Models;

use CodeIgniter\Model;

class DebtPinSecurityModel extends Model
{
    protected $table            = 'debt_pin_security';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_id',
        'failed_attempts',
        'window_started_at',
        'locked_until',
        'last_failed_at',
        'last_success_at',
        'updated_at',
    ];
}
