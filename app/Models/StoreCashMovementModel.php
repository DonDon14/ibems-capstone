<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreCashMovementModel extends Model
{
    protected $table            = 'store_cash_movements';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'store_id',
        'business_date',
        'channel',
        'movement_type',
        'amount',
        'reason',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;

    public function getByStoreAndRange(int $storeId, string $fromDate, string $toDate, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->where('store_id', $storeId)
            ->where('business_date >=', $fromDate)
            ->where('business_date <=', $toDate)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}

