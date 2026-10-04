<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Migrations\AddDepartmentSettlementReceipts;
use App\Database\Migrations\AddOfficePortalRoles;
use App\Database\Migrations\AddPosRequestReceipts;
use App\Database\Migrations\AddRentalAndDailyFood;
use App\Database\Migrations\AddStoreRepaymentReceipts;
use App\Database\Migrations\AddWasteDispositionToInventoryMovements;
use App\Database\Migrations\CreateDailyStoreReports;
use App\Database\Migrations\CreateScheduledSalaryProfiles;
use App\Database\Migrations\ExpandFoodTemplates;
use App\Database\Migrations\RepairPaymentDestinationForeignKey;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/** Applies only the reviewed hosted workflow additions, never seed or reset migrations. */
final class HostedWorkflowSchemaService
{
    private const VERSION = '2026-10-04-hosted-workflows-v1';

    private const MIGRATIONS = [
        '2026-09-05-000001_AddRentalAndDailyFood.php' => AddRentalAndDailyFood::class,
        '2026-09-05-000002_ExpandFoodTemplates.php' => ExpandFoodTemplates::class,
        '2026-09-23-000001_AddOfficePortalRoles.php' => AddOfficePortalRoles::class,
        '2026-09-23-000002_CreateDailyStoreReports.php' => CreateDailyStoreReports::class,
        '2026-09-23-000003_RepairPaymentDestinationForeignKey.php' => RepairPaymentDestinationForeignKey::class,
        '2026-09-23-000004_AddWasteDispositionToInventoryMovements.php' => AddWasteDispositionToInventoryMovements::class,
        '2026-09-24-000002_CreateScheduledSalaryProfiles.php' => CreateScheduledSalaryProfiles::class,
        '2026-09-30-000001_AddPosRequestReceipts.php' => AddPosRequestReceipts::class,
        '2026-09-30-000002_AddStoreRepaymentReceipts.php' => AddStoreRepaymentReceipts::class,
        '2026-10-02-000001_AddDepartmentSettlementReceipts.php' => AddDepartmentSettlementReceipts::class,
    ];

    public function apply(BaseConnection $db): void
    {
        if ($db->DBDriver !== 'Postgre' || $db->schema !== 'public') {
            throw new RuntimeException('Hosted workflow additions require the public PostgreSQL application schema.');
        }
        foreach (['users', 'user_roles', 'balances', 'products', 'transactions', 'transaction_payments', 'ibems_schema_meta'] as $table) {
            if (!$db->tableExists($table)) {
                throw new RuntimeException('Verified hosted application baseline is missing: ' . $table);
            }
        }

        $db->transBegin();
        try {
            // Competing instance starts must observe the committed schema and marker.
            $db->query("SELECT pg_advisory_xact_lock(hashtext('ibems-hosted-workflows-v1'))");
            if ($db->table('ibems_schema_meta')->where('version', self::VERSION)->countAllResults() === 0) {
                foreach (self::MIGRATIONS as $file => $class) {
                    require_once APPPATH . 'Database/Migrations/' . $file;
                    (new $class(Database::forge($db)))->up();
                    $db->resetDataCache();
                }
                if (!$db->table('ibems_schema_meta')->insert([
                    'version' => self::VERSION,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                    'description' => 'Reviewed office roles, food/rental, daily reports, salary scheduling and durable financial receipt additions; existing financial rows retained.',
                ])) {
                    throw new RuntimeException('Hosted workflow schema marker could not be saved.');
                }
            }
            foreach (['food_template_details', 'food_daily_batches', 'rental_bookings', 'daily_store_reports', 'daily_store_report_events', 'scheduled_salary_profiles', 'pos_request_receipts', 'store_repayment_receipts', 'department_settlement_receipts'] as $table) {
                if (!$db->tableExists($table)) {
                    throw new RuntimeException('Hosted workflow schema is incomplete: ' . $table);
                }
            }
            if (!$db->transStatus()) {
                throw new RuntimeException('Hosted workflow additions failed; the transaction will be rolled back.');
            }
            $db->transCommit();
        } catch (\Throwable $error) {
            $db->transRollback();
            $db->resetTransStatus();
            $db->resetDataCache();
            throw $error;
        }
    }
}
