<?php

namespace App\Services;

use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

class DepartmentAuthorizationService
{
    public const MAX_ATTEMPTS = 5;
    public const ATTEMPT_WINDOW_MINUTES = 15;
    public const LOCKOUT_MINUTES = 15;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function userCanApprove(int $departmentId, int $userId, ?string $onDate = null): bool
    {
        if ($departmentId <= 0 || $userId <= 0) {
            return false;
        }

        return $this->db->table('departments d')
            ->select('d.id')
            ->join('users u', 'u.id = d.head_user_id', 'inner')
            ->where('d.id', $departmentId)
            ->where('d.is_active', true)
            ->where('d.head_user_id', $userId)
            ->where('u.is_active', true)
            ->countAllResults() > 0;
    }

    public function userHasAssignment(int $userId): bool
    {
        if ($userId <= 0 || !$this->db->tableExists('departments')) {
            return false;
        }

        return $this->db->table('departments')->where('head_user_id', $userId)->where('is_active', true)->countAllResults() > 0;
    }

    public function setPin(int $userId, string $pin): array
    {
        if (!$this->userHasAssignment($userId)) {
            return $this->error('Only an active department head can set a department approval PIN.', 403);
        }
        if (!preg_match('/^[0-9]{4,6}$/', $pin)) {
            return $this->error('The department approval PIN must contain 4 to 6 digits.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $row = $this->db->table('department_authorization_pins')->where('user_id', $userId)->get()->getRowArray();
            $payload = [
                'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
                'failed_attempts' => 0,
                'window_started_at' => null,
                'locked_until' => null,
                'last_failed_at' => null,
                'updated_at' => $now,
            ];
            if ($row) {
                $ok = $this->db->table('department_authorization_pins')->where('user_id', $userId)->update($payload);
            } else {
                $payload['user_id'] = $userId;
                $payload['created_at'] = $now;
                $ok = $this->db->table('department_authorization_pins')->insert($payload);
            }
            if (!$ok) {
                throw new RuntimeException('Unable to save the department approval PIN.');
            }

            $this->audit($row ? 'DEPARTMENT_PIN_CHANGED' : 'DEPARTMENT_PIN_SET', 0, $userId, 0, 0, []);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Unable to save the department approval PIN.');
            }
            $this->db->transCommit();
            return ['status' => 'success', 'code' => 200];
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Department PIN update failed: {message}', ['message' => $e->getMessage()]);
            return $this->error('Unable to save the department approval PIN.', 500);
        }
    }

    public function authorize(int $departmentId, int $approverId, string $pin, int $actorId, int $storeId, float $amount): array
    {
        if (!$this->userCanApprove($departmentId, $approverId)) {
            $this->audit('BLOCKED_DEPARTMENT_APPROVAL', $departmentId, $actorId, $storeId, $amount, [
                'approver_user_id' => $approverId,
                'reason' => 'not_authorized_for_department',
            ]);
            return $this->error('The selected approver is not the active head of this department.', 403);
        }

        $state = $this->db->table('department_authorization_pins')->where('user_id', $approverId)->get()->getRowArray();
        if (!$state || trim((string) ($state['pin_hash'] ?? '')) === '') {
            return $this->error('The selected approver has not set a department approval PIN.', 409);
        }

        $now = new DateTimeImmutable();
        $nowSql = $now->format('Y-m-d H:i:s');
        $lockedUntil = $this->dateTime($state['locked_until'] ?? null);
        if ($lockedUntil && $lockedUntil > $now) {
            $this->audit('BLOCKED_DEPARTMENT_PIN', $departmentId, $actorId, $storeId, $amount, [
                'approver_user_id' => $approverId,
                'locked_until' => $lockedUntil->format('Y-m-d H:i:s'),
            ]);
            return $this->locked($lockedUntil, $now);
        }

        if (password_verify($pin, (string) $state['pin_hash'])) {
            $this->db->table('department_authorization_pins')->where('user_id', $approverId)->update([
                'failed_attempts' => 0,
                'window_started_at' => null,
                'locked_until' => null,
                'last_success_at' => $nowSql,
                'updated_at' => $nowSql,
            ]);
            $this->audit('DEPARTMENT_PIN_AUTHORIZED', $departmentId, $actorId, $storeId, $amount, [
                'approver_user_id' => $approverId,
            ]);
            return ['status' => 'success', 'code' => 200, 'approver_user_id' => $approverId];
        }

        $windowStarted = $this->dateTime($state['window_started_at'] ?? null);
        $windowExpired = !$windowStarted || $windowStarted->modify('+' . self::ATTEMPT_WINDOW_MINUTES . ' minutes') <= $now;
        $attempts = $windowExpired ? 1 : ((int) ($state['failed_attempts'] ?? 0) + 1);
        $windowStarted = $windowExpired ? $now : $windowStarted;
        $lockedUntil = $attempts >= self::MAX_ATTEMPTS ? $now->modify('+' . self::LOCKOUT_MINUTES . ' minutes') : null;
        $this->db->table('department_authorization_pins')->where('user_id', $approverId)->update([
            'failed_attempts' => $attempts,
            'window_started_at' => $windowStarted->format('Y-m-d H:i:s'),
            'locked_until' => $lockedUntil?->format('Y-m-d H:i:s'),
            'last_failed_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $this->audit($lockedUntil ? 'DEPARTMENT_PIN_LOCKED' : 'FAILED_DEPARTMENT_PIN', $departmentId, $actorId, $storeId, $amount, [
            'approver_user_id' => $approverId,
            'failed_attempts' => $attempts,
            'attempts_remaining' => max(0, self::MAX_ATTEMPTS - $attempts),
            'locked_until' => $lockedUntil?->format('Y-m-d H:i:s'),
        ]);

        if ($lockedUntil) {
            return $this->locked($lockedUntil, $now);
        }
        return $this->error('Invalid department approval PIN.', 401, [
            'attempts_remaining' => self::MAX_ATTEMPTS - $attempts,
        ]);
    }

    private function audit(string $action, int $departmentId, int $actorId, int $storeId, float $amount, array $extra): void
    {
        $payload = array_merge(['department_id' => $departmentId, 'store_id' => $storeId, 'amount' => $amount], $extra);
        if (!(new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'entity' => $departmentId > 0 ? 'departments' : 'users',
            'entity_id' => $departmentId > 0 ? $departmentId : ($actorId > 0 ? $actorId : null),
            'payload_json' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Failed to write department authorization audit.');
        }
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function locked(DateTimeImmutable $lockedUntil, DateTimeImmutable $now): array
    {
        $seconds = max(1, $lockedUntil->getTimestamp() - $now->getTimestamp());
        return $this->error('Department approval PIN is temporarily locked. Try again in ' . (int) ceil($seconds / 60) . ' minute(s).', 423, [
            'locked_until' => $lockedUntil->format('Y-m-d H:i:s'),
            'locked_until_epoch' => $lockedUntil->getTimestamp(),
        ]);
    }

    private function error(string $message, int $code = 400, array $extra = []): array
    {
        return array_merge(['status' => 'error', 'code' => $code, 'message' => $message], $extra);
    }
}
