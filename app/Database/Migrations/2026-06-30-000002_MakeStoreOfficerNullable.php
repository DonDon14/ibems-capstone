<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class MakeStoreOfficerNullable extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('officer_id', 'stores')) {
            return;
        }

        $this->forge->modifyColumn('stores', [
            'officer_id' => [
                'name' => 'officer_id',
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        if (!$this->db->fieldExists('officer_id', 'stores')) {
            return;
        }

        $hasUnassigned = (int) $this->db->table('stores')
            ->where('officer_id IS NULL', null, false)
            ->countAllResults();

        if ($hasUnassigned > 0) {
            return;
        }

        $this->forge->modifyColumn('stores', [
            'officer_id' => [
                'name' => 'officer_id',
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
        ]);
    }
}
