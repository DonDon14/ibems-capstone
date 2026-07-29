<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVarianceReviewMetadataToStoreDaySessions extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_day_sessions')) {
            return;
        }

        $fields = [];
        if (!$this->db->fieldExists('reviewed_by', 'store_day_sessions')) {
            $fields['reviewed_by'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'review_status',
            ];
        }

        if (!$this->db->fieldExists('reviewed_at', 'store_day_sessions')) {
            $fields['reviewed_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'reviewed_by',
            ];
        }

        if (!$this->db->fieldExists('review_note', 'store_day_sessions')) {
            $fields['review_note'] = [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'reviewed_at',
            ];
        }

        if (!$this->db->fieldExists('accountability_user_id', 'store_day_sessions')) {
            $fields['accountability_user_id'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'review_note',
            ];
        }

        if (!$this->db->fieldExists('accountability_amount', 'store_day_sessions')) {
            $fields['accountability_amount'] = [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
                'after' => 'accountability_user_id',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('store_day_sessions', $fields);
        }
    }

    public function down()
    {
        if (!$this->db->tableExists('store_day_sessions')) {
            return;
        }

        $fields = [];
        foreach (['reviewed_by', 'reviewed_at', 'review_note', 'accountability_user_id', 'accountability_amount'] as $field) {
            if ($this->db->fieldExists($field, 'store_day_sessions')) {
                $fields[] = $field;
            }
        }

        if ($fields !== []) {
            $this->forge->dropColumn('store_day_sessions', $fields);
        }
    }
}
