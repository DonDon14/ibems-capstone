<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogModel extends Model
{
    protected $table            = 'audit_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'actor_id',
        'action',
        'entity',
        'entity_id',
        'payload_json',
        'created_at',
    ];

    protected $useTimestamps = false;
}