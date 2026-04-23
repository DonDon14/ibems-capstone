<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class IbemsAuthAudit extends BaseCommand
{
    protected $group       = 'IBEMS';
    protected $name        = 'ibems:auth-audit';
    protected $description = 'Audit auth/role consistency, route presence, and store-officer role mappings.';
    protected $usage       = 'ibems:auth-audit';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params): void
    {
        $allowedRoles = ['ADMIN', 'STORE_SYSTEM', 'ACCOUNTING_OFFICE', 'USER'];
        $errors = 0;
        $warnings = 0;

        CLI::write('IBEMS Auth Audit', 'yellow');
        CLI::newLine();

        try {
            $db = Database::connect();
            $db->initialize();
            CLI::write('[OK] Database connection established', 'green');
        } catch (\Throwable $e) {
            CLI::write('[FAIL] Database connection failed: ' . $e->getMessage(), 'red');
            exit(1);
        }

        // Route presence quick check (static config file contains key paths).
        $routesPath = APPPATH . 'Config/Routes.php';
        $routesSource = is_file($routesPath) ? (string) file_get_contents($routesPath) : '';
        $requiredPaths = [
            'auth/login',
            'auth/select-role',
            'admin/dashboard',
            'store/pos',
            'accounting/dashboard',
            'user/dashboard',
        ];
        foreach ($requiredPaths as $path) {
            if (strpos($routesSource, $path) !== false) {
                CLI::write("[OK] Route path present in config: {$path}", 'green');
            } else {
                CLI::write("[FAIL] Route path missing in config: {$path}", 'red');
                $errors++;
            }
        }

        // Validate users table role values.
        $userRows = $db->table('users')
            ->select('id, email, role')
            ->get()
            ->getResultArray();

        foreach ($userRows as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $role = strtoupper(trim((string) ($user['role'] ?? '')));
            if (!in_array($role, $allowedRoles, true)) {
                CLI::write("[FAIL] users.role invalid for user_id={$userId}: {$role}", 'red');
                $errors++;
            }
        }

        // Validate user_roles role values and build map.
        $roleRows = $db->table('user_roles')
            ->select('user_id, role')
            ->get()
            ->getResultArray();

        $rolesByUser = [];
        foreach ($roleRows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $role = strtoupper(trim((string) ($row['role'] ?? '')));
            if (!in_array($role, $allowedRoles, true)) {
                CLI::write("[FAIL] user_roles.role invalid for user_id={$userId}: {$role}", 'red');
                $errors++;
                continue;
            }
            if (!isset($rolesByUser[$userId])) {
                $rolesByUser[$userId] = [];
            }
            if (!in_array($role, $rolesByUser[$userId], true)) {
                $rolesByUser[$userId][] = $role;
            }
        }

        // Effective role checks.
        foreach ($userRows as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $legacyRole = strtoupper(trim((string) ($user['role'] ?? 'USER')));
            $assignedRoles = $rolesByUser[$userId] ?? [];
            $effectiveRoles = $assignedRoles;
            if (!in_array($legacyRole, $effectiveRoles, true)) {
                $effectiveRoles[] = $legacyRole;
            }
            if (empty($effectiveRoles)) {
                CLI::write("[FAIL] user_id={$userId} has no effective role", 'red');
                $errors++;
            }

            if (!empty($assignedRoles) && !in_array($legacyRole, $assignedRoles, true)) {
                CLI::write("[WARN] users.role not in user_roles for user_id={$userId} (legacy={$legacyRole})", 'light_yellow');
                $warnings++;
            }
        }

        // Store officer mapping checks.
        $stores = $db->table('stores')
            ->select('id, store_name, officer_id, is_active')
            ->get()
            ->getResultArray();

        foreach ($stores as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            $officerId = (int) ($store['officer_id'] ?? 0);
            $storeName = (string) ($store['store_name'] ?? ('Store #' . $storeId));
            if ($officerId <= 0) {
                CLI::write("[FAIL] store_id={$storeId} ({$storeName}) has invalid officer_id", 'red');
                $errors++;
                continue;
            }

            $officer = null;
            foreach ($userRows as $user) {
                if ((int) ($user['id'] ?? 0) === $officerId) {
                    $officer = $user;
                    break;
                }
            }
            if (!$officer) {
                CLI::write("[FAIL] store_id={$storeId} ({$storeName}) officer user not found: {$officerId}", 'red');
                $errors++;
                continue;
            }

            $legacyRole = strtoupper(trim((string) ($officer['role'] ?? '')));
            $assignedRoles = $rolesByUser[$officerId] ?? [];
            $hasStoreSystem = $legacyRole === 'STORE_SYSTEM' || in_array('STORE_SYSTEM', $assignedRoles, true);
            if (!$hasStoreSystem) {
                CLI::write("[FAIL] store_id={$storeId} ({$storeName}) officer_id={$officerId} missing STORE_SYSTEM role", 'red');
                $errors++;
            }
        }

        CLI::newLine();
        CLI::write("Warnings: {$warnings}", $warnings > 0 ? 'light_yellow' : 'green');
        CLI::write("Errors: {$errors}", $errors > 0 ? 'red' : 'green');
        CLI::newLine();

        if ($errors > 0) {
            CLI::write('Auth audit failed. Fix errors above before deployment.', 'red');
            exit(1);
        }

        CLI::write('Auth audit passed.', 'green');
    }
}

