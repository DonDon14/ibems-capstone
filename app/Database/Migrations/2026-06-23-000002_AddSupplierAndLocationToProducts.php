<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSupplierAndLocationToProducts extends Migration
{
    public function up()
    {
        $fields = [];

        if (!$this->db->fieldExists('supplier', 'products')) {
            $fields['supplier'] = [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
                'after' => 'category',
            ];
        }

        if (!$this->db->fieldExists('location_bin', 'products')) {
            $fields['location_bin'] = [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
                'after' => 'low_stock_threshold',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('products', $fields);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('location_bin', 'products')) {
            $this->forge->dropColumn('products', 'location_bin');
        }

        if ($this->db->fieldExists('supplier', 'products')) {
            $this->forge->dropColumn('products', 'supplier');
        }
    }
}
