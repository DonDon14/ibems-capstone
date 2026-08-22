<?php

namespace App\Commands;

use App\Models\NotificationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TestInAppNotification extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'notifications:test-in-app';
    protected $description = 'Create one unread in-app test notification for an active IBEMS user.';
    protected $usage = 'notifications:test-in-app <email>';
    protected $arguments = ['email' => 'Email address of the active IBEMS user who should receive the sample.'];

    public function run(array $params)
    {
        $email = trim((string) ($params[0] ?? ''));
        if ($email === '') {
            CLI::error('Provide the IBEMS user email that should receive the test notification.');
            return;
        }

        $db = db_connect();
        $user = $db->table('users')->select('id, is_active')->where('email', $email)->get()->getRowArray();
        if (!$user || !(bool) ($user['is_active'] ?? false)) {
            CLI::error('No active IBEMS user matches that email address.');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $id = (new NotificationModel())->insert([
            'user_id' => (int) $user['id'],
            'txn_id' => null,
            'audit_log_id' => null,
            'notification_type' => 'test',
            'title' => 'Test notification',
            'message' => 'Your IBEMS notification center is working correctly.',
            'link_url' => site_url('dashboard'),
            'icon' => 'bi bi-bell',
            'severity' => 'success',
            'read_at' => null,
            'channel' => 'in_app',
            'status' => 'unread',
            'email_status' => 'skipped',
            'email_attempts' => 0,
            'email_next_attempt_at' => null,
            'dedupe_key' => 'manual-test:' . (int) $user['id'] . ':' . date('YmdHis') . ':' . bin2hex(random_bytes(3)),
            'created_at' => $now,
        ], true);

        if (!$id) {
            CLI::error('The sample notification could not be created.');
            return;
        }

        CLI::write('Unread in-app test notification created.', 'green');
    }
}
