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

    protected $afterInsert = ['publishNotification'];

    protected function publishNotification(array $event): array
    {
        $auditId = (int) ($event['id'] ?? 0);
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        if ($auditId <= 0 || $data === []) {
            return $event;
        }

        try {
            (new \App\Services\NotificationService())->publishFromAudit($auditId, $data);
        } catch (\Throwable $exception) {
            log_message('error', 'Notification publication failed for audit event {id}: {message}', [
                'id' => $auditId,
                'message' => $exception->getMessage(),
            ]);
        }

        return $event;
    }
}
