<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Authorization extends BaseConfig
{
    /**
     * Named route permissions. Access is evaluated against the deliberately
     * selected active role, never the union of every role assigned to a user.
     *
     * @var array<string, list<string>>
     */
    public array $policies = [
        'portal.enter'          => ['ADMIN', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE'],
        'system.manage'         => ['ADMIN'],
        'store.review_assigned' => ['STORE_SUPERVISOR'],
        'store.inspect'         => ['STORE_SYSTEM', 'ADMIN'],
        'store.operate'         => ['STORE_SYSTEM'],
        'user.self'             => ['USER'],
        'accounting.inspect'    => ['ACCOUNTING_OFFICE', 'ADMIN'],
        'accounting.operate'    => ['ACCOUNTING_OFFICE'],
    ];

    /** @return list<string>|null */
    public function rolesFor(string $policy): ?array
    {
        $policy = strtolower(trim($policy));
        if (!array_key_exists($policy, $this->policies)) {
            return null;
        }

        return array_values(array_unique(array_map(
            static fn (string $role): string => ibems_normalize_role($role),
            $this->policies[$policy]
        )));
    }
}
