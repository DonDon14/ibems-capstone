<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Queues future salary decisions and activates them without rewriting prior revisions. */
final class ScheduledSalaryProfileService
{
    public function schedule(BaseConnection $db, int $userId, array $profile, int $actorId, string $reason): array
    {
        if (!$db->tableExists('scheduled_salary_profiles')) {
            return $this->error(503, 'Scheduled salary profiles are unavailable until the latest migration is applied.');
        }
        $effectiveDate = (string) ($profile['effective_date'] ?? '');
        if (!SalaryCreditPolicy::isFutureEffectiveDate($effectiveDate)) {
            return $this->error(400, 'A scheduled salary profile requires a future effective date.');
        }

        $now = ibems_now()->format('Y-m-d H:i:s');
        $db->transException(true)->transStart();
        try {
            $latest = $db->table('scheduled_salary_profiles')
                ->select('id, revision, status')
                ->where('user_id', $userId)
                ->where('effective_date', $effectiveDate)
                ->orderBy('revision', 'DESC')
                ->get()->getRowArray();
            if ($latest !== null && !in_array($latest['status'], ['scheduled', 'replaced'], true)) {
                throw new \RuntimeException('An activated salary profile cannot be revised.');
            }
            $replacedId = $latest && $latest['status'] === 'scheduled' ? (int) $latest['id'] : null;
            if ($replacedId !== null) {
                $db->table('scheduled_salary_profiles')
                    ->where('id', $replacedId)->where('status', 'scheduled')
                    ->update(['status' => 'replaced']);
                if ($db->affectedRows() !== 1) {
                    throw new \RuntimeException('The pending profile changed during replacement.');
                }
            }

            $revision = (int) ($latest['revision'] ?? 0) + 1;
            $db->table('scheduled_salary_profiles')->insert([
                'user_id' => $userId,
                'effective_date' => $effectiveDate,
                'revision' => $revision,
                'status' => 'scheduled',
                'employment_type' => $profile['employment_type'],
                'salary_schedule_id' => $profile['schedule_id'],
                'salary_schedule_code' => $profile['schedule_code'],
                'salary_grade' => $profile['salary_grade_label'],
                'salary_step' => $profile['salary_step'],
                'schedule_monthly_salary' => $profile['schedule_monthly_salary'],
                'monthly_salary' => $profile['monthly_salary'],
                'credit_rate' => $profile['credit_rate'],
                'credit_limit' => $profile['credit_limit'],
                'reason' => $reason,
                'created_by' => $actorId,
                'replaces_id' => $replacedId,
                'created_at' => $now,
            ]);
            $id = (int) $db->insertID();
            $db->table('audit_logs')->insert([
                'actor_id' => $actorId,
                'action' => $replacedId === null ? 'ACCOUNTING_SCHEDULE_FINANCIAL_PROFILE' : 'ACCOUNTING_REVISE_SCHEDULED_PROFILE',
                'entity' => 'scheduled_salary_profiles',
                'entity_id' => $id,
                'payload_json' => json_encode([
                    'user_id' => $userId,
                    'effective_date' => $effectiveDate,
                    'revision' => $revision,
                    'replaces_id' => $replacedId,
                    'profile' => $profile,
                    'reason' => $reason,
                ]),
                'created_at' => $now,
            ]);
            $db->transComplete();
            if (!$db->transStatus()) {
                throw new \RuntimeException('Scheduled salary transaction did not commit.');
            }
        } catch (\Throwable) {
            $db->transRollback();
            return $this->error(409, 'The scheduled salary profile could not be saved. Reload the employee profile and retry.');
        }

        return ['code' => 202, 'payload' => [
            'status' => 'scheduled',
            'scheduled_profile_id' => $id,
            'user_id' => $userId,
            'effective_date' => $effectiveDate,
            'revision' => $revision,
            'replaced_profile_id' => $replacedId,
            'monthly_salary' => (float) $profile['monthly_salary'],
            'credit_limit' => (float) $profile['credit_limit'],
        ]];
    }

    public function pendingForUser(BaseConnection $db, int $userId): array
    {
        if ($userId <= 0 || !$db->tableExists('scheduled_salary_profiles')) {
            return [];
        }

        return $db->table('scheduled_salary_profiles')
            ->select('id, effective_date, revision, employment_type, salary_schedule_code, salary_grade, salary_step, schedule_monthly_salary, monthly_salary, credit_rate, credit_limit, reason, created_by, created_at')
            ->where('user_id', $userId)->where('status', 'scheduled')
            ->orderBy('effective_date', 'ASC')->get()->getResultArray();
    }

    /** @return array{total_pending:int,due_count:int,page:int,page_size:int,has_more:bool,rows:array<int,array<string,mixed>>}|null */
    public function pendingQueue(BaseConnection $db, int $page = 1, int $limit = 50): ?array
    {
        if (!$db->tableExists('scheduled_salary_profiles')) {
            return null;
        }
        $today = ibems_business_date();
        $total = $db->table('scheduled_salary_profiles')->where('status', 'scheduled')->countAllResults();
        $due = $db->table('scheduled_salary_profiles')->where('status', 'scheduled')
            ->where('effective_date <=', $today)->countAllResults();
        $pageSize = max(1, min($limit, 100));
        $page = max(1, $page);
        $rows = $db->table('scheduled_salary_profiles s')
            ->select('s.id, s.user_id, s.effective_date, s.revision, s.salary_grade, s.salary_step, s.monthly_salary, s.credit_limit, s.reason, u.employee_id, u.name')
            ->join('users u', 'u.id = s.user_id')
            ->where('s.status', 'scheduled')
            ->orderBy('s.effective_date', 'ASC')->orderBy('s.id', 'ASC')
            ->limit($pageSize, ($page - 1) * $pageSize)->get()->getResultArray();

        return [
            'total_pending' => $total,
            'due_count' => $due,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => $page * $pageSize < $total,
            'rows' => $rows,
        ];
    }

