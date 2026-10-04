<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUniqueRunMonthToSettlementRuns extends Migration
{
    public function up()
    {
        $indexes = $this->db->getIndexData('settlement_runs');
        $hasUnique = false;

        foreach ($indexes as $name => $index) {
            $fields = $index->fields ?? [];
            $isUnique = (bool) ($index->type ?? false);
            if ($isUnique && count($fields) === 1 && strtolower((string) $fields[0]) === 'run_month') {
                $hasUnique = true;
                break;
            }
        }

        if (!$hasUnique) {
            if ($this->db->DBDriver === 'SQLite3' || $this->db->DBDriver === 'Postgre') {
                $this->db->query('CREATE UNIQUE INDEX uq_settlement_runs_run_month ON settlement_runs (run_month)');
            } else {
                $this->db->query('ALTER TABLE settlement_runs ADD UNIQUE KEY uq_settlement_runs_run_month (run_month)');
            }
        }
    }

    public function down()
    {
        $indexes = $this->db->getIndexData('settlement_runs');
        if (array_key_exists('uq_settlement_runs_run_month', $indexes)) {
            if ($this->db->DBDriver === 'SQLite3' || $this->db->DBDriver === 'Postgre') {
                $this->db->query('DROP INDEX uq_settlement_runs_run_month');
            } else {
                $this->db->query('ALTER TABLE settlement_runs DROP INDEX uq_settlement_runs_run_month');
            }
        }
    }
}
