<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class StoreTestProductsSeeder extends Seeder
{
    public function run()
    {
        $store = $this->db->table('stores')
            ->select('id')
            ->where('store_name', 'Main Store')
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        if (!$store) {
            $store = $this->db->table('stores')
                ->select('id')
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
        }

        if (!$store) {
            return;
        }

        $storeId = (int) $store['id'];
        $now = date('Y-m-d H:i:s');

        // Ensure legacy rows are safe even if category was previously nullable.
        $this->db->table('products')
            ->where('store_id', $storeId)
            ->where('category', null)
            ->set('category', 'General')
            ->update();

        $products = [
            [
                'sku' => 'SKU002',
                'name' => 'Bottled Water',
                'category' => 'Drinks',
                'price' => 20.00,
                'stock_qty' => 80,
            ],
            [
                'sku' => 'SKU003',
                'name' => 'Iced Tea',
                'category' => 'Drinks',
                'price' => 30.00,
                'stock_qty' => 70,
            ],
            [
                'sku' => 'SKU004',
                'name' => 'Pandesal Pack',
                'category' => 'Bread',
                'price' => 25.00,
                'stock_qty' => 50,
            ],
            [
                'sku' => 'SKU005',
                'name' => 'Chicken Sandwich',
                'category' => 'Snacks',
                'price' => 55.00,
                'stock_qty' => 40,
            ],
            [
                'sku' => 'SKU006',
                'name' => 'Chocolate Bar',
                'category' => 'Snacks',
                'price' => 35.00,
                'stock_qty' => 60,
            ],
        ];

        foreach ($products as $product) {
            $existing = $this->db->table('products')
                ->select('id')
                ->where('store_id', $storeId)
                ->where('sku', $product['sku'])
                ->get()
                ->getRowArray();

            if ($existing) {
                continue;
            }

            $this->db->table('products')->insert([
                'store_id' => $storeId,
                'sku' => $product['sku'],
                'name' => $product['name'],
                'category' => $product['category'],
                'image_url' => null,
                'price' => $product['price'],
                'stock_qty' => $product['stock_qty'],
                'is_active' => 1,
                'updated_at' => $now,
            ]);
        }
    }
}

