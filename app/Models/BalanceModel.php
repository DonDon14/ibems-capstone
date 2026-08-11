<?php

namespace App\Models;

use CodeIgniter\Model;

class BalanceModel extends Model
{
    protected $table            = 'balances';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'user_id',
        'credit_limit',
        'current_debt',
        'updated_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'user_id'       => 'integer',
        'credit_limit'  => 'float',
        'current_debt'  => 'float',
    ];

    protected $useTimestamps = false;

    public function getBalanceByUserId(int $userId): ?array
    {
        return $this->where('user_id', $userId)
                    ->first();
    }

    public function getBalanceForUpdate(int $userId): ?array
    {
        if ($this->db->DBDriver === 'SQLite3') {
            return $this->getBalanceByUserId($userId);
        }

        $sql = $this->builder()
            ->where('user_id', $userId)
            ->getCompiledSelect() . ' FOR UPDATE';

        return $this->db->query($sql)->getRowArray() ?: null;
    }

    public function canUseCredit(int $userId, float $amount): bool
    {
        $balance = $this->getBalanceByUserId($userId);

        if (!$balance) {
            return false;
        }

        $available = $balance['credit_limit'] - $balance['current_debt'];

        return $available >= $amount;
    }

    public function addDebt(int $userId, float $amount): bool
    {
        return $this->set('current_debt', 'current_debt + ' . $amount, false)
                    ->where('user_id', $userId)
                    ->update();
    }

    public function resetDebt(int $userId): bool
    {
        return $this->update($userId, [
            'current_debt' => 0,
        ]);
    }
}
