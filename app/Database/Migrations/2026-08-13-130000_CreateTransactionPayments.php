<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTransactionPayments extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('transaction_payments')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'transaction_id' => ['type' => 'INT', 'unsigned' => true],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 50],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'cash_received' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'change_due' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['transaction_id', 'payment_method']);
        $this->forge->addForeignKey('transaction_id', 'transactions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('transaction_payments');
    }

    public function down()
    {
        if ($this->db->tableExists('transaction_payments')) {
            $this->forge->dropTable('transaction_payments');
        }
    }
}
