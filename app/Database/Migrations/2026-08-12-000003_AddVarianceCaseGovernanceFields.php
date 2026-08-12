<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVarianceCaseGovernanceFields extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('retention_until', 'store_day_variance_case_attachments')) {
            $this->forge->addColumn('store_day_variance_case_attachments', [
                'retention_until' => ['type' => 'DATE', 'null' => true, 'after' => 'description'],
            ]);
        }
        $fields = [];
        if (!$this->db->fieldExists('due_at', 'store_day_variance_case_handoffs')) {
            $fields['due_at'] = ['type' => 'DATETIME', 'null' => true, 'after' => 'created_at'];
        }
        if (!$this->db->fieldExists('acknowledged_by', 'store_day_variance_case_handoffs')) {
            $fields['acknowledged_by'] = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'due_at'];
        }
        if ($fields !== []) $this->forge->addColumn('store_day_variance_case_handoffs', $fields);

        $this->db->table('store_day_variance_case_attachments')->where('retention_until IS NULL', null, false)
            ->set('retention_until', date('Y-m-d', strtotime('+7 years')))->update();
        $this->db->table('store_day_variance_case_handoffs')->where('due_at IS NULL', null, false)
            ->set('due_at', date('Y-m-d H:i:s', strtotime('+48 hours')))->update();
    }

    public function down()
    {
        foreach (['acknowledged_by', 'due_at'] as $field) if ($this->db->fieldExists($field, 'store_day_variance_case_handoffs')) $this->forge->dropColumn('store_day_variance_case_handoffs', $field);
        if ($this->db->fieldExists('retention_until', 'store_day_variance_case_attachments')) $this->forge->dropColumn('store_day_variance_case_attachments', 'retention_until');
    }
}
