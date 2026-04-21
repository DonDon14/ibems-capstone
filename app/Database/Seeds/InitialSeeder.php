<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InitialSeeder extends Seeder
{
    public function run()
    {
        // USERS
        $this->db->table('users')->insert([
            'employee_id'  => 'EMP001',
            'name'         => 'Test Faculty',
            'email'        => 'faculty@test.com',
            'password_hash'=> password_hash('123456', PASSWORD_DEFAULT),
            'role'         => 'USER',
            'user_type'    => 'faculty',
            'qr_token'     => 'QR001',
            'base_salary'  => 30000,
            'is_active'    => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        // STORE
        $this->db->table('stores')->insert([
            'store_name' => 'Main Store',
            'officer_id' => 1,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // BALANCE
        $this->db->table('balances')->insert([
            'user_id'      => 1,
            'credit_limit' => 5000,
            'current_debt' => 0,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        // PRODUCTS
        $this->db->table('products')->insert([
            'store_id'   => 1,
            'sku'        => 'SKU001',
            'name'       => 'Coffee',
            'price'      => 50,
            'stock_qty'  => 100,
            'is_active'  => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}