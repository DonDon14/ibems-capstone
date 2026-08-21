<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDeductionPeriodSnapshots extends Migration
{
    public function up()
    {
        $fields = [
            'opening_debt_snapshot' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'period_debits_snapshot' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'period_credits_snapshot' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'salary_snapshot' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'deduction_choice' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'full'],
            'preparation_reason' => ['type' => 'TEXT', 'null' => true],
        ];
        foreach ($fields as $name => $definition) {
            if (!$this->db->fieldExists($name, 'deduction_batch_items')) {
                $this->forge->addColumn('deduction_batch_items', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach (['preparation_reason', 'deduction_choice', 'salary_snapshot', 'period_credits_snapshot', 'period_debits_snapshot', 'opening_debt_snapshot'] as $field) {
            if ($this->db->fieldExists($field, 'deduction_batch_items')) {
                $this->forge->dropColumn('deduction_batch_items', $field);
            }
        }
    }
}
