<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDebtPinSecurityTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('debt_pin_security')) {
            return;
        }

        $this->forge->addField([
            'user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'failed_attempts' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 0,
            ],
            'window_started_at' => ['type' => 'DATETIME', 'null' => true],
            'locked_until' => ['type' => 'DATETIME', 'null' => true],
            'last_failed_at' => ['type' => 'DATETIME', 'null' => true],
            'last_success_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addKey('locked_until');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('debt_pin_security', true);
    }

    public function down()
    {
        $this->forge->dropTable('debt_pin_security', true);
    }
}
