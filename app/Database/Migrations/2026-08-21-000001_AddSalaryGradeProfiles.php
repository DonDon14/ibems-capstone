<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSalaryGradeProfiles extends Migration
{
    public function up()
    {
        $columns = [
            'employment_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'base_salary'],
            'salary_grade' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'employment_type'],
            'salary_step' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'after' => 'salary_grade'],
            'salary_effective_date' => ['type' => 'DATE', 'null' => true, 'after' => 'salary_step'],
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'users')) {
                $this->forge->addColumn('users', [$name => $definition]);
            }
        }

        if ($this->db->DBDriver === 'Postgre') {
            $constraints = $this->db->query(<<<'SQL'
                select c.conname
                from pg_constraint c
                where c.conrelid = 'public.balances'::regclass
                  and c.contype = 'c'
                  and pg_get_constraintdef(c.oid) like '%current_debt%credit_limit%'
                SQL)->getResultArray();
            foreach ($constraints as $constraint) {
                $name = str_replace('"', '""', (string) ($constraint['conname'] ?? ''));
                if ($name !== '') {
                    $this->db->query('ALTER TABLE public.balances DROP CONSTRAINT "' . $name . '"');
                }
            }
        }
    }

    public function down()
    {
        // Financial profile history is intentionally retained.
    }
}
