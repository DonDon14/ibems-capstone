<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class StoreLifecycleService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function validateDeactivation(int $storeId, string $reason): array
    {
        $errors = [];
        if (trim($reason) === '') {
            $errors[] = 'A deactivation reason is required.';
        }
        if ($this->db->table('store_day_sessions')->where('store_id', $storeId)->where('status', 'open')->countAllResults() > 0) {
            $errors[] = 'Close or administratively resolve the open store day first.';
        }
        if (!$this->db->tableExists('store_day_variance_cases')) {
            $errors[] = 'Variance-case verification is unavailable; store deactivation is blocked.';
        } elseif ($this->db->table('store_day_variance_cases')->where('store_id', $storeId)->where('status !=', 'resolved')->countAllResults() > 0) {
            $errors[] = 'Resolve all store-day variance cases first.';
        }

        return $errors;
    }

    public function lifecyclePayload(bool $activate, int $actorId, string $reason = ''): array
    {
        $now = date('Y-m-d H:i:s');
        if ($activate) {
            return [
                'is_active' => true,
                'reactivated_at' => $now,
                'reactivated_by' => $actorId,
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ];
        }

        return [
            'is_active' => false,
            'deactivated_at' => $now,
            'deactivated_by' => $actorId,
            'deactivation_reason' => trim($reason),
        ];
    }

    public function validateReactivation(array $store): array
    {
        $officerId = (int) ($store['officer_id'] ?? 0);
        if ($officerId <= 0 || $this->db->table('users')->where('id', $officerId)->where('is_active', true)->countAllResults() === 0) {
            return ['Assign an active primary officer before reactivation.'];
        }

        $activeSupervisors = $this->db->table('store_supervisors ss')
            ->join('users u', 'u.id = ss.user_id')
            ->where('ss.store_id', (int) ($store['id'] ?? 0))
            ->where('u.is_active', true)
            ->countAllResults();

        return $activeSupervisors > 0 ? [] : ['Assign at least one active supervisor before reactivation.'];
    }
}
