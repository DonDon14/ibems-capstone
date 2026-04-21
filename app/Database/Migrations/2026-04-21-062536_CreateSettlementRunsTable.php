<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSettlementRunsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'run_month' => [
                'type' => 'VARCHAR',
                'constraint' => 7, // format: YYYY-MM
            ],
            'run_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'run_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'total_accounts' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'total_debt_before' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0.00,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('run_by', 'users', 'id', 'RESTRICT', 'CASCADE');

        $this->forge->createTable('settlement_runs');
    }

    public function down()
    {
        $this->forge->dropTable('settlement_runs');
    }
}
