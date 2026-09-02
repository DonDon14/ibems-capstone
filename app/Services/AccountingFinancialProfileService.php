<?php

namespace App\Services;

use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;

final class AccountingFinancialProfileService
{
    /** @param array<string, mixed> $request @return array{code:int,payload:array<string, mixed>} */
    public function update(BaseConnection $db, array $request, int $actorId): array
    {
        $userId = (int) ($request['user_id'] ?? 0);
        $scheduleId = (int) ($request['salary_schedule_id'] ?? 0);
        $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($request['employment_type'] ?? ''));
        $salaryGrade = trim((string) ($request['salary_grade'] ?? ''));
        $salaryStep = (int) ($request['salary_step'] ?? 0);
        $effectiveDate = trim((string) ($request['salary_effective_date'] ?? ''));
        $creditPercentage = array_key_exists('credit_percentage', $request)
            ? (float) $request['credit_percentage']
            : SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE);
        $reason = trim((string) ($request['reason'] ?? ''));

        if ($userId <= 0 || $scheduleId <= 0 || !in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)
            || $salaryGrade === '' || $salaryStep < 1 || $salaryStep > 8
            || !SalaryCreditPolicy::isValidEffectiveDate($effectiveDate)
            || !SalaryCreditPolicy::isValidCreditPercentage($creditPercentage) || $reason === '') {
            return $this->error(400, 'Salary schedule, grade, step, employment type, effective date, credit percentage, and reason are required.');
        }

        foreach (['employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            if (!$db->fieldExists($field, 'users')) {
                return $this->error(503, 'Dynamic salary-grade setup is unavailable until the latest database migration is applied.');
            }
        }
        if (!$db->fieldExists('credit_rate', 'balances')) {
            return $this->error(503, 'Dynamic credit percentage is unavailable until the latest database migration is applied.');
        }

        $resolvedRate = (new SalaryScheduleService())->resolveRate($db, $scheduleId, $salaryGrade, $salaryStep);
        if ($resolvedRate === null) {
            return $this->error(400, 'The selected salary grade and step are not available in that salary schedule.');
        }
        if ($effectiveDate < $resolvedRate['effective_from']
            || ($resolvedRate['effective_to'] !== null && $effectiveDate > $resolvedRate['effective_to'])) {
            return $this->error(400, 'The effective date must fall within the selected salary schedule.');
        }

        $salary = (float) $resolvedRate['monthly_salary'];
        $creditRate = SalaryCreditPolicy::rateFromPercentage($creditPercentage);
        $creditLimit = SalaryCreditPolicy::creditLimit($salary, $creditRate);
        $current = $db->table('users u')
            ->select('u.base_salary, u.employment_type, u.salary_grade, u.salary_step, u.salary_effective_date, u.salary_schedule_id, u.user_type, u.is_active, b.user_id AS balance_user_id, b.credit_limit, b.credit_rate')
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();
        if (!$current) {
            return $this->error(404, 'Employee not found.');
        }
        if (!ibems_bool($current['is_active'] ?? false) || !in_array(strtolower((string) ($current['user_type'] ?? '')), ['faculty', 'staff'], true)) {
            return $this->error(400, 'Only active Faculty and Staff can have an employee financial profile.');
        }

        $wasConfigured = $current['balance_user_id'] !== null;
        $beforeSalary = (float) ($current['base_salary'] ?? 0);
        $beforeLimit = (float) ($current['credit_limit'] ?? 0);
        $beforeCreditRate = (float) ($current['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE);
        $beforeProfile = [
            'employment_type' => $current['employment_type'] ?? null,
            'salary_grade' => $current['salary_grade'] ?? null,
            'salary_step' => $current['salary_step'] ?? null,
            'salary_effective_date' => $current['salary_effective_date'] ?? null,
            'salary_schedule_id' => $current['salary_schedule_id'] ?? null,
        ];
        $now = date('Y-m-d H:i:s');

        $db->transException(true)->transStart();
        try {
            $db->table('users')->where('id', $userId)->update([
                'base_salary' => $salary,
                'employment_type' => $employmentType,
                'salary_grade' => $resolvedRate['salary_grade_label'],
                'salary_step' => $salaryStep,
                'salary_effective_date' => $effectiveDate,
                'salary_schedule_id' => $scheduleId,
            ]);
            if ($wasConfigured) {
                $db->table('balances')->where('user_id', $userId)->update([
                    'credit_limit' => $creditLimit,
                    'credit_rate' => $creditRate,
                    'updated_at' => $now,
                ]);
            } else {
                $db->table('balances')->insert([
                    'user_id' => $userId,
                    'credit_limit' => $creditLimit,
                    'credit_rate' => $creditRate,
                    'current_debt' => 0,
                    'updated_at' => $now,
                ]);
            }
            (new AuditLogModel())->insert([
                'actor_id' => $actorId,
                'action' => 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE',
                'entity' => 'balances',
                'entity_id' => $userId,
                'payload_json' => json_encode([
                    'previous_base_salary' => $beforeSalary,
                    'new_base_salary' => $salary,
                    'previous_credit_limit' => $beforeLimit,
                    'new_credit_limit' => $creditLimit,
                    'previous_salary_profile' => $beforeProfile,
                    'new_salary_profile' => [
                        'employment_type' => $employmentType,
                        'salary_grade' => $resolvedRate['salary_grade_label'],
                        'salary_step' => $salaryStep,
                        'salary_effective_date' => $effectiveDate,
                        'salary_schedule_id' => $scheduleId,
                        'salary_schedule_code' => $resolvedRate['schedule_code'],
                    ],
                    'previous_credit_percentage' => SalaryCreditPolicy::percentageFromRate($beforeCreditRate),
                    'new_credit_percentage' => $creditPercentage,
                    'credit_rate' => $creditRate,
                    'reason' => $reason,
                    'financial_profile_created' => !$wasConfigured,
                ]),
                'created_at' => $now,
            ]);
            $db->transComplete();
        } catch (\Throwable) {
            $db->transRollback();
            return $this->error(500, 'Financial profile update failed and was rolled back.');
        }

        return [
            'code' => 200,
            'payload' => [
                'status' => 'success',
                'user_id' => $userId,
                'previous_base_salary' => $beforeSalary,
                'new_base_salary' => $salary,
                'previous_credit_limit' => $beforeLimit,
                'new_credit_limit' => $creditLimit,
                'employment_type' => $employmentType,
                'salary_grade' => $resolvedRate['salary_grade_label'],
                'salary_step' => $salaryStep,
                'salary_effective_date' => $effectiveDate,
                'salary_schedule_id' => $scheduleId,
                'salary_schedule_code' => $resolvedRate['schedule_code'],
                'credit_rate' => $creditRate,
                'credit_percentage' => $creditPercentage,
                'financial_profile_created' => !$wasConfigured,
            ],
        ];
    }

    /** @return array{code:int,payload:array{status:string,message:string}} */
    private function error(int $code, string $message): array
    {
        return ['code' => $code, 'payload' => ['status' => 'error', 'message' => $message]];
    }
}
