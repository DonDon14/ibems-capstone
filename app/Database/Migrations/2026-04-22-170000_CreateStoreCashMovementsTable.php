<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoreCashMovementsTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('store_cash_movements')) {
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
            'channel' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'default' => 'cash',
            ],
            'movement_type' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'reason' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'created_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
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
        $this->forge->addKey(['store_id', 'business_date']);
        $this->forge->addForeignKey('store_id', 'stores', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('store_cash_movements');
    }

    public function down()
    {
        $this->forge->dropTable('store_cash_movements', true);
    }
}