    public function activate(BaseConnection $db, int $profileId, int $actorId, ?string $asOf = null): array
    {
        $asOf ??= ibems_business_date();
        if (!SalaryCreditPolicy::isValidEffectiveDate($asOf)) {
            return $this->error(400, 'Activation date must be YYYY-MM-DD.');
        }
        if (!$db->tableExists('scheduled_salary_profiles')) {
            return $this->error(503, 'Scheduled salary profiles are unavailable until the latest migration is applied.');
        }
        if ($profileId <= 0 || $actorId <= 0) {
            return $this->error(400, 'A scheduled profile and Accounting actor are required.');
        }
        $profile = $db->table('scheduled_salary_profiles')->where('id', $profileId)->get()->getRowArray();
        if ($profile === null) {
            return $this->error(404, 'Scheduled salary profile not found.');
        }
        if ($profile['status'] !== 'scheduled') {
            return $this->error(409, 'This scheduled profile is no longer pending.');
        }
        if ($profile['effective_date'] > $asOf) {
            return $this->error(409, 'This salary profile is not effective yet.');
        }
        $db->transException(true)->transStart();
        try {
            $earlier = $db->table('scheduled_salary_profiles')->where('user_id', (int) $profile['user_id'])
                ->where('status', 'scheduled')->where('effective_date <', $profile['effective_date'])
                ->countAllResults();
            if ($earlier > 0) {
                $db->transRollback();
                return $this->error(409, 'Activate this employee’s earlier pending profile first.');
            }
            $db->table('scheduled_salary_profiles')->where('id', $profileId)->where('status', 'scheduled')
                ->update(['status' => 'applying']);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return $this->error(409, 'Another request has already handled this salary profile.');
            }

            $userId = (int) $profile['user_id'];
            $current = $db->table('users u')
                ->select('u.base_salary, u.employment_type, u.salary_grade, u.salary_step, u.salary_effective_date, u.salary_schedule_id, u.user_type, u.is_active, b.user_id AS balance_user_id, b.credit_limit, b.credit_rate')
                ->join('balances b', 'b.user_id = u.id', 'left')
                ->where('u.id', $userId)->get()->getRowArray();
            if ($current === null) {
                throw new \RuntimeException('Scheduled profile refers to a missing employee.');
            }
            if (!ibems_bool($current['is_active'] ?? false)
                || !in_array(strtolower((string) ($current['user_type'] ?? '')), ['faculty', 'staff'], true)) {
                $db->transRollback();
                return $this->error(409, 'The employee is no longer an active Faculty or Staff member.');
            }
            if (($current['salary_effective_date'] ?? null) !== null
                && (string) $current['salary_effective_date'] >= (string) $profile['effective_date']) {
                $db->transRollback();
                return $this->error(409, 'A salary profile effective on or after this date is already active.');
            }
            $now = ibems_now()->format('Y-m-d H:i:s');
            $db->table('users')->where('id', $userId)->update([
                'base_salary' => $profile['monthly_salary'],
                'employment_type' => $profile['employment_type'],
                'salary_grade' => $profile['salary_grade'],
                'salary_step' => $profile['salary_step'],
                'salary_effective_date' => $profile['effective_date'],
                'salary_schedule_id' => $profile['salary_schedule_id'],
            ]);
            $balance = [
                'credit_limit' => $profile['credit_limit'],
                'credit_rate' => $profile['credit_rate'],
                'updated_at' => $now,
            ];
            if ($current['balance_user_id'] !== null) {
                $db->table('balances')->where('user_id', $userId)->update($balance);
            } else {
                $db->table('balances')->insert($balance + ['user_id' => $userId, 'current_debt' => 0]);
            }
            $db->table('audit_logs')->insert([
                'actor_id' => $actorId,
                'action' => 'SALARY_PROFILE_ACTIVATED',
                'entity' => 'scheduled_salary_profiles',
                'entity_id' => $profileId,
                'payload_json' => json_encode([
                    'user_id' => $userId,
                    'effective_date' => $profile['effective_date'],
                    'revision' => $profile['revision'],
                    'scheduled_by' => $profile['created_by'],
                    'activated_by' => $actorId,
                    'previous_profile' => $current,
                    'new_monthly_salary' => (float) $profile['monthly_salary'],
                    'new_credit_limit' => (float) $profile['credit_limit'],
                    'reason' => $profile['reason'],
                ]),
                'created_at' => $now,
            ]);
            $db->table('scheduled_salary_profiles')->where('id', $profileId)->update([
                'status' => 'applied', 'applied_at' => $now,
            ]);
            $db->transComplete();
            if (!$db->transStatus()) {
                throw new \RuntimeException('Salary activation transaction did not commit.');
            }
        } catch (\Throwable $exception) {
            $db->transRollback();
            return $this->error(500, 'Salary activation failed and was rolled back.');
        }

        return ['code' => 200, 'payload' => [
            'status' => 'success',
            'scheduled_profile_id' => $profileId,
            'user_id' => (int) $profile['user_id'],
            'effective_date' => $profile['effective_date'],
            'monthly_salary' => (float) $profile['monthly_salary'],
            'credit_limit' => (float) $profile['credit_limit'],
        ]];
    }

    private function error(int $code, string $message): array
    {
        return ['code' => $code, 'payload' => ['status' => 'error', 'message' => $message]];
    }
}
