<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class SalaryScheduleService
{
    public const STANDARD_SCHEDULE_CODE = 'PH-NG-2026-T3';
    public const STANDARD_GRADE = 11;
    public const STANDARD_STEP = 1;

    public function catalog(BaseConnection $db): array
    {
        if (!$db->tableExists('salary_schedules') || !$db->tableExists('salary_schedule_rates')) {
            return [];
        }

        $schedules = $db->table('salary_schedules')
            ->select('id, code, name, effective_from, effective_to, is_active')
            ->where('is_active', true)
            ->orderBy('effective_from', 'DESC')
            ->get()->getResultArray();
        if ($schedules === []) {
            return [];
        }

        $scheduleIds = array_map(static fn(array $row): int => (int) $row['id'], $schedules);
        $rates = $db->table('salary_schedule_rates')
            ->select('schedule_id, salary_grade, salary_step, monthly_salary')
            ->whereIn('schedule_id', $scheduleIds)
            ->orderBy('salary_grade', 'ASC')
            ->orderBy('salary_step', 'ASC')
            ->get()->getResultArray();
        $ratesBySchedule = [];
        foreach ($rates as $rate) {
            $ratesBySchedule[(int) $rate['schedule_id']][] = [
                'salary_grade' => (int) $rate['salary_grade'],
                'salary_step' => (int) $rate['salary_step'],
                'monthly_salary' => (float) $rate['monthly_salary'],
            ];
        }

        return array_map(static function (array $schedule) use ($ratesBySchedule): array {
            $id = (int) $schedule['id'];
            return [
                'id' => $id,
                'code' => (string) $schedule['code'],
                'name' => (string) $schedule['name'],
                'effective_from' => (string) $schedule['effective_from'],
                'effective_to' => $schedule['effective_to'] !== null ? (string) $schedule['effective_to'] : null,
                'is_active' => ibems_bool($schedule['is_active']),
                'rates' => $ratesBySchedule[$id] ?? [],
            ];
        }, $schedules);
    }

    public function resolveRate(BaseConnection $db, int $scheduleId, string|int $salaryGrade, int $salaryStep): ?array
    {
        if ($scheduleId <= 0 || $salaryStep < 1 || $salaryStep > 8
            || !$db->tableExists('salary_schedules') || !$db->tableExists('salary_schedule_rates')) {
            return null;
        }

        $grade = $this->normalizeGradeNumber($salaryGrade);
        if ($grade < 1 || $grade > 33) {
            return null;
        }

        $row = $db->table('salary_schedule_rates r')
            ->select('r.monthly_salary, r.salary_grade, r.salary_step, s.id AS schedule_id, s.code AS schedule_code, s.name AS schedule_name, s.effective_from, s.effective_to')
            ->join('salary_schedules s', 's.id = r.schedule_id')
            ->where('s.id', $scheduleId)
            ->where('s.is_active', true)
            ->where('r.salary_grade', $grade)
            ->where('r.salary_step', $salaryStep)
            ->get()->getRowArray();
        if (!$row) {
            return null;
        }

        return [
            'schedule_id' => (int) $row['schedule_id'],
            'schedule_code' => (string) $row['schedule_code'],
            'schedule_name' => (string) $row['schedule_name'],
            'effective_from' => (string) $row['effective_from'],
            'effective_to' => $row['effective_to'] !== null ? (string) $row['effective_to'] : null,
            'salary_grade' => $grade,
            'salary_grade_label' => 'SG-' . $grade,
            'salary_step' => (int) $row['salary_step'],
            'monthly_salary' => (float) $row['monthly_salary'],
        ];
    }

    public function standardProfile(BaseConnection $db): ?array
    {
        if (!$db->tableExists('salary_schedules')) {
            return null;
        }
        $schedule = $db->table('salary_schedules')
            ->select('id')
            ->where('code', self::STANDARD_SCHEDULE_CODE)
            ->get()->getRowArray();

        return $schedule
            ? $this->resolveRate($db, (int) $schedule['id'], self::STANDARD_GRADE, self::STANDARD_STEP)
            : null;
    }

    public function resolveRateByCode(BaseConnection $db, string $scheduleCode, string|int $salaryGrade, int $salaryStep): ?array
    {
        if (!$db->tableExists('salary_schedules')) {
            return null;
        }
        $schedule = $db->table('salary_schedules')
            ->select('id')
            ->where('code', strtoupper(trim($scheduleCode)))
            ->get()->getRowArray();

        return $schedule
            ? $this->resolveRate($db, (int) $schedule['id'], $salaryGrade, $salaryStep)
            : null;
    }

    public function normalizeGradeNumber(string|int $salaryGrade): int
    {
        $value = strtoupper(trim((string) $salaryGrade));
        if (preg_match('/^(?:SG[- ]?)?(\d{1,2})$/', $value, $matches) !== 1) {
            return 0;
        }
        return (int) $matches[1];
    }
}
