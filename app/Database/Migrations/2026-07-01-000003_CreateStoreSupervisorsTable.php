<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoreSupervisorsTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('store_supervisors')) {
            return;
        }

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
            'user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
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
        $this->forge->addUniqueKey(['store_id', 'user_id'], 'store_supervisors_store_user_unique');
        $this->forge->addKey('store_id');
        $this->forge->addKey('user_id');
        $this->forge->createTable('store_supervisors', true);
    }

    public function down()
    {
        $this->forge->dropTable('store_supervisors', true);
    }
}
