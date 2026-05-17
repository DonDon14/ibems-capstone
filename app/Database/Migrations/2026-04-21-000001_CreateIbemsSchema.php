<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateIbemsSchema extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'employee_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'email' => ['type' => 'VARCHAR', 'constraint' => 150],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'role' => ['type' => 'VARCHAR', 'constraint' => 30],
            'user_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'qr_token' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'base_salary' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->addUniqueKey('employee_id');
        $this->forge->addUniqueKey('qr_token');
        $this->forge->createTable('users', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'store_name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'officer_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('officer_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('stores', true);

        $this->forge->addField([
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'credit_limit' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 1000],
            'current_debt' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('balances', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sku' => ['type' => 'VARCHAR', 'constraint' => 50],
            'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'price' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'stock_qty' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['store_id', 'sku']);
        $this->forge->addForeignKey('store_id', 'stores', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('products', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'client_txn_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'customer_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 30],
            'other_payment_label' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'walkin_note' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'synced'],
            'reference_no' => ['type' => 'VARCHAR', 'constraint' => 60],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'synced_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('client_txn_id');
        $this->forge->addUniqueKey('reference_no');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('store_id', 'stores', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('transactions', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'transaction_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'product_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'qty' => ['type' => 'INT', 'constraint' => 11],
            'unit_price' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'line_total' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('transaction_id', 'transactions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('transaction_items', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'product_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type' => ['type' => 'VARCHAR', 'constraint' => 30],
            'qty' => ['type' => 'INT', 'constraint' => 11],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'txn_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('store_id', 'stores', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('txn_id', 'transactions', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('inventory_movements', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'filename' => ['type' => 'VARCHAR', 'constraint' => 190],
            'imported_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'imported_at' => ['type' => 'DATETIME', 'null' => true],
            'total_rows' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'valid_rows' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'invalid_rows' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('imported_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('salary_import_batches', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'batch_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'employee_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'monthly_salary' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20],
            'error_msg' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('batch_id', 'salary_import_batches', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('salary_import_rows', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'run_month' => ['type' => 'VARCHAR', 'constraint' => 7],
            'run_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'run_at' => ['type' => 'DATETIME', 'null' => true],
            'total_accounts' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'total_debt_before' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'notes' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('run_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('settlement_runs', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'txn_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 30],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
            'error_msg' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('txn_id', 'transactions', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('notifications', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'actor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 80],
            'entity' => ['type' => 'VARCHAR', 'constraint' => 50],
            'entity_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('actor_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('audit_logs', true);
    }

    public function down()
    {
        $tables = [
            'audit_logs',
            'notifications',
            'settlement_runs',
            'salary_import_rows',
            'salary_import_batches',
            'inventory_movements',
            'transaction_items',
            'transactions',
            'products',
            'balances',
            'stores',
            'users',
        ];

        foreach ($tables as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
