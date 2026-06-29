<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoreDaySessionsTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('store_day_sessions')) {
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
            'business_date' => [
                'type' => 'DATE',
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'open',
            ],
            'opening_cash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'opening_ecash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'opening_note' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'opened_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'opened_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expected_cash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
            ],
            'expected_ecash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
            ],
            'counted_cash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
            ],
            'counted_ecash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
            ],
            'variance_cash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
            ],
            'variance_ecash' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
            ],
            'closing_note' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'closed_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'closed_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addUniqueKey(['store_id', 'business_date']);
        $this->forge->addForeignKey('store_id', 'stores', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('opened_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('closed_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('store_day_sessions');
    }

    public function down()
    {
        $this->forge->dropTable('store_day_sessions', true);
    }
}
