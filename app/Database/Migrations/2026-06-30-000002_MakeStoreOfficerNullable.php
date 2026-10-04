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

        // CI's SQLite Forge renames stores to temp_stores while rebuilding it.
        // Legacy alter mode keeps child-table foreign keys pointed at stores.
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->modifyColumn('stores', [
                'officer_id' => [
                    'name' => 'officer_id',
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
            ]);
        } finally {
            if ($this->db->DBDriver === 'SQLite3') {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
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

        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->modifyColumn('stores', [
                'officer_id' => [
                    'name' => 'officer_id',
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => false,
                ],
            ]);
        } finally {
            if ($this->db->DBDriver === 'SQLite3') {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
