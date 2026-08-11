<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDeductionWorkflowTables extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('deduction_periods')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'period_code' => [
                    'type' => 'VARCHAR',
                    'constraint' => 40,
                ],
                'label' => [
                    'type' => 'VARCHAR',
                    'constraint' => 120,
                ],
                'frequency' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                ],
                'date_start' => [
                    'type' => 'DATE',
                ],
                'date_end' => [
                    'type' => 'DATE',
                ],
                'expected_processing_date' => [
                    'type' => 'DATE',
                    'null' => true,
                ],
                'preparation_deadline' => [
                    'type' => 'DATE',
                    'null' => true,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 30,
                    'default' => 'draft',
                ],
                'notes' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'reviewed_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'submitted_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'confirmed_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'finalized_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
                'submitted_at' => ['type' => 'DATETIME', 'null' => true],
                'confirmed_at' => ['type' => 'DATETIME', 'null' => true],
                'finalized_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('period_code', 'deduction_periods_code_unique');
            $this->forge->addKey(['date_start', 'date_end'], false, false, 'deduction_periods_dates_idx');
            $this->forge->addKey('status');
            $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->addForeignKey('reviewed_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->addForeignKey('submitted_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->addForeignKey('confirmed_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->addForeignKey('finalized_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->createTable('deduction_periods', true);
        }

        if (!$this->db->tableExists('deduction_batches')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'period_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 30,
                    'default' => 'draft',
                ],
                'total_accounts' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 0,
                ],
                'total_requested' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'total_confirmed' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'total_carryover' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'legacy_settlement_run_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'notes' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('period_id', 'deduction_batches_period_unique');
            $this->forge->addUniqueKey('legacy_settlement_run_id', 'deduction_batches_legacy_run_unique');
            $this->forge->addKey('status');
            $this->forge->addForeignKey('period_id', 'deduction_periods', 'id', 'CASCADE', 'RESTRICT');
            $this->forge->addForeignKey('legacy_settlement_run_id', 'settlement_runs', 'id', 'CASCADE', 'SET NULL');
            $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->createTable('deduction_batches', true);
        }

        if (!$this->db->tableExists('deduction_batch_items')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'batch_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'user_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'debt_snapshot' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'requested_amount' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'confirmed_amount' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'carryover_amount' => [
                    'type' => 'DECIMAL',
                    'constraint' => '12,2',
                    'default' => 0,
                ],
                'result_status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 40,
                    'default' => 'pending',
                ],
                'reason_code' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                    'null' => true,
                ],
                'result_reference' => [
                    'type' => 'VARCHAR',
                    'constraint' => 120,
                    'null' => true,
                ],
                'result_notes' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'confirmed_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'confirmed_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['batch_id', 'user_id'], 'deduction_batch_items_user_unique');
            $this->forge->addKey(['user_id', 'result_status'], false, false, 'deduction_batch_items_result_idx');
            $this->forge->addForeignKey('batch_id', 'deduction_batches', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'RESTRICT');
            $this->forge->addForeignKey('confirmed_by', 'users', 'id', 'CASCADE', 'SET NULL');
            $this->forge->createTable('deduction_batch_items', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('deduction_batch_items', true);
        $this->forge->dropTable('deduction_batches', true);
        $this->forge->dropTable('deduction_periods', true);
    }
}
