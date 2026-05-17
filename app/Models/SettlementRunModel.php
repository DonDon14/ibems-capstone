<?php

namespace App\Models;

use CodeIgniter\Model;

class SettlementRunModel extends Model
{
    protected $table = 'settlement_runs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['run_month', 'run_by', 'run_at', 'total_accounts', 'total_debt_before', 'notes'];
}
