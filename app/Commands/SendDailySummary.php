<?php

namespace App\Commands;

use App\Models\TransactionModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SendDailySummary extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:send-daily-summary';
    protected $description = 'Sends end-of-day sales summary to accounting users.';

    public function run(array $params)
    {
        $date = date('Y-m-d');
        $summary = (new TransactionModel())
            ->select('payment_method, COUNT(*) as txn_count, SUM(amount) as total_amount')
            ->where('DATE(created_at)', $date)
            ->groupBy('payment_method')
            ->findAll();

        $lines = ["IBEMS Daily Summary ({$date})", ''];
        foreach ($summary as $row) {
            $lines[] = sprintf(
                '%s: %d transaction(s), PHP %.2f',
                $row['payment_method'],
                (int) $row['txn_count'],
                (float) $row['total_amount']
            );
        }

        $body = implode(PHP_EOL, $lines);

        $emails = (new UserModel())->where('role', 'ACCOUNTING_OFFICE')->where('is_active', 1)->findAll();
        if ($emails === []) {
            CLI::write('No accounting users found.', 'yellow');
            return;
        }

        foreach ($emails as $recipient) {
            $email = service('email');
            $email->setTo($recipient['email']);
            $email->setSubject('IBEMS Daily Sales Summary');
            $email->setMessage($body);
            $email->send();
        }

        CLI::write('Daily summary attempted for accounting users.', 'green');
    }
}
