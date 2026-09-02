<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddConfigurableStoreOperations extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_capabilities')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'store_id' => ['type' => 'INT', 'unsigned' => true],
                'capability' => ['type' => 'VARCHAR', 'constraint' => 40],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['store_id', 'capability']);
            $this->forge->addForeignKey('store_id', 'stores', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('store_capabilities');
        }

        $productColumns = [
            'item_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'stock_item'],
            'stock_policy' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'tracked'],
            'unit_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'piece'],
        ];
        foreach ($productColumns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'products')) {
                $this->forge->addColumn('products', [$name => $definition]);
            }
        }

        $snapshotColumns = [
            'item_name_snapshot' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'sku_snapshot' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'variant_snapshot' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'unit_code_snapshot' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'item_type_snapshot' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
        ];
        foreach ($snapshotColumns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'transaction_items')) {
                $this->forge->addColumn('transaction_items', [$name => $definition]);
            }
        }

        if ($this->db->table('store_capabilities')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            foreach ($this->db->table('stores')->select('id')->get()->getResultArray() as $store) {
                $this->db->table('store_capabilities')->insert([
                    'store_id' => (int) $store['id'],
                    'capability' => 'retail',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        foreach (['item_name_snapshot', 'sku_snapshot', 'variant_snapshot', 'unit_code_snapshot', 'item_type_snapshot'] as $column) {
            if ($this->db->fieldExists($column, 'transaction_items')) {
                $this->forge->dropColumn('transaction_items', $column);
            }
        }
        foreach (['item_type', 'stock_policy', 'unit_code'] as $column) {
            if ($this->db->fieldExists($column, 'products')) {
                $this->forge->dropColumn('products', $column);
            }
        }
        if ($this->db->tableExists('store_capabilities')) {
            $this->forge->dropTable('store_capabilities');
        }
    }
}
