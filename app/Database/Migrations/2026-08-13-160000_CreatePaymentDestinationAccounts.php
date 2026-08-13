<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePaymentDestinationAccounts extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('payment_destination_accounts')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'payment_method_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'account_name' => ['type' => 'VARCHAR', 'constraint' => 120],
                'account_number' => ['type' => 'VARCHAR', 'constraint' => 120],
                'image_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'is_active' => ['type' => 'BOOLEAN', 'default' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['store_id', 'payment_method_id', 'account_number']);
            $this->forge->addKey(['store_id', 'payment_method_id', 'is_active']);
            $this->forge->addForeignKey('store_id', 'stores', 'id', 'RESTRICT', 'CASCADE');
            $this->forge->addForeignKey('payment_method_id', 'store_payment_methods', 'id', 'RESTRICT', 'CASCADE');
            $this->forge->createTable('payment_destination_accounts');
        }

        if ($this->db->tableExists('transaction_payments') && !$this->db->fieldExists('destination_account_id', 'transaction_payments')) {
            $this->forge->addColumn('transaction_payments', [
                'destination_account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'payment_method'],
                'destination_account_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'destination_account_id'],
                'destination_account_number' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'destination_account_name'],
            ]);
            $this->forge->addForeignKey('destination_account_id', 'payment_destination_accounts', 'id', 'SET NULL', 'CASCADE');
        }

        if (!$this->db->tableExists('store_day_payment_account_balances')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'store_day_session_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'destination_account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'account_name_snapshot' => ['type' => 'VARCHAR', 'constraint' => 120],
                'account_number_snapshot' => ['type' => 'VARCHAR', 'constraint' => 120],
                'opening_balance' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'expected_balance' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
                'counted_balance' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
                'variance' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['store_day_session_id', 'destination_account_id']);
            $this->forge->addForeignKey('store_day_session_id', 'store_day_sessions', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('destination_account_id', 'payment_destination_accounts', 'id', 'RESTRICT', 'CASCADE');
            $this->forge->createTable('store_day_payment_account_balances');
        }
    }

    public function down()
    {
        $this->forge->dropTable('store_day_payment_account_balances', true);
        if ($this->db->tableExists('transaction_payments')) {
            foreach (['destination_account_id', 'destination_account_name', 'destination_account_number'] as $field) {
                if ($this->db->fieldExists($field, 'transaction_payments')) $this->forge->dropColumn('transaction_payments', $field);
            }
        }
        $this->forge->dropTable('payment_destination_accounts', true);
    }
}
