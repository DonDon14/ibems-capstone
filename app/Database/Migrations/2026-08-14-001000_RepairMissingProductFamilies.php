<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RepairMissingProductFamilies extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('product_families')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'store_id' => ['type' => 'INT', 'unsigned' => true],
                'name' => ['type' => 'VARCHAR', 'constraint' => 150],
                'category' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => 'General'],
                'supplier' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'image_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'option_name' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'Size'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addForeignKey('store_id', 'stores', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('product_families', true);
        }

        if (!$this->db->fieldExists('family_id', 'products')) {
            $this->forge->addColumn('products', [
                'family_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'store_id'],
            ]);
        }

        $this->db->query('CREATE INDEX IF NOT EXISTS idx_products_family ON products (family_id)');
    }

    public function down()
    {
        // Repair migration is intentionally non-destructive.
    }
}
