<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

class UserPurchaseCardService
{
    public const UNLOCK_MINUTES = 5;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function isAvailable(): bool
    {
        return $this->db->tableExists($this->db->getPrefix() . 'user_purchase_cards', false);
    }

    /** @return array<string, mixed> */
    public function status(int $userId): array
    {
        if ($userId <= 0 || !$this->isAvailable()) {
            return $this->lockedStatus();
        }

        $row = $this->db->table('user_purchase_cards')->where('user_id', $userId)->get()->getRowArray();
        if (!$row) {
            return $this->lockedStatus();
        }

        $until = $this->dateTime($row['unlocked_until'] ?? null);
        $active = !ibems_bool($row['is_locked'] ?? true) && $until !== null && $until > new DateTimeImmutable();

        return [
            'is_locked' => !$active,
            'unlocked_until' => $active ? $until->format('Y-m-d H:i:s') : null,
            'unlocked_until_epoch' => $active ? $until->getTimestamp() : null,
            'last_unlocked_at' => $row['last_unlocked_at'] ?? null,
            'last_locked_at' => $row['last_locked_at'] ?? null,
            'last_transaction_at' => $row['last_transaction_at'] ?? null,
            'unlock_minutes' => self::UNLOCK_MINUTES,
        ];
    }

    /** @return array<string, mixed> */
    public function unlock(int $userId): array
    {
        if ($userId <= 0) {
            return $this->error('Not authenticated.', 401);
        }
        if (!$this->isAvailable()) {
            return $this->error('Purchase card controls are unavailable until the latest database migration is applied.', 503);
        }

        $now = new DateTimeImmutable();
        $until = $now->modify('+' . self::UNLOCK_MINUTES . ' minutes');
        $this->db->transBegin();
        try {
            $existing = $this->db->table('user_purchase_cards')->where('user_id', $userId)->get()->getRowArray();
            $payload = [
                'is_locked' => false,
                'unlocked_until' => $until->format('Y-m-d H:i:s'),
                'last_unlocked_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $ok = $this->db->table('user_purchase_cards')->where('user_id', $userId)->update($payload);
            } else {
                $payload['user_id'] = $userId;
                $payload['created_at'] = $now->format('Y-m-d H:i:s');
                $ok = $this->db->table('user_purchase_cards')->insert($payload);
            }
            if (!$ok) {
                throw new RuntimeException('Unable to unlock the purchase card.');
            }
            $this->audit('USER_PURCHASE_CARD_UNLOCKED', $userId, $userId, ['unlocked_until' => $until->format('Y-m-d H:i:s')]);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Unable to unlock the purchase card.');
            }
            $this->db->transCommit();

            return ['status' => 'success', 'code' => 200, 'card' => $this->status($userId)];
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Purchase card unlock failed: {message}', ['message' => $e->getMessage()]);
            return $this->error('Unable to unlock the purchase card.', 500);
        }
    }

    /** @return array<string, mixed> */
    public function lock(int $userId): array
    {
        if ($userId <= 0) {
            return $this->error('Not authenticated.', 401);
        }
        if (!$this->isAvailable()) {
            return $this->error('Purchase card controls are unavailable until the latest database migration is applied.', 503);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $existing = $this->db->table('user_purchase_cards')->where('user_id', $userId)->get()->getRowArray();
            $payload = ['is_locked' => true, 'unlocked_until' => null, 'last_locked_at' => $now, 'updated_at' => $now];
            if ($existing) {
                $ok = $this->db->table('user_purchase_cards')->where('user_id', $userId)->update($payload);
            } else {
                $payload += ['user_id' => $userId, 'created_at' => $now];
                $ok = $this->db->table('user_purchase_cards')->insert($payload);
            }
            if (!$ok) {
                throw new RuntimeException('Unable to lock the purchase card.');
            }
            $this->audit('USER_PURCHASE_CARD_LOCKED', $userId, $userId, []);
            if (!$this->db->transStatus()) {
                throw new RuntimeException('Unable to lock the purchase card.');
            }
            $this->db->transCommit();

            return ['status' => 'success', 'code' => 200, 'card' => $this->status($userId)];
        } catch (Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Purchase card lock failed: {message}', ['message' => $e->getMessage()]);
            return $this->error('Unable to lock the purchase card.', 500);
        }
    }

    /**
     * Must be called inside the transaction that records the purchase.
     * A successful authorization consumes the unlock and returns the card to locked.
     *
     * @return array<string, mixed>
     */
    public function authorizeAndConsume(int $userId, int $actorId, int $storeId, float $amount): array
    {
        if (!$this->isAvailable()) {
            return $this->error('Purchase card controls are unavailable until the latest database migration is applied.', 503);
        }

        $driver = strtolower((string) ($this->db->DBDriver ?? ''));
        if (str_contains($driver, 'sqlite')) {
            $row = $this->db->table('user_purchase_cards')->where('user_id', $userId)->get()->getRowArray();
        } else {
            $row = $this->db->query('SELECT * FROM user_purchase_cards WHERE user_id = ? FOR UPDATE', [$userId])->getRowArray();
        }
        $now = new DateTimeImmutable();
        $until = $this->dateTime($row['unlocked_until'] ?? null);
        $active = $row && !ibems_bool($row['is_locked'] ?? true) && $until !== null && $until > $now;
        if (!$active) {
            $this->audit('BLOCKED_USER_PURCHASE_CARD', $userId, $actorId, [
                'store_id' => $storeId,
                'amount' => $amount,
                'reason' => $row ? ($until !== null && $until <= $now ? 'unlock_expired' : 'card_locked') : 'card_not_unlocked',
            ]);
            return $this->error('The employee purchase card is locked. Ask the employee to unlock it in their portal, then check again.', 423);
        }

        $nowSql = $now->format('Y-m-d H:i:s');
        if (!$this->db->table('user_purchase_cards')->where('user_id', $userId)->update([
            'is_locked' => true,
            'unlocked_until' => null,
            'last_locked_at' => $nowSql,
            'last_transaction_at' => $nowSql,
            'updated_at' => $nowSql,
        ])) {
            throw new RuntimeException('Unable to consume the purchase card authorization.');
        }
        $this->audit('USER_PURCHASE_CARD_AUTHORIZED', $userId, $actorId, ['store_id' => $storeId, 'amount' => $amount]);

        return ['status' => 'success', 'code' => 200];
    }

    /** @return array<string, mixed> */
    private function lockedStatus(): array
    {
        return [
            'is_locked' => true,
            'unlocked_until' => null,
            'unlocked_until_epoch' => null,
            'last_unlocked_at' => null,
            'last_locked_at' => null,
            'last_transaction_at' => null,
            'unlock_minutes' => self::UNLOCK_MINUTES,
        ];
    }

    private function audit(string $action, int $userId, int $actorId, array $payload): void
    {
        if (!$this->db->table('audit_logs')->insert([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'action' => $action,
            'entity' => 'user_purchase_cards',
            'entity_id' => $userId,
            'payload_json' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Unable to write purchase card audit log.');
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

    /** @return array<string, mixed> */
    private function error(string $message, int $code): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }
}
