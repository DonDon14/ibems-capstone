<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDebtPinHashToUsers extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('debt_pin_hash', 'users')) {
            return;
        }

        $this->forge->addColumn('users', [
            'debt_pin_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'password_hash',
            ],
        ]);
    }

    public function down()
    {
        if (!$this->db->fieldExists('debt_pin_hash', 'users')) {
            return;
        }

        $this->forge->dropColumn('users', 'debt_pin_hash');
    }
}
