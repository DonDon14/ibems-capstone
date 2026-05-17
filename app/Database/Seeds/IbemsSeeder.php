<?php

namespace App\Database\Seeds;

use App\Services\CreditService;
use CodeIgniter\Database\Seeder;

class IbemsSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');
        $users = [
            [
                'employee_id' => 'ADM-001',
                'name' => 'System Admin',
                'email' => 'admin@ibems.local',
                'password_hash' => password_hash('admin1234', PASSWORD_DEFAULT),
                'role' => 'ADMIN',
                'user_type' => null,
                'qr_token' => 'QR-ADM-001',
                'base_salary' => 0,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => 'ACC-001',
                'name' => 'Accounting Officer',
                'email' => 'accounting@ibems.local',
                'password_hash' => password_hash('accounting1234', PASSWORD_DEFAULT),
                'role' => 'ACCOUNTING_OFFICE',
                'user_type' => 'staff',
                'qr_token' => 'QR-ACC-001',
                'base_salary' => 28000,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => 'FAC-001',
                'name' => 'Faculty Demo',
                'email' => 'faculty@ibems.local',
                'password_hash' => password_hash('faculty1234', PASSWORD_DEFAULT),
                'role' => 'USER',
                'user_type' => 'faculty',
                'qr_token' => 'QR-FAC-001',
                'base_salary' => 32000,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => 'STU-001',
                'name' => 'Student Demo',
                'email' => 'student@ibems.local',
                'password_hash' => password_hash('student1234', PASSWORD_DEFAULT),
                'role' => 'USER',
                'user_type' => 'student',
                'qr_token' => 'QR-STU-001',
                'base_salary' => null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => 'STR-001',
                'name' => 'Store Cashier',
                'email' => 'store@ibems.local',
                'password_hash' => password_hash('store1234', PASSWORD_DEFAULT),
                'role' => 'STORE_SYSTEM',
                'user_type' => 'staff',
                'qr_token' => 'QR-STR-001',
                'base_salary' => 18000,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $this->db->table('users')->insertBatch($users);
        $allUsers = $this->db->table('users')->select('id, user_type, base_salary')->get()->getResultArray();

        $creditService = new CreditService();
        $balances = [];
        foreach ($allUsers as $u) {
            if (! in_array((string) $u['user_type'], ['faculty', 'staff'], true)) {
                continue;
            }

            $balances[] = [
                'user_id' => (int) $u['id'],
                'credit_limit' => $creditService->computeLimit($u['base_salary'] !== null ? (float) $u['base_salary'] : null),
                'current_debt' => 0,
                'updated_at' => $now,
            ];
        }

        if ($balances !== []) {
            $this->db->table('balances')->insertBatch($balances);
        }

        $officerId = (int) $this->db->table('users')->select('id')->where('role', 'STORE_SYSTEM')->get()->getRow('id');
        $this->db->table('stores')->insertBatch([
            [
                'store_name' => 'Campus Cafeteria',
                'officer_id' => $officerId,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_name' => 'Water Station',
                'officer_id' => $officerId,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $storeId = (int) $this->db->table('stores')->select('id')->orderBy('id', 'ASC')->get()->getRow('id');
        $this->db->table('products')->insertBatch([
            [
                'store_id' => $storeId,
                'sku' => 'CAF-001',
                'name' => 'Rice Meal',
                'category' => 'Meals',
                'price' => 65,
                'stock_qty' => 100,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeId,
                'sku' => 'CAF-002',
                'name' => 'Bottled Water',
                'category' => 'Drinks',
                'price' => 20,
                'stock_qty' => 200,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
