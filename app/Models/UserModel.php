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
        'debt_pin_hash',
        'role',
        'user_type',
        'qr_token',
        'profile_image_url',
        'base_salary',
        'employment_type',
        'salary_grade',
        'salary_step',
        'salary_effective_date',
        'is_active',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'id'          => 'integer',
        'base_salary' => 'float',
        'salary_step' => '?integer',
        'is_active'   => 'boolean',
    ];

    protected $useTimestamps = false;

    public function getActiveUserByEmail(string $email): ?array
    {
        return $this->where('email', $email)
                    ->where('is_active', true)
                    ->first();
    }

    public function getActiveUserWithRolesByEmail(string $email): ?array
    {
        return $this->getActiveUserWithRoles('u.email', $email);
    }

    public function getByQrToken(string $qrToken): ?array
    {
        return $this->where('qr_token', $qrToken)
                    ->where('is_active', true)
                    ->first();
    }

    public function getActiveUserById(int $userId): ?array
    {
        return $this->where('id', $userId)
                    ->where('is_active', true)
                    ->first();
    }

    public function getActiveUserWithRolesById(int $userId): ?array
    {
        return $this->getActiveUserWithRoles('u.id', $userId);
    }

    public function getEffectiveRoles(array $user): array
    {
        if (isset($user['_effective_roles']) && is_array($user['_effective_roles'])) {
            return array_values(array_unique(array_filter(array_map(
                static fn ($role): string => strtoupper(trim((string) $role)),
                $user['_effective_roles']
            ))));
        }

        $userId = (int) ($user['id'] ?? 0);
        $roles = [];

        if ($userId > 0) {
            $userRoleModel = new UserRoleModel();
            $roles = $userRoleModel->getRolesByUserId($userId);
        }

        $legacyRole = strtoupper(trim((string) ($user['role'] ?? '')));
        if ($legacyRole !== '' && !in_array($legacyRole, $roles, true)) {
            $roles[] = $legacyRole;
        }

        if ($roles === []) {
            $roles[] = 'USER';
        }

        return array_values(array_unique($roles));
    }

    private function getActiveUserWithRoles(string $field, string|int $value): ?array
    {
        $rows = $this->db->table('users u')
            ->select('u.*, ur.role AS assigned_role')
            ->join('user_roles ur', 'ur.user_id = u.id', 'left')
            ->where($field, $value)
            ->where('u.is_active', true)
            ->orderBy('ur.role', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return null;
        }

        $user = $rows[0];
        $roles = [];
        foreach ($rows as $row) {
            $assignedRole = strtoupper(trim((string) ($row['assigned_role'] ?? '')));
            if ($assignedRole !== '') {
                $roles[] = $assignedRole;
            }
        }
        unset($user['assigned_role']);

        $legacyRole = strtoupper(trim((string) ($user['role'] ?? '')));
        if ($legacyRole !== '') {
            $roles[] = $legacyRole;
        }
        $user['_effective_roles'] = $roles !== [] ? array_values(array_unique($roles)) : ['USER'];

        return $user;
    }
}
