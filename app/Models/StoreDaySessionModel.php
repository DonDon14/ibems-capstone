<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreDaySessionModel extends Model
{
    protected $table            = 'store_day_sessions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'store_id',
        'business_date',
        'status',
        'opening_cash',
        'opening_ecash',
        'opening_note',
        'opened_by',
        'opened_at',
        'expected_cash',
        'expected_ecash',
        'counted_cash',
        'counted_ecash',
        'variance_cash',
        'variance_ecash',
        'variance_status',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'accountability_user_id',
        'accountability_amount',
        'closing_note',
        'closed_by',
        'closed_at',
        'created_at',
        'updated_at',
    ];

    public function getByStoreAndDate(int $storeId, string $businessDate): ?array
    {
        return $this->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->first();
    }

    public function getOpenByStore(int $storeId): ?array
    {
        return $this->where('store_id', $storeId)
            ->where('status', 'open')
            ->orderBy('business_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function getToday(int $storeId): ?array
    {
        return $this->getByStoreAndDate($storeId, ibems_business_date());
    }

    public function openDay(int $storeId, string $businessDate, float $openingCash, float $openingEcash, int $openedBy, string $note = ''): array
    {
        $now = date('Y-m-d H:i:s');
        $payload = [
            'store_id' => $storeId,
            'business_date' => $businessDate,
            'status' => 'open',
            'opening_cash' => $openingCash,
            'opening_ecash' => $openingEcash,
            'opening_note' => $note !== '' ? $note : null,
            'opened_by' => $openedBy > 0 ? $openedBy : null,
            'opened_at' => $now,
            'expected_cash' => null,
            'expected_ecash' => null,
            'counted_cash' => null,
            'counted_ecash' => null,
            'variance_cash' => null,
            'variance_ecash' => null,
            'variance_status' => 'balanced',
            'review_status' => 'not_required',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'accountability_user_id' => null,
            'accountability_amount' => 0,
            'closing_note' => null,
            'closed_by' => null,
            'closed_at' => null,
            'updated_at' => $now,
        ];

        $existing = $this->getByStoreAndDate($storeId, $businessDate);
        if ($existing) {
            $this->update((int) $existing['id'], $payload);
            return $this->find((int) $existing['id']) ?? $existing;
        }

        $payload['created_at'] = $now;
        $id = (int) $this->insert($payload);
        return $this->find($id) ?? $payload;
    }

    public function reopenDay(int $sessionId, int $openedBy): array
    {
        $now = date('Y-m-d H:i:s');
        $this->update($sessionId, [
            'status' => 'open',
            'opened_by' => $openedBy > 0 ? $openedBy : null,
            'opened_at' => $now,
            'expected_cash' => null,
            'expected_ecash' => null,
            'counted_cash' => null,
            'counted_ecash' => null,
            'variance_cash' => null,
            'variance_ecash' => null,
            'variance_status' => 'balanced',
            'review_status' => 'not_required',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'accountability_user_id' => null,
            'accountability_amount' => 0,
            'closing_note' => null,
            'closed_by' => null,
            'closed_at' => null,
            'updated_at' => $now,
        ]);
        return $this->find($sessionId) ?? [];
    }
}
