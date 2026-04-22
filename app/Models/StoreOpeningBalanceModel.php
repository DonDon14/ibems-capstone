<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreOpeningBalanceModel extends Model
{
    protected $table            = 'store_opening_balances';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'store_id',
        'business_date',
        'opening_balance',
        'note',
        'opened_by',
        'opened_at',
        'created_at',
        'updated_at',
    ];

    public function getByStoreAndDate(int $storeId, string $businessDate): ?array
    {
        return $this->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->first();
    }

    public function getInitialByStore(int $storeId): ?array
    {
        return $this->where('store_id', $storeId)
            ->orderBy('business_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->first();
    }

    public function upsertOpening(int $storeId, string $businessDate, float $openingBalance, int $openedBy, string $note = ''): array
    {
        $existing = $this->getByStoreAndDate($storeId, $businessDate);
        $now = date('Y-m-d H:i:s');

        $payload = [
            'store_id' => $storeId,
            'business_date' => $businessDate,
            'opening_balance' => $openingBalance,
            'note' => $note !== '' ? $note : null,
            'opened_by' => $openedBy > 0 ? $openedBy : null,
            'opened_at' => $now,
            'updated_at' => $now,
        ];

        if ($existing) {
            $this->update((int) $existing['id'], $payload);
            return $this->find((int) $existing['id']) ?? $existing;
        }

        $payload['created_at'] = $now;
        $id = (int) $this->insert($payload);
        return $this->find($id) ?? $payload;
    }

    public function createInitialOpening(int $storeId, float $openingBalance, int $openedBy, string $note = ''): array
    {
        $existing = $this->getInitialByStore($storeId);
        if ($existing) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'store_id' => $storeId,
            'business_date' => date('Y-m-d'),
            'opening_balance' => $openingBalance,
            'note' => $note !== '' ? $note : null,
            'opened_by' => $openedBy > 0 ? $openedBy : null,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $id = (int) $this->insert($payload);
        return $this->find($id) ?? $payload;
    }
}
