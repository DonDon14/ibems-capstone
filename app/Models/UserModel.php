<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'employee_id',
        'name',
        'email',
        'password_hash',
        'role',
        'user_type',
        'qr_token',
        'profile_image_url',
        'base_salary',
        'is_active',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'id'          => 'integer',
        'base_salary' => 'float',
        'is_active'   => 'boolean',
    ];

    protected $useTimestamps = false;

    public function getActiveUserByEmail(string $email): ?array
    {
        return $this->where('email', $email)
                    ->where('is_active', 1)
                    ->first();
    }

    public function getByQrToken(string $qrToken): ?array
    {
        return $this->where('qr_token', $qrToken)
                    ->where('is_active', 1)
                    ->first();
    }

    public function getActiveUserById(int $userId): ?array
    {
        return $this->where('id', $userId)
                    ->where('is_active', 1)
                    ->first();
    }
}
