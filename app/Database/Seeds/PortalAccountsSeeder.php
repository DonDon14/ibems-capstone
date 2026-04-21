<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PortalAccountsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $accounts = [
            [
                'employee_id' => 'EMP100',
                'name' => 'Portal Admin',
                'email' => 'admin@ibems.local',
                'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
                'role' => 'ADMIN',
                'user_type' => 'staff',
                'qr_token' => 'QR100',
                'base_salary' => 50000,
                'is_active' => 1,
                'created_at' => $now,
                'credit_limit' => 10000,
                'current_debt' => 0,
            ],
            [
                'employee_id' => 'EMP101',
                'name' => 'Portal Store Officer',
                'email' => 'store@ibems.local',
                'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
                'role' => 'STORE_SYSTEM',
                'user_type' => 'staff',
                'qr_token' => 'QR101',
                'base_salary' => 28000,
                'is_active' => 1,
                'created_at' => $now,
                'credit_limit' => 3000,
                'current_debt' => 0,
            ],
            [
                'employee_id' => 'EMP102',
                'name' => 'Portal Accounting Officer',
                'email' => 'accounting@ibems.local',
                'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
                'role' => 'ACCOUNTING_OFFICE',
                'user_type' => 'staff',
                'qr_token' => 'QR102',
                'base_salary' => 32000,
                'is_active' => 1,
                'created_at' => $now,
                'credit_limit' => 5000,
                'current_debt' => 0,
            ],
            [
                'employee_id' => 'EMP103',
                'name' => 'Portal End User',
                'email' => 'user@ibems.local',
                'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
                'role' => 'USER',
                'user_type' => 'faculty',
                'qr_token' => 'QR103',
                'base_salary' => 30000,
                'is_active' => 1,
                'created_at' => $now,
                'credit_limit' => 5000,
                'current_debt' => 1200,
            ],
        ];

        foreach ($accounts as $account) {
            $existing = $this->db->table('users')->where('email', $account['email'])->get()->getRowArray();

            $userData = [
                'employee_id' => $account['employee_id'],
                'name' => $account['name'],
                'email' => $account['email'],
                'password_hash' => $account['password_hash'],
                'role' => $account['role'],
                'user_type' => $account['user_type'],
                'qr_token' => $account['qr_token'],
                'base_salary' => $account['base_salary'],
                'is_active' => $account['is_active'],
                'created_at' => $account['created_at'],
            ];

            if ($existing) {
                $this->db->table('users')->where('id', $existing['id'])->update($userData);
                $userId = (int) $existing['id'];
            } else {
                $this->db->table('users')->insert($userData);
                $userId = (int) $this->db->insertID();
            }

            $balanceData = [
                'credit_limit' => $account['credit_limit'],
                'current_debt' => $account['current_debt'],
                'updated_at' => $now,
            ];

            $balanceExists = $this->db->table('balances')->where('user_id', $userId)->get()->getRowArray();

            if ($balanceExists) {
                $this->db->table('balances')->where('user_id', $userId)->update($balanceData);
            } else {
                $this->db->table('balances')->insert(array_merge(['user_id' => $userId], $balanceData));
            }

            if ($account['role'] === 'STORE_SYSTEM') {
                $store = $this->db->table('stores')->where('store_name', 'Main Store')->get()->getRowArray();
                if ($store) {
                    $this->db->table('stores')->where('id', $store['id'])->update(['officer_id' => $userId]);
                }
            }
        }
    }
}
