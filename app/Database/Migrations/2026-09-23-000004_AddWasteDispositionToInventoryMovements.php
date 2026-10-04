<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Explicitly distinguish discarded/spoiled stock from ordinary count corrections. */
class AddWasteDispositionToInventoryMovements extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('inventory_movements')
            && !$this->db->fieldExists('waste_disposition', 'inventory_movements')) {
            $this->forge->addColumn('inventory_movements', [
                'waste_disposition' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('inventory_movements')
            && $this->db->fieldExists('waste_disposition', 'inventory_movements')) {
            $this->forge->dropColumn('inventory_movements', 'waste_disposition');
        }
    }
}
