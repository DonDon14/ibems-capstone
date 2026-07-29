<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLowStockThresholdToProducts extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('low_stock_threshold', 'products')) {
            return;
        }

        $this->forge->addColumn('products', [
            'low_stock_threshold' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 10,
                'after' => 'stock_qty',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('low_stock_threshold', 'products')) {
            $this->forge->dropColumn('products', 'low_stock_threshold');
        }
    }
}
