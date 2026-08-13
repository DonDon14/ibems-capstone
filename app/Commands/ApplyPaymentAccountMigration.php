<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class ApplyPaymentAccountMigration extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:payment-accounts-migrate';
    protected $description = 'Apply the fixed PostgreSQL payment destination account migration.';

    public function run(array $params)
    {
        $db = Database::connect();
        if (stripos((string) ($db->DBDriver ?? ''), 'Postgre') === false) {
            CLI::error('This command is only available for PostgreSQL.');
            return EXIT_ERROR;
        }
        try {
            foreach (['004_payment_destination_accounts.sql', '005_remove_legacy_payment_method_defaults.sql', '006_payment_method_images.sql'] as $file) {
                $sql = file_get_contents(ROOTPATH . 'database/postgresql/' . $file);
                if ($sql === false) throw new \RuntimeException('Migration SQL file not found: ' . $file);
                foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [])) as $statement) $db->query($statement);
            }
            CLI::write('Dynamic payment method migrations applied.', 'green');
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            try { $db->query('rollback'); } catch (\Throwable) {}
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
    }
}
