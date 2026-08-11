<?php

namespace App\Models;

use CodeIgniter\Model;

class DebtInvestigationModel extends Model
{
    protected $table = 'debt_investigations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'user_id', 'transaction_id', 'status', 'issue_type', 'summary',
        'evidence_summary', 'findings', 'recommended_action', 'recommended_amount',
        'rejection_reason', 'reversal_cashbook_entry_id', 'opened_by',
        'investigator_id', 'recommended_by', 'approved_by', 'posted_by',
        'recommended_at', 'approved_at', 'posted_at', 'closed_at', 'created_at',
        'updated_at',
    ];
}
