<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Immutable, versioned handoffs from store close to BE, Cashier, and Accounting. */
class CreateDailyStoreReports extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('daily_store_reports')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'store_day_session_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'business_date' => ['type' => 'DATE'],
                'revision' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 1],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'submitted'],
                'transaction_count' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'sales_total' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'cash_total' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'debt_total' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'other_total' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'source_json' => ['type' => 'TEXT'],
                'submission_note' => ['type' => 'TEXT', 'null' => true],
                'submitted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'submitted_at' => ['type' => 'DATETIME'],
                'be_reviewed_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'be_reviewed_at' => ['type' => 'DATETIME', 'null' => true],
                'be_review_note' => ['type' => 'TEXT', 'null' => true],
                'cashier_received_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'cashier_received_at' => ['type' => 'DATETIME', 'null' => true],
                'accounting_furnished_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['store_id', 'business_date', 'revision']);
            $this->forge->addKey(['status', 'business_date']);
            $this->forge->addKey('store_day_session_id');
            $this->forge->createTable('daily_store_reports', true);
        }

        if (!$this->db->tableExists('daily_store_report_events')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'report_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'event_type' => ['type' => 'VARCHAR', 'constraint' => 32],
                'actor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'note' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['report_id', 'created_at']);
            $this->forge->createTable('daily_store_report_events', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('daily_store_report_events', true);
        $this->forge->dropTable('daily_store_reports', true);
    }
}
