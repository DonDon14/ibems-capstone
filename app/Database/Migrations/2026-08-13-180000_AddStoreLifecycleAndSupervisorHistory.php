<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStoreLifecycleAndSupervisorHistory extends Migration
{
    public function up()
    {
        $fields = $this->db->getFieldNames('stores');
        $add = [];
        if (!in_array('deactivated_at', $fields, true)) $add['deactivated_at'] = ['type' => 'DATETIME', 'null' => true];
        if (!in_array('deactivated_by', $fields, true)) $add['deactivated_by'] = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true];
        if (!in_array('deactivation_reason', $fields, true)) $add['deactivation_reason'] = ['type' => 'TEXT', 'null' => true];
        if (!in_array('reactivated_at', $fields, true)) $add['reactivated_at'] = ['type' => 'DATETIME', 'null' => true];
        if (!in_array('reactivated_by', $fields, true)) $add['reactivated_by'] = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true];
        if ($add !== []) $this->forge->addColumn('stores', $add);

        if (!$this->db->tableExists('store_supervisor_assignment_history')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'assigned_at' => ['type' => 'DATETIME'],
                'assigned_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'ended_at' => ['type' => 'DATETIME', 'null' => true],
                'ended_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['store_id', 'ended_at']);
            $this->forge->addKey(['user_id', 'ended_at']);
            $this->forge->createTable('store_supervisor_assignment_history', true);

            $now = date('Y-m-d H:i:s');
            foreach ($this->db->table('store_supervisors')->get()->getResultArray() as $row) {
                $this->db->table('store_supervisor_assignment_history')->insert([
                    'store_id' => (int) $row['store_id'],
                    'user_id' => (int) $row['user_id'],
                    'assigned_at' => $row['created_at'] ?: $now,
                    'assigned_by' => null,
                ]);
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('store_supervisor_assignment_history', true);
        $this->forge->dropColumn('stores', ['deactivated_at', 'deactivated_by', 'deactivation_reason', 'reactivated_at', 'reactivated_by']);
    }
}
