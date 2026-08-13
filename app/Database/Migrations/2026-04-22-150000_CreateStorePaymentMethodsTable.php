<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStorePaymentMethodsTable extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_payment_methods')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'store_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'code' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                ],
                'label' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                ],
                'icon_class' => [
                    'type' => 'VARCHAR',
                    'constraint' => 80,
                    'null' => true,
                ],
                'sort_order' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 0,
                ],
                'is_active' => [
                    'type' => 'BOOLEAN',
                    'default' => true,
                ],
                'is_system_reserved' => [
                    'type' => 'BOOLEAN',
                    'default' => false,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['store_id', 'code']);
            $this->forge->addKey(['store_id', 'is_active']);
            $this->forge->addForeignKey('store_id', 'stores', 'id', 'RESTRICT', 'CASCADE');
            $this->forge->createTable('store_payment_methods');
        }

        $defaults = [
            ['cash', 'Cash', 'bi bi-cash', 10, 0],
            ['debt', 'Debt', 'bi bi-credit-card', 100, 1],
        ];

        $stores = $this->db->table('stores')->select('id')->get()->getResultArray();
        foreach ($stores as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }

            foreach ($defaults as $method) {
                [$code, $label, $iconClass, $sortOrder, $isReserved] = $method;
                $existing = $this->db->table('store_payment_methods')
                    ->where('store_id', $storeId)
                    ->where('code', $code)
                    ->get()
                    ->getRowArray();

                if ($existing) {
                    continue;
                }

                $this->db->table('store_payment_methods')->insert([
                    'store_id' => $storeId,
                    'code' => $code,
                    'label' => $label,
                    'icon_class' => $iconClass,
                    'sort_order' => $sortOrder,
                    'is_active' => 1,
                    'is_system_reserved' => $isReserved ? 1 : 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('store_payment_methods', true);
    }
}

