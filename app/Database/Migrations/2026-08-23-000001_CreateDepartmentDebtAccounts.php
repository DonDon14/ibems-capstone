<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDepartmentDebtAccounts extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'name' => ['type' => 'VARCHAR', 'constraint' => 160],
            'head_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'is_active' => ['type' => 'BOOLEAN', 'default' => true],
            'status_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('name');
        $this->forge->addKey('head_user_id');
        $this->forge->addForeignKey('head_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('updated_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('departments');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'is_active' => ['type' => 'BOOLEAN', 'default' => true],
            'effective_from' => ['type' => 'DATE'],
            'effective_until' => ['type' => 'DATE', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['department_id', 'user_id']);
        $this->forge->addKey(['user_id', 'is_active']);
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('department_delegates');

        $this->forge->addField([
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'pin_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'failed_attempts' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'window_started_at' => ['type' => 'DATETIME', 'null' => true],
            'locked_until' => ['type' => 'DATETIME', 'null' => true],
            'last_failed_at' => ['type' => 'DATETIME', 'null' => true],
            'last_success_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('department_authorization_pins');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period_month' => ['type' => 'DATE'],
            'allocation_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'used_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'outstanding_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'open'],
            'notes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'configured_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['department_id', 'period_month']);
        $this->forge->addKey(['period_month', 'status']);
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('configured_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('department_debt_periods');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'entry_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'direction' => ['type' => 'VARCHAR', 'constraint' => 12],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'allocation_before' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'allocation_after' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'used_before' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'used_after' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'outstanding_before' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'outstanding_after' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'transaction_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'requester_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'requester_name' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'approved_by_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'actor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'reference_no' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'remarks' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'meta_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['department_id', 'created_at']);
        $this->forge->addKey('period_id');
        $this->forge->addKey('transaction_id');
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('period_id', 'department_debt_periods', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('transaction_id', 'transactions', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('requester_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('approved_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('actor_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('department_debt_entries');

        $this->forge->addField([
            'transaction_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'entry_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'debt_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'requester_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'requester_name' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'approved_by_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('transaction_id', true);
        $this->forge->addKey(['department_id', 'period_id']);
        $this->forge->addForeignKey('transaction_id', 'transactions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('period_id', 'department_debt_periods', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('entry_id', 'department_debt_entries', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('requester_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('approved_by_user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('department_debt_transactions');
    }

    public function down()
    {
        $this->forge->dropTable('department_debt_transactions', true);
        $this->forge->dropTable('department_debt_entries', true);
        $this->forge->dropTable('department_debt_periods', true);
        $this->forge->dropTable('department_authorization_pins', true);
        $this->forge->dropTable('department_delegates', true);
        $this->forge->dropTable('departments', true);
    }
}
