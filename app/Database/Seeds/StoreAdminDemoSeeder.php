<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

class StoreAdminDemoSeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;
        if (! $db->tableExists('users') || ! $db->tableExists('stores') || ! $db->tableExists('store_supervisors')) {
            CLI::error('Required tables are missing. Run migrations first.');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $email = 'store.admin@ibems.local';
        $password = env('demo.storeAdminPassword') ?: bin2hex(random_bytes(4));
        $user = $db->table('users')
            ->select('id')
            ->where('email', $email)
            ->get()
            ->getRowArray();

        $payload = [
            'employee_id' => 'STA001',
            'name' => 'Main Store Administrator',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'STORE_SUPERVISOR',
            'user_type' => 'staff',
            'qr_token' => 'QR-STA-001',
            'base_salary' => 32000,
            'is_active' => 1,
        ];

        if ($user) {
            $userId = (int) $user['id'];
            $db->table('users')->where('id', $userId)->update($payload);
        } else {
            $payload['created_at'] = $now;
            $db->table('users')->insert($payload);
            $userId = (int) $db->insertID();
        }

        if ($db->tableExists('user_roles')) {
            $exists = $db->table('user_roles')
                ->where('user_id', $userId)
                ->where('role', 'STORE_SUPERVISOR')
                ->countAllResults();
            if ($exists === 0) {
                $db->table('user_roles')->insert([
                    'user_id' => $userId,
                    'role' => 'STORE_SUPERVISOR',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $store = $db->table('stores')
            ->select('id')
            ->where('store_name', 'Main Campus Store')
            ->get()
            ->getRowArray();
        $storeId = (int) ($store['id'] ?? 0);

        if ($storeId > 0) {
            $exists = $db->table('store_supervisors')
                ->where('store_id', $storeId)
                ->where('user_id', $userId)
                ->countAllResults();
            if ($exists === 0) {
                $db->table('store_supervisors')->insert([
                    'store_id' => $storeId,
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        CLI::write('Store Admin demo account ready:', 'green');
        CLI::write('Email: ' . $email);
        CLI::write('Password: ' . $password);
        CLI::write('Assigned store: ' . ($storeId > 0 ? 'Main Campus Store' : 'not assigned; Main Campus Store was not found'));
    }
}
