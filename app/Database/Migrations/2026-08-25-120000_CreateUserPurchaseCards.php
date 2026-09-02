<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUserPurchaseCards extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('user_purchase_cards')) {
            return;
        }

        $this->forge->addField([
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'is_locked' => ['type' => 'BOOLEAN', 'default' => true],
            'unlocked_until' => ['type' => 'DATETIME', 'null' => true],
            'last_unlocked_at' => ['type' => 'DATETIME', 'null' => true],
            'last_locked_at' => ['type' => 'DATETIME', 'null' => true],
            'last_transaction_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addKey(['is_locked', 'unlocked_until']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_purchase_cards');
    }

    public function down()
    {
        if ($this->db->tableExists('user_purchase_cards')) {
            $this->forge->dropTable('user_purchase_cards');
        }
    }
}
