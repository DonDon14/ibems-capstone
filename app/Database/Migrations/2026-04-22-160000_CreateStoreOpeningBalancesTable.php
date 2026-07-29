<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoreOpeningBalancesTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('store_opening_balances')) {
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
            'opening_balance' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'note' => [
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
        $this->forge->createTable('store_opening_balances');
    }

    public function down()
    {
        $this->forge->dropTable('store_opening_balances', true);
    }
}
