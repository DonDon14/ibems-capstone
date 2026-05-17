<?php

namespace App\Services;

use App\Models\AuditLogModel;

class AuditService
{
    public function log(?int $actorId, string $action, string $entity, ?string $entityId = null, ?array $payload = null): void
    {
        $model = new AuditLogModel();
        $model->insert([
            'actor_id' => $actorId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'payload_json' => $payload !== null ? json_encode($payload) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
