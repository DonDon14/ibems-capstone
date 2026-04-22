<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDebtCashbookEntriesTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('debt_cashbook_entries')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'entry_type' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
            ],
            'direction' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'debt_before' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'debt_after' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'credit_limit_snapshot' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'available_credit_snapshot' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'reference_type' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'reference_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'actor_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'remarks' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'meta_json' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey(['reference_type', 'reference_id']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('actor_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('debt_cashbook_entries');
    }

    public function down()
    {
        $this->forge->dropTable('debt_cashbook_entries', true);
    }
}

