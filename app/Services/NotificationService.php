<?php

namespace App\Services;

use App\Models\NotificationModel;
use Config\Email;

class NotificationService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    public function publishFromAudit(int $auditId, array $audit): int
    {
        if ($auditId <= 0 || !$this->notificationSchemaReady()) {
            return 0;
        }

        $action = strtoupper(trim((string) ($audit['action'] ?? '')));
        if ($action === '' || in_array($action, ['LOGIN', 'LOGOUT', 'SELECT_ROLE'], true)) {
            return 0;
        }

        $payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $actorId = (int) ($audit['actor_id'] ?? 0);
        $entity = trim((string) ($audit['entity'] ?? ''));
        $entityId = (int) ($audit['entity_id'] ?? 0);
        $storeId = $this->resolveStoreId($entity, $entityId, $payload);
        $recipients = $this->resolveRecipients($actorId, $entity, $entityId, $payload, $storeId, $action);
        if ($recipients === []) {
            return 0;
        }

        $event = $this->formatEvent($action, $payload, $storeId);
        $createdAt = (string) ($audit['created_at'] ?? date('Y-m-d H:i:s'));
        $transactionId = $entity === 'transactions' ? $entityId : (int) ($payload['transaction_id'] ?? 0);
        $model = new NotificationModel();
        $inserted = 0;

        foreach ($recipients as $recipient) {
            $userId = (int) $recipient['id'];
            $link = $this->recipientLink((array) $recipient['roles'], $event['category'], $transactionId);
            $ok = $model->insert([
                'user_id' => $userId,
                'txn_id' => $transactionId > 0 ? $transactionId : null,
                'audit_log_id' => $auditId,
                'notification_type' => $event['category'],
                'title' => $event['title'],
                'message' => $event['message'],
                'link_url' => $link,
                'icon' => $event['icon'],
                'severity' => $event['severity'],
                'read_at' => null,
                'channel' => 'in_app,email',
                'status' => 'unread',
                'email_status' => 'pending',
                'email_attempts' => 0,
                'email_next_attempt_at' => $createdAt,
                'dedupe_key' => 'audit:' . $auditId . ':user:' . $userId,
                'created_at' => $createdAt,
            ], false);
            if ($ok !== false) {
                $inserted++;
            }
        }

        return $inserted;
    }

    public function feed(int $userId, int $limit = 12): array
    {
        if ($userId <= 0 || !$this->notificationSchemaReady()) {
            return ['unread_count' => 0, 'notifications' => []];
        }

        $model = new NotificationModel();
        $rows = $model->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->findAll(max(1, min(30, $limit)));

        return ['unread_count' => $model->unreadCount($userId), 'notifications' => $rows];
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        if ($notificationId <= 0 || $userId <= 0 || !$this->notificationSchemaReady()) {
            return false;
        }
        return $this->db->table('notifications')
            ->where('id', $notificationId)->where('user_id', $userId)
            ->update(['read_at' => date('Y-m-d H:i:s'), 'status' => 'read']);
    }

    public function markAllRead(int $userId): int
    {
        if ($userId <= 0 || !$this->notificationSchemaReady()) {
            return 0;
        }
        $this->db->table('notifications')->where('user_id', $userId)->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s'), 'status' => 'read']);
        return $this->db->affectedRows();
    }

    public function dispatchPendingEmails(int $limit = 10): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'configured' => $this->emailConfigured()];
        if (!$result['configured'] || !$this->notificationSchemaReady()) {
            return $result;
        }

        $now = date('Y-m-d H:i:s');
        $rows = $this->db->table('notifications n')
            ->select('n.*, u.email, u.name')
            ->join('users u', 'u.id = n.user_id', 'inner')
            ->whereIn('n.email_status', ['pending', 'failed'])
            ->groupStart()->where('n.email_next_attempt_at <=', $now)->orWhere('n.email_next_attempt_at', null)->groupEnd()
            ->where('n.email_attempts <', 5)->where('u.is_active', true)
            ->orderBy('n.created_at', 'ASC')->limit(max(1, min(50, $limit)))->get()->getResultArray();

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $emailAddress = trim((string) ($row['email'] ?? ''));
            if (!$this->deliverableAddress($emailAddress)) {
                $this->db->table('notifications')->where('id', $id)->update([
                    'email_status' => 'skipped', 'error_msg' => 'No deliverable email address.',
                    'email_last_attempt_at' => $now,
                ]);
                $result['skipped']++;
                continue;
            }

            $attempts = (int) ($row['email_attempts'] ?? 0) + 1;
            $this->db->table('notifications')->where('id', $id)
                ->whereIn('email_status', ['pending', 'failed'])
                ->update(['email_status' => 'sending', 'email_attempts' => $attempts, 'email_last_attempt_at' => $now]);
            if ($this->db->affectedRows() !== 1) {
                continue;
            }

            try {
                $emailConfig = clone config(Email::class);
                // Google displays app passwords in four-character groups. Normalize
                // only presentation whitespace and never log the credential.
                $emailConfig->SMTPPass = preg_replace('/\s+/', '', $emailConfig->SMTPPass) ?? $emailConfig->SMTPPass;
                $email = service('email', $emailConfig, false);
                $email->clear(true);
                $email->setFrom($emailConfig->fromEmail, $emailConfig->fromName ?: 'IBEMS Notifications');
                $email->setTo($emailAddress);
                $email->setSubject('[IBEMS] ' . (string) $row['title']);
                $email->setMessage($this->emailHtml($row));
                $email->setAltMessage((string) $row['title'] . "\n\n" . (string) $row['message']);
                if (!$email->send(false)) {
                    throw new \RuntimeException('The mail server did not accept the message.');
                }
                $this->db->table('notifications')->where('id', $id)->update([
                    'email_status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'),
                    'email_next_attempt_at' => null, 'error_msg' => null,
                ]);
                $result['sent']++;
            } catch (\Throwable $exception) {
                $delayMinutes = [1, 5, 30, 120, 720][min(4, $attempts - 1)];
                $this->db->table('notifications')->where('id', $id)->update([
                    'email_status' => 'failed',
                    'email_next_attempt_at' => date('Y-m-d H:i:s', time() + ($delayMinutes * 60)),
                    'error_msg' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
                log_message('error', 'Email notification {id} failed on attempt {attempt}.', ['id' => $id, 'attempt' => $attempts]);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function emailConfigured(): bool
    {
        $config = config(Email::class);
        return strtolower(trim($config->protocol)) === 'smtp'
            && trim($config->SMTPHost) !== '' && trim($config->SMTPUser) !== ''
            && trim($config->SMTPPass) !== '' && trim($config->fromEmail) !== '';
    }

    private function notificationSchemaReady(): bool
    {
        return $this->db->tableExists('notifications')
            && in_array('audit_log_id', $this->db->getFieldNames('notifications'), true);
    }

    private function resolveRecipients(int $actorId, string $entity, int $entityId, array $payload, int $storeId, string $action): array
    {
        $rows = $this->db->table('users u')->select('u.id, u.role, ur.role AS assigned_role')
            ->join('user_roles ur', 'ur.user_id = u.id', 'left')->where('u.is_active', true)->get()->getResultArray();
        $users = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $users[$id] ??= ['id' => $id, 'roles' => []];
            foreach ([$row['role'] ?? '', $row['assigned_role'] ?? ''] as $role) {
                $role = strtoupper(trim((string) $role));
                if ($role !== '') $users[$id]['roles'][] = $role;
            }
        }
        foreach ($users as &$user) $user['roles'] = array_values(array_unique($user['roles']));
        unset($user);

        $wanted = [];
        foreach ($users as $id => $user) {
            if (in_array('ADMIN', $user['roles'], true)) $wanted[$id] = true;
            if ($this->isFinancialEvent($entity, $action) && in_array('ACCOUNTING_OFFICE', $user['roles'], true)) $wanted[$id] = true;
        }

        $affectedUserId = (int) ($payload['customer_user_id'] ?? $payload['user_id'] ?? $payload['target_user_id'] ?? 0);
        if ($affectedUserId <= 0 && in_array($entity, ['users', 'balances', 'user_balances', 'debt_cashbook'], true)) $affectedUserId = $entityId;
        if ($affectedUserId > 0) $wanted[$affectedUserId] = true;

        if ($storeId > 0) {
            $store = $this->db->table('stores')->select('officer_id')->where('id', $storeId)->get()->getRowArray();
            if ((int) ($store['officer_id'] ?? 0) > 0) $wanted[(int) $store['officer_id']] = true;
            if ($this->db->tableExists('store_supervisors')) {
                foreach ($this->db->table('store_supervisors')->select('user_id')->where('store_id', $storeId)->get()->getResultArray() as $row) {
                    $wanted[(int) $row['user_id']] = true;
                }
            }
        }

        unset($wanted[$actorId]);
        return array_values(array_filter($users, static fn(array $user): bool => isset($wanted[(int) $user['id']])));
    }

    private function resolveStoreId(string $entity, int $entityId, array $payload): int
    {
        $storeId = (int) ($payload['store_id'] ?? 0);
        if ($storeId > 0) return $storeId;
        $lookups = [
            'transactions' => ['transactions', 'store_id'], 'store_day_sessions' => ['store_day_sessions', 'store_id'],
            'store_cash_movements' => ['store_cash_movements', 'store_id'], 'products' => ['products', 'store_id'],
            'inventory_movements' => ['inventory_movements', 'store_id'],
        ];
        if ($entityId > 0 && isset($lookups[$entity]) && $this->db->tableExists($lookups[$entity][0])) {
            $row = $this->db->table($lookups[$entity][0])->select($lookups[$entity][1])->where('id', $entityId)->get()->getRowArray();
            return (int) ($row[$lookups[$entity][1]] ?? 0);
        }
        return 0;
    }

    private function isFinancialEvent(string $entity, string $action): bool
    {
        return str_contains($entity, 'debt') || str_contains($entity, 'deduction') || $entity === 'transactions'
            || str_contains($action, 'FINANCIAL') || str_contains($action, 'PAYMENT') || str_contains($action, 'REPAYMENT');
    }

    private function formatEvent(string $action, array $payload, int $storeId): array
    {
        $amount = (float) ($payload['amount'] ?? $payload['total_amount'] ?? 0);
        $storeName = '';
        if ($storeId > 0) {
            $storeRow = $this->db->table('stores')->select('store_name')->where('id', $storeId)->get()->getRowArray();
            $storeName = (string) ($storeRow['store_name'] ?? '');
        }
        if ($action === 'CREATE_TRANSACTION') {
            return ['category' => 'transaction', 'title' => 'Transaction completed',
                'message' => trim(($amount > 0 ? 'PHP ' . number_format($amount, 2) . ' transaction' : 'A transaction') . ($storeName !== '' ? ' at ' . $storeName : '') . ' was recorded.'),
                'icon' => 'bi bi-receipt', 'severity' => 'success'];
        }
        $title = ucwords(strtolower(str_replace('_', ' ', $action)));
        return ['category' => $this->isFinancialEvent('', $action) ? 'financial' : 'activity', 'title' => $title,
            'message' => trim($title . ($storeName !== '' ? ' was recorded for ' . $storeName : ' was recorded in IBEMS') . '.'),
            'icon' => str_contains($action, 'INVENTORY') || str_contains($action, 'PRODUCT') ? 'bi bi-box-seam' : 'bi bi-activity',
            'severity' => str_contains($action, 'FAIL') || str_contains($action, 'REJECT') ? 'danger' : 'info'];
    }

    private function recipientLink(array $roles, string $category, int $transactionId): string
    {
        if (in_array('ADMIN', $roles, true)) return site_url('admin/audit');
        if (in_array('ACCOUNTING_OFFICE', $roles, true)) return site_url('accounting/debts');
        if (in_array('STORE_SUPERVISOR', $roles, true)) return site_url('store-admin/dashboard');
        if (in_array('STORE_SYSTEM', $roles, true)) return site_url($category === 'transaction' ? 'store/history' : 'store/dashboard');
        if ($transactionId > 0) return site_url('user/transactions/' . $transactionId);
        return site_url('user/dashboard');
    }

    private function deliverableAddress(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && !preg_match('/\.(local|test)$/i', substr(strrchr($email, '@') ?: '', 1));
    }

    private function emailHtml(array $row): string
    {
        $title = esc((string) $row['title']);
        $message = esc((string) $row['message']);
        $link = esc((string) ($row['link_url'] ?? ''));
        return '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#0f172a">'
            . '<div style="background:#0b3b6e;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0"><strong>IBEMS</strong></div>'
            . '<div style="border:1px solid #dbe4ee;border-top:0;padding:22px;border-radius:0 0 12px 12px"><h2 style="margin-top:0">' . $title . '</h2>'
            . '<p style="line-height:1.6">' . $message . '</p>'
            . ($link !== '' ? '<p><a href="' . $link . '" style="display:inline-block;background:#0b3b6e;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px">Open IBEMS</a></p>' : '')
            . '<p style="color:#64748b;font-size:12px;margin-bottom:0">This is an automated IBEMS activity notification.</p></div></div>';
    }
}
