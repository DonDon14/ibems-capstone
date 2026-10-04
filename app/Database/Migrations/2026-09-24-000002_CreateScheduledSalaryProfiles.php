<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Keeps future salary decisions separate from the currently active profile. */
class CreateScheduledSalaryProfiles extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('scheduled_salary_profiles')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'effective_date' => ['type' => 'DATE'],
            'revision' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'scheduled'],
            'employment_type' => ['type' => 'VARCHAR', 'constraint' => 20],
            'salary_schedule_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'salary_schedule_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'salary_grade' => ['type' => 'VARCHAR', 'constraint' => 30],
            'salary_step' => ['type' => 'SMALLINT', 'unsigned' => true],
            'schedule_monthly_salary' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'monthly_salary' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'credit_rate' => ['type' => 'DECIMAL', 'constraint' => '6,4'],
            'credit_limit' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'reason' => ['type' => 'TEXT'],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'replaces_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'applied_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'effective_date', 'revision']);
        $this->forge->addKey(['status', 'effective_date']);
        $this->forge->addKey(['user_id', 'status']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('scheduled_salary_profiles', true);
    }

    public function down()
    {
        // Financial decisions and their revision history are intentionally retained.
    }
}
