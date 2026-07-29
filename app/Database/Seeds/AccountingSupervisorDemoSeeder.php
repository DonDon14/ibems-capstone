<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

class AccountingSupervisorDemoSeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;
        if (!$db->tableExists('users') || !$db->tableExists('balances')) {
            CLI::error('Required tables are missing. Run migrations first.');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $email = 'accounting.supervisor@ibems.local';
        $password = env('demo.accountingSupervisorPassword') ?: '123456';
        $existing = $db->table('users')->select('id')->where('email', $email)->get()->getRowArray();
        $payload = [
            'employee_id' => 'ACC-SUP-001',
            'name' => 'Accounting Supervisor',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'ACCOUNTING_OFFICE',
            'user_type' => 'staff',
            'qr_token' => 'QR-ACC-SUP-001',
            'base_salary' => 45000,
            'is_active' => 1,
        ];

        if ($existing) {
            $userId = (int) $existing['id'];
            $db->table('users')->where('id', $userId)->update($payload);
        } else {
            $payload['created_at'] = $now;
            $db->table('users')->insert($payload);
            $userId = (int) $db->insertID();
        }

        if ($db->tableExists('user_roles')) {
            $roleExists = $db->table('user_roles')
                ->where('user_id', $userId)
                ->where('role', 'ACCOUNTING_OFFICE')
                ->countAllResults();
            if ($roleExists === 0) {
                $db->table('user_roles')->insert([
                    'user_id' => $userId,
                    'role' => 'ACCOUNTING_OFFICE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $balanceExists = $db->table('balances')->where('user_id', $userId)->countAllResults();
        if ($balanceExists === 0) {
            $db->table('balances')->insert([
                'user_id' => $userId,
                'credit_limit' => 0,
                'current_debt' => 0,
                'updated_at' => $now,
            ]);
        }

        CLI::write('Accounting Supervisor demo account ready:', 'green');
        CLI::write('Email: ' . $email);
        CLI::write('Password: ' . $password);
        CLI::write('Purpose: independent deduction finalization and correction approval');
    }
}
