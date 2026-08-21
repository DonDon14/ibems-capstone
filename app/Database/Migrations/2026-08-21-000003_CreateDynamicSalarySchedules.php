<?php

namespace App\Database\Migrations;

use App\Services\SalaryCreditPolicy;
use App\Services\SalaryScheduleService;
use CodeIgniter\Database\Migration;

class CreateDynamicSalarySchedules extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('salary_schedules')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'code' => ['type' => 'VARCHAR', 'constraint' => 40],
                'name' => ['type' => 'VARCHAR', 'constraint' => 180],
                'effective_from' => ['type' => 'DATE'],
                'effective_to' => ['type' => 'DATE', 'null' => true],
                'is_active' => ['type' => 'BOOLEAN', 'default' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('code');
            $this->forge->createTable('salary_schedules');
        }

        if (!$this->db->tableExists('salary_schedule_rates')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'schedule_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'salary_grade' => ['type' => 'SMALLINT', 'unsigned' => true],
                'salary_step' => ['type' => 'SMALLINT', 'unsigned' => true],
                'monthly_salary' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['schedule_id', 'salary_grade', 'salary_step']);
            $this->forge->addForeignKey('schedule_id', 'salary_schedules', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('salary_schedule_rates');
        }

        if (!$this->db->fieldExists('salary_schedule_id', 'users')) {
            $this->forge->addColumn('users', [
                'salary_schedule_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'salary_effective_date'],
            ]);
        }
        if (!$this->db->fieldExists('credit_rate', 'balances')) {
            $this->forge->addColumn('balances', [
                'credit_rate' => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => SalaryCreditPolicy::DEFAULT_CREDIT_RATE, 'after' => 'credit_limit'],
            ]);
        }

        $schedule = $this->db->table('salary_schedules')
            ->where('code', SalaryScheduleService::STANDARD_SCHEDULE_CODE)
            ->get()->getRowArray();
        if (!$schedule) {
            $this->db->table('salary_schedules')->insert([
                'code' => SalaryScheduleService::STANDARD_SCHEDULE_CODE,
                'name' => 'Philippine National Government 2026 - Third Tranche',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $schedule = $this->db->table('salary_schedules')
                ->where('code', SalaryScheduleService::STANDARD_SCHEDULE_CODE)
                ->get()->getRowArray();
        }
        $scheduleId = (int) ($schedule['id'] ?? 0);
        if ($scheduleId <= 0) {
            throw new \RuntimeException('Unable to create the standard 2026 salary schedule.');
        }

        if ($this->db->table('salary_schedule_rates')->where('schedule_id', $scheduleId)->countAllResults() === 0) {
            $this->db->table('salary_schedule_rates')->insertBatch($this->salaryRates($scheduleId));
        }

        $standardSalary = 31705.00;
        $standardLimit = SalaryCreditPolicy::creditLimit($standardSalary);
        $employees = $this->db->table('users')
            ->select('id')
            ->whereIn('user_type', ['faculty', 'staff'])
            ->get()->getResultArray();
        foreach ($employees as $employee) {
            $userId = (int) $employee['id'];
            $this->db->table('users')->where('id', $userId)->update([
                'base_salary' => $standardSalary,
                'employment_type' => 'plantilla',
                'salary_grade' => 'SG-11',
                'salary_step' => 1,
                'salary_effective_date' => '2026-01-01',
                'salary_schedule_id' => $scheduleId,
            ]);

            $balance = $this->db->table('balances')->where('user_id', $userId)->get()->getRowArray();
            if ($balance) {
                $this->db->table('balances')->where('user_id', $userId)->update([
                    'credit_limit' => $standardLimit,
                    'credit_rate' => SalaryCreditPolicy::DEFAULT_CREDIT_RATE,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $this->db->table('balances')->insert([
                    'user_id' => $userId,
                    'credit_limit' => $standardLimit,
                    'credit_rate' => SalaryCreditPolicy::DEFAULT_CREDIT_RATE,
                    'current_debt' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down()
    {
        // Salary schedules and employee financial history are intentionally retained.
    }

    private function salaryRates(int $scheduleId): array
    {
        $steps = [
            1 => [14634,15522,16486,17506,18581,19716,20914,22423,24329,26917,31705,33947,36125,38764,42178,45694,49562,53818,59153,66052,73303,81796,91306,102603,116643,131807,148940,167129,187531,210718,300961,356237,449157],
            2 => [14730,15636,16610,17636,18720,19862,21069,22627,24523,27131,31820,34069,36283,39141,42594,46152,50066,54371,59966,66970,74337,82963,92622,104209,118469,133870,151273,169752,190482,214038,306691,363257,462329],
            3 => [14849,15752,16732,17767,18858,20009,21224,22832,24720,27347,32109,34357,36599,39523,43015,46615,50576,54933,60793,67904,75388,84151,93962,105841,120326,135968,153644,172418,193480,217207,312532,370418],
            4 => [14968,15869,16856,17898,18998,20158,21382,23038,24917,27565,32401,34648,36919,39910,43442,47084,51092,55499,61632,68853,76456,85356,95330,107500,122212,138100,155906,174797,196528,220425,318182,377359],
            5 => [15089,15986,16982,18031,19137,20307,21539,23246,25117,27786,32697,34943,37244,40300,43874,47559,51614,56075,62486,69818,77542,86582,96823,109185,124131,140268,158353,177545,199624,223691,323938,384805],
            6 => [15211,16103,17106,18163,19280,20456,21699,23456,25318,28007,32998,35242,37572,40696,44310,48040,52144,56657,63353,70772,78645,87746,98341,110898,126079,142469,160235,180339,202005,227224,329989,392400],
            7 => [15333,16223,17234,18298,19423,20609,21859,23668,25521,28230,33302,35544,37904,41097,44753,48528,52678,57246,64236,71727,79692,89011,99883,112533,128061,144707,162752,182660,205191,230595,336092,400150],
            8 => [15456,16342,17360,18433,19565,20761,22022,23883,25725,28456,33611,35850,38241,41503,45202,49020,53221,57842,65132,72671,80831,90295,101318,114301,130073,146983,165310,185537,208430,234240,342310,408055],
        ];

        $rows = [];
        foreach ($steps as $step => $salaries) {
            foreach ($salaries as $offset => $salary) {
                $rows[] = [
                    'schedule_id' => $scheduleId,
                    'salary_grade' => $offset + 1,
                    'salary_step' => $step,
                    'monthly_salary' => $salary,
                ];
            }
        }
        return $rows;
    }
}
