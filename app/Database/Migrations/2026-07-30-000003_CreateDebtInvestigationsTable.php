<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDebtInvestigationsTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('debt_investigations')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'transaction_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'open'],
            'issue_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'summary' => ['type' => 'TEXT'],
            'evidence_summary' => ['type' => 'TEXT', 'null' => true],
            'findings' => ['type' => 'TEXT', 'null' => true],
            'recommended_action' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'recommended_amount' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'rejection_reason' => ['type' => 'TEXT', 'null' => true],
            'reversal_cashbook_entry_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'opened_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'investigator_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'recommended_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'approved_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'posted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'recommended_at' => ['type' => 'DATETIME', 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'posted_at' => ['type' => 'DATETIME', 'null' => true],
            'closed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'status'], false, false, 'debt_investigations_user_status_idx');
        $this->forge->addKey('transaction_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('transaction_id', 'transactions', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('reversal_cashbook_entry_id', 'debt_cashbook_entries', 'id', 'CASCADE', 'SET NULL');
        foreach (['opened_by', 'investigator_id', 'recommended_by', 'approved_by', 'posted_by'] as $actorField) {
            $this->forge->addForeignKey($actorField, 'users', 'id', 'CASCADE', 'SET NULL');
        }
        $this->forge->createTable('debt_investigations', true);
    }

    public function down()
    {
        $this->forge->dropTable('debt_investigations', true);
    }
}
