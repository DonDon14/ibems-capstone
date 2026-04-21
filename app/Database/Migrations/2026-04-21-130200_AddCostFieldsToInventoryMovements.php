<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCostFieldsToInventoryMovements extends Migration
{
    public function up()
    {
        $fields = [];

        if (!$this->db->fieldExists('unit_cost', 'inventory_movements')) {
            $fields['unit_cost'] = [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => true,
                'after' => 'qty',
            ];
        }

        if (!$this->db->fieldExists('total_cost', 'inventory_movements')) {
            $fields['total_cost'] = [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
                'after' => 'unit_cost',
            ];
        }

        if (!$this->db->fieldExists('expected_profit', 'inventory_movements')) {
            $fields['expected_profit'] = [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
                'after' => 'total_cost',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('inventory_movements', $fields);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('expected_profit', 'inventory_movements')) {
            $this->forge->dropColumn('inventory_movements', 'expected_profit');
        }
        if ($this->db->fieldExists('total_cost', 'inventory_movements')) {
            $this->forge->dropColumn('inventory_movements', 'total_cost');
        }
        if ($this->db->fieldExists('unit_cost', 'inventory_movements')) {
            $this->forge->dropColumn('inventory_movements', 'unit_cost');
        }
    }
}
