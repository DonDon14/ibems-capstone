<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'user_id', 'txn_id', 'audit_log_id', 'notification_type', 'title', 'message',
        'link_url', 'icon', 'severity', 'read_at', 'channel', 'status', 'sent_at',
        'error_msg', 'email_status', 'email_attempts', 'email_next_attempt_at',
        'email_last_attempt_at', 'dedupe_key', 'created_at',
    ];

    public function unreadCount(int $userId): int
    {
        return $this->where('user_id', $userId)->where('read_at', null)->countAllResults();
    }
}
