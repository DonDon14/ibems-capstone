<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\DebtPinSecurityModel;
use Config\Database;
use DateTimeImmutable;
use Throwable;

class DebtPinAuthorizationService
{
    public const MAX_ATTEMPTS = 5;
    public const ATTEMPT_WINDOW_MINUTES = 15;
    public const LOCKOUT_MINUTES = 15;

    public function authorize(
        int $userId,
        string $pinHash,
        string $pin,
        int $actorId,
        int $storeId,
        float $amount
    ): array {
        $db = Database::connect();
        $securityModel = new DebtPinSecurityModel();
        $now = new DateTimeImmutable();
        $nowSql = $now->format('Y-m-d H:i:s');

        $db->transBegin();

        try {
            $state = $securityModel->find($userId);
            if (!$state) {
                $securityModel->insert([
                    'user_id' => $userId,
                    'failed_attempts' => 0,
                    'updated_at' => $nowSql,
                ]);
                $state = $securityModel->find($userId) ?? [];
            }

            $lockedUntil = $this->dateTime($state['locked_until'] ?? null);
            if ($lockedUntil && $lockedUntil > $now) {
                $this->audit('BLOCKED_DEBT_PIN', $userId, $actorId, $storeId, $amount, [
                    'locked_until' => $lockedUntil->format('Y-m-d H:i:s'),
                ]);
                $db->transCommit();

                return $this->locked($lockedUntil, $now);
            }

            if (password_verify($pin, $pinHash)) {
                $securityModel->update($userId, [
                    'failed_attempts' => 0,
                    'window_started_at' => null,
                    'locked_until' => null,
                    'last_success_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);
                $this->audit('DEBT_PIN_AUTHORIZED', $userId, $actorId, $storeId, $amount);
                $db->transCommit();

                return ['status' => 'success', 'code' => 200];
            }

            $windowStarted = $this->dateTime($state['window_started_at'] ?? null);
            $windowExpired = !$windowStarted
                || $windowStarted->modify('+' . self::ATTEMPT_WINDOW_MINUTES . ' minutes') <= $now;
            $attempts = $windowExpired ? 1 : ((int) ($state['failed_attempts'] ?? 0) + 1);
            $windowStarted = $windowExpired ? $now : $windowStarted;
            $lockedUntil = $attempts >= self::MAX_ATTEMPTS
                ? $now->modify('+' . self::LOCKOUT_MINUTES . ' minutes')
                : null;

            $securityModel->update($userId, [
                'failed_attempts' => $attempts,
                'window_started_at' => $windowStarted->format('Y-m-d H:i:s'),
                'locked_until' => $lockedUntil?->format('Y-m-d H:i:s'),
                'last_failed_at' => $nowSql,
                'updated_at' => $nowSql,
            ]);

            $this->audit(
                $lockedUntil ? 'DEBT_PIN_LOCKED' : 'FAILED_DEBT_PIN',
                $userId,
                $actorId,
                $storeId,
                $amount,
                [
                    'failed_attempts' => $attempts,
                    'attempts_remaining' => max(0, self::MAX_ATTEMPTS - $attempts),
                    'locked_until' => $lockedUntil?->format('Y-m-d H:i:s'),
                ]
            );
            $db->transCommit();

            if ($lockedUntil) {
                return $this->locked($lockedUntil, $now);
            }

            return [
                'status' => 'error',
                'code' => 401,
                'message' => 'Invalid debt PIN.',
                'attempts_remaining' => self::MAX_ATTEMPTS - $attempts,
            ];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Debt PIN authorization failed: {message}', ['message' => $e->getMessage()]);

            return [
                'status' => 'error',
                'code' => 500,
                'message' => 'Unable to verify the debt PIN. Please try again.',
            ];
        }
    }

    private function audit(
        string $action,
        int $userId,
        int $actorId,
        int $storeId,
        float $amount,
        array $extra = []
    ): void {
        $payload = array_merge([
            'store_id' => $storeId,
            'amount' => $amount,
            'customer_user_id' => $userId,
        ], $extra);

        if (!(new AuditLogModel())->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'entity' => 'users',
            'entity_id' => $userId,
            'payload_json' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new \RuntimeException('Failed to write debt PIN security audit.');
        }
    }

    private function locked(DateTimeImmutable $lockedUntil, DateTimeImmutable $now): array
    {
        $seconds = max(1, $lockedUntil->getTimestamp() - $now->getTimestamp());

        return [
            'status' => 'error',
            'code' => 423,
            'message' => 'Debt PIN is temporarily locked. Try again in ' . (int) ceil($seconds / 60) . ' minute(s).',
            'locked_until' => $lockedUntil->format('Y-m-d H:i:s'),
            'locked_until_epoch' => $lockedUntil->getTimestamp(),
        ];
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
}
