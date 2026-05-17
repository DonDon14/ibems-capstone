<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAppSettingsTable extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('app_settings')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'setting_key' => ['type' => 'VARCHAR', 'constraint' => 120],
                'setting_value' => ['type' => 'TEXT', 'null' => true],
                'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('setting_key');
            $this->forge->addForeignKey('updated_by', 'users', 'id', 'SET NULL', 'CASCADE');
            $this->forge->createTable('app_settings', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('app_settings', true);
    }
}
