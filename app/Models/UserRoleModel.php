<?php

namespace App\Models;

use CodeIgniter\Model;

class UserRoleModel extends Model
{
    protected $table            = 'user_roles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_id',
        'role',
        'created_at',
        'updated_at',
    ];

    public function getRolesByUserId(int $userId): array
    {
        $rows = $this->where('user_id', $userId)
            ->orderBy('role', 'ASC')
            ->findAll();

        $roles = [];
        foreach ($rows as $row) {
            $role = strtoupper(trim((string) ($row['role'] ?? '')));
            if ($role !== '') {
                $roles[] = $role;
            }
        }
        return array_values(array_unique($roles));
    }
}

