<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Email;

class TestNotificationEmail extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'notifications:test-email';
    protected $description = 'Send a safe SMTP configuration test to the configured IBEMS sender address.';
    protected $usage = 'notifications:test-email';

    public function run(array $params)
    {
        $config = config(Email::class);
        if (strtolower(trim($config->protocol)) !== 'smtp'
            || trim($config->SMTPHost) === ''
            || trim($config->SMTPUser) === ''
            || trim($config->SMTPPass) === ''
            || trim($config->fromEmail) === '') {
            CLI::error('SMTP is not completely configured.');
            return;
        }

        try {
            $runtimeConfig = clone $config;
            $runtimeConfig->SMTPPass = preg_replace('/\s+/', '', $runtimeConfig->SMTPPass) ?? $runtimeConfig->SMTPPass;
            $email = service('email', $runtimeConfig, false);
            $email->clear(true);
            $email->setFrom($config->fromEmail, $config->fromName ?: 'IBEMS Notifications');
            $email->setTo($config->fromEmail);
            $email->setSubject('[IBEMS] Gmail notification test');
            $email->setMessage(
                '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#0f172a">'
                . '<div style="background:#0b3b6e;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0"><strong>IBEMS</strong></div>'
                . '<div style="border:1px solid #dbe4ee;border-top:0;padding:22px;border-radius:0 0 12px 12px">'
                . '<h2 style="margin-top:0">Gmail notifications are connected</h2>'
                . '<p>IBEMS successfully authenticated with the configured SMTP account.</p>'
                . '<p style="color:#64748b;font-size:12px;margin-bottom:0">This is a one-time configuration test.</p>'
                . '</div></div>'
            );
            $email->setAltMessage('IBEMS Gmail notifications are connected. This is a one-time configuration test.');

            if (!$email->send(false)) {
                $debug = strtolower(strip_tags((string) $email->printDebugger([])));
                $reason = match (true) {
                    str_contains($debug, 'password command failed'),
                    str_contains($debug, 'authentication failed'),
                    str_contains($debug, 'username command failed'),
                    str_contains($debug, 'failed to authenticate password'),
                    str_contains($debug, 'failed to authenticate username'),
                    str_contains($debug, 'failed to authenticate user credentials') => 'Gmail rejected the account or app password.',
                    str_contains($debug, 'unable to connect'),
                    str_contains($debug, 'no socket') => 'The SMTP connection could not be established.',
                    str_contains($debug, 'starttls'),
                    str_contains($debug, 'crypto') => 'TLS negotiation with Gmail failed.',
                    str_contains($debug, 'from command failed'),
                    str_contains($debug, 'recipient command failed') => 'Gmail rejected the sender or recipient address.',
                    str_contains($debug, 'no "from" header') => 'The test message did not receive a From header.',
                    str_contains($debug, 'must include recipients'),
                    str_contains($debug, 'invalid email address') => 'The configured sender or recipient address is invalid.',
                    str_contains($debug, 'unable to send email using smtp') => 'The SMTP transport failed before Gmail returned a response.',
                    default => 'Gmail did not accept the test message.',
                };
                preg_match_all('/\b[245]\d{2}\b/', $debug, $smtpCodes);
                $codes = array_values(array_unique($smtpCodes[0] ?? []));
                $codeSuffix = $codes !== [] ? ' SMTP response codes: ' . implode(', ', $codes) . '.' : '';
                CLI::error($reason . $codeSuffix . ' No credential values were logged.');
                return;
            }

            CLI::write('Gmail accepted the IBEMS test message.', 'green');
        } catch (\Throwable) {
            CLI::error('The Gmail SMTP test failed. Review the application log for the non-secret error details.');
        }
    }
}
