<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVarianceReviewToStoreDaySessions extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_day_sessions')) {
            return;
        }

        $fields = [];

        if (!$this->db->fieldExists('variance_status', 'store_day_sessions')) {
            $fields['variance_status'] = [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'balanced',
                'after' => 'variance_ecash',
            ];
        }

        if (!$this->db->fieldExists('review_status', 'store_day_sessions')) {
            $fields['review_status'] = [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'not_required',
                'after' => 'variance_status',
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
        foreach (['variance_status', 'review_status'] as $field) {
            if ($this->db->fieldExists($field, 'store_day_sessions')) {
                $fields[] = $field;
            }
        }

        if ($fields !== []) {
            $this->forge->dropColumn('store_day_sessions', $fields);
        }
    }
}
