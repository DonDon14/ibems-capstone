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
            $this->db->query('ALTER TABLE settlement_runs ADD UNIQUE KEY uq_settlement_runs_run_month (run_month)');
        }
    }

    public function down()
    {
        $indexes = $this->db->getIndexData('settlement_runs');
        if (array_key_exists('uq_settlement_runs_run_month', $indexes)) {
            $this->db->query('ALTER TABLE settlement_runs DROP INDEX uq_settlement_runs_run_month');
        }
    }
}
