<?php

namespace App\Services;

use Config\Database;

final class HrStaffService
{
    public function create(array $request, int $actorId): array
    {
        $password = (string) ($request['password'] ?? '');
        if ($password !== '' && (strlen($password) < 6 || strlen($password) > 4096)) {
            return ['code' => 400, 'payload' => [
                'status' => 'error',
                'message' => 'Temporary password must be 6 to 4096 characters, or left blank for the default.',
            ]];
        }

        $userType = strtolower(trim((string) ($request['user_type'] ?? '')));
        $employmentType = SalaryCreditPolicy::normalizeEmploymentType((string) ($request['employment_type'] ?? ''));
        if (!in_array($userType, ['faculty', 'staff'], true)
            || !in_array($employmentType, SalaryCreditPolicy::EMPLOYMENT_TYPES, true)) {
            return ['code' => 400, 'payload' => [
                'status' => 'error',
                'message' => 'Select a valid faculty/staff and employment classification.',
            ]];
        }

        $db = Database::connect();
        $schedules = new SalaryScheduleService();
        $scheduleText = trim((string) ($request['salary_schedule_id'] ?? ''));
        $scheduleId = preg_match('/^[1-9][0-9]*$/D', $scheduleText) === 1 ? (int) $scheduleText : 0;
        $grade = (string) ($request['salary_grade'] ?? '');
        $step = SalaryCreditPolicy::parseSalaryStep($request['salary_step'] ?? null);
        $profile = $step !== null ? $schedules->resolveRate($db, $scheduleId, $grade, $step) : null;
        if ($profile === null) {
            return ['code' => 400, 'payload' => [
                'status' => 'error',
                'message' => 'Select a valid salary schedule, grade, and step.',
            ]];
        }
        $today = ibems_business_date();
        if ($today < $profile['effective_from'] || ($profile['effective_to'] !== null && $today > $profile['effective_to'])) {
            return ['code' => 400, 'payload' => [
                'status' => 'error',
                'message' => 'The selected salary schedule is not effective today.',
            ]];
        }

        $staffRequest = [
            'employee_id' => trim((string) ($request['employee_id'] ?? '')),
            'name' => trim((string) ($request['name'] ?? '')),
            'email' => strtolower(trim((string) ($request['email'] ?? ''))),
            'password' => $password,
            'user_type' => $userType,
            'roles' => ['USER'],
            'is_active' => 1,
            'employment_type' => $employmentType,
            'salary_schedule_id' => $profile['schedule_id'],
            'salary_grade' => $profile['salary_grade_label'],
            'salary_step' => $profile['salary_step'],
            'salary_effective_date' => $today,
            'credit_percentage' => SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE),
        ];

        return (new AdminUserService())->create($staffRequest, $actorId, null, 'HR_CREATE_STAFF');
    }

    public function importCsv($file, int $actorId): array
    {
        return (new AdminUserService())->importCsv($file, $actorId, [
            'force_user_type' => 'staff',
            'force_active' => true,
            'allowed_user_types' => ['staff'],
            'allowed_headers' => ['employee_id', 'name', 'email'],
            'strict_column_count' => true,
            'allow_updates' => false,
            'audit_action' => 'HR_IMPORT_STAFF_CSV',
        ]);
    }
}
