<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter.com/user_guide/extending/common.html
 */

if (! function_exists('ibems_normalize_role')) {
    function ibems_normalize_role(?string $role): string
    {
        return strtoupper(trim((string) $role));
    }
}

if (! function_exists('ibems_role_landing_path')) {
    function ibems_role_landing_path(?string $role): string
    {
        return match (ibems_normalize_role($role)) {
            'ADMIN' => '/admin/dashboard',
            'STORE_SUPERVISOR' => '/store-admin/dashboard',
            'STORE_SYSTEM' => '/store/pos',
            'ACCOUNTING_OFFICE' => '/accounting/dashboard',
            'USER' => '/user/dashboard',
            default => '/login',
        };
    }
}

if (! function_exists('ibems_available_roles')) {
    function ibems_available_roles(): array
    {
        $roles = (array) (session()->get('available_roles') ?? []);
        $roles = array_map(static fn ($role) => ibems_normalize_role((string) $role), $roles);

        return array_values(array_filter(array_unique($roles), static fn ($role) => $role !== ''));
    }
}

if (! function_exists('ibems_refresh_session_roles')) {
    function ibems_refresh_session_roles(bool $force = false): array
    {
        if (! session()->get('logged_in')) {
            return [];
        }

        $userId = (int) (session()->get('user_id') ?? 0);
        if ($userId <= 0) {
            return ibems_available_roles();
        }

        $configuredInterval = getenv('IBEMS_ROLE_REFRESH_INTERVAL');
        $refreshInterval = is_numeric($configuredInterval)
            ? max(0, min(300, (int) $configuredInterval))
            : (ENVIRONMENT === 'testing' ? 0 : 30);
        $lastRefresh = (int) (session()->get('roles_refreshed_at') ?? 0);
        if (! $force && $refreshInterval > 0 && $lastRefresh > 0 && (time() - $lastRefresh) < $refreshInterval) {
            return ibems_available_roles();
        }

        try {
            $userModel = new \App\Models\UserModel();
            $user = $userModel->getActiveUserWithRolesById($userId);
            if (! $user) {
                session()->destroy();
                return [];
            }

            $roles = $userModel->getEffectiveRoles($user);
            $roles = array_values(array_filter(array_unique(array_map('ibems_normalize_role', $roles))));
            $currentRole = ibems_current_role();

            if ($currentRole !== null && ! in_array($currentRole, $roles, true)) {
                $currentRole = null;
            }

            if ($currentRole === null && count($roles) === 1) {
                $currentRole = $roles[0];
            }

            session()->set([
                'available_roles' => $roles,
                'role' => $currentRole,
                'roles_refreshed_at' => time(),
            ]);

            return $roles;
        } catch (\Throwable $exception) {
            log_message('error', 'Unable to refresh session roles: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return ibems_available_roles();
        }
    }
}

if (! function_exists('ibems_current_role')) {
    function ibems_current_role(): ?string
    {
        $role = ibems_normalize_role((string) (session()->get('role') ?? ''));

        return $role !== '' ? $role : null;
    }
}

if (! function_exists('ibems_current_path')) {
    function ibems_current_path(): string
    {
        $path = '/' . trim((string) service('uri')->getPath(), '/');
        $path = preg_replace('#/index\.php(?=/|$)#', '', $path) ?: '/';

        return rtrim($path, '/') ?: '/';
    }
}

if (! function_exists('ibems_is_active_path')) {
    function ibems_is_active_path(string $prefix): string
    {
        $prefix = '/' . trim($prefix, '/');
        $path = ibems_current_path();

        return $path === $prefix || str_starts_with($path, $prefix . '/') ? 'is-active' : '';
    }
}

if (! function_exists('ibems_initials')) {
    function ibems_initials(?string $name, string $fallback): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : $fallback;
    }
}

if (! function_exists('ibems_profile_image_url')) {
    function ibems_profile_image_url(?string $url): string
    {
        $url = trim((string) $url);

        return $url !== '' ? $url : base_url('assets/images/default-profile.svg');
    }
}

if (! function_exists('ibems_money')) {
    function ibems_money(float|int|string|null $value, int $decimals = 2): string
    {
        return 'PHP ' . number_format((float) ($value ?? 0), $decimals);
    }
}

if (! function_exists('ibems_bool')) {
    function ibems_bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }
}

if (! function_exists('ibems_datetime')) {
    function ibems_datetime(?string $value, string $format = 'M d, h:i A'): string
    {
        try {
            $stored = new \DateTimeImmutable((string) ($value ?: 'now'), new \DateTimeZone('UTC'));
            return $stored->setTimezone(new \DateTimeZone('Asia/Manila'))->format($format);
        } catch (\Throwable) {
            return '-';
        }
    }
}

if (! function_exists('ibems_business_date')) {
    /**
     * Return the institution's operating date without changing the UTC storage timezone.
     */
    function ibems_business_date(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
}

if (! function_exists('ibems_business_day_utc_bounds')) {
    /** @return array{start: string, end: string} */
    function ibems_business_day_utc_bounds(?string $businessDate = null): array
    {
        $date = trim((string) ($businessDate ?? ibems_business_date()));
        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        $day = new \DateTimeImmutable($date . ' 00:00:00', $businessZone);

        return [
            'start' => $day->setTimezone($storageZone)->format('Y-m-d H:i:s'),
            'end' => $day->setTime(23, 59, 59)->setTimezone($storageZone)->format('Y-m-d H:i:s'),
        ];
    }
}
