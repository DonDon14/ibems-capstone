<?php

namespace App\Commands;

use App\Services\NotificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class DispatchNotifications extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'notifications:dispatch';
    protected $description = 'Send pending IBEMS email notifications and retry prior delivery failures.';
    protected $usage = 'notifications:dispatch [limit]';

    public function run(array $params)
    {
        $limit = max(1, min(100, (int) ($params[0] ?? 25)));
        $result = (new NotificationService())->dispatchPendingEmails($limit);
        if (!$result['configured']) {
            CLI::write('Gmail/SMTP is not configured. In-app notifications remain available and email items stay queued.', 'yellow');
            return;
        }
        CLI::write(sprintf('Sent: %d | Failed: %d | Skipped: %d', $result['sent'], $result['failed'], $result['skipped']), $result['failed'] ? 'yellow' : 'green');
    }
}
