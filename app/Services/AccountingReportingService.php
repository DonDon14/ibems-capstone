<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class AccountingReportingService
{
    /** @return array<string, mixed> */
    public function dashboardData(BaseConnection $db, string $requestedPeriod = 'day'): array
    {
        $period = DashboardPeriod::resolve($requestedPeriod);
        $accountsRow = $db->table('balances b')
            ->select('COUNT(*) AS total_accounts, SUM(b.current_debt) AS total_debt, SUM(CASE WHEN b.current_debt > 0 THEN 1 ELSE 0 END) AS with_debt, SUM(CASE WHEN b.current_debt > b.credit_limit AND b.current_debt > 0 THEN 1 ELSE 0 END) AS over_limit_count')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->get()
            ->getRowArray() ?? [];

        $periodDeductions = $this->deductionSummaryForStorageRange($db, $period['start'], $period['end']);

        $lastPeriod = $db->table('deduction_periods')
            ->select('period_code, label, status, updated_at')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $topDebtAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $overLimitAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt > b.credit_limit', null, false)
            ->where('b.current_debt >', 0)
            ->orderBy('(b.current_debt - b.credit_limit)', 'DESC', false)
            ->limit(5)
            ->get()
            ->getResultArray();

        $staleCutoff = date('Y-m-d H:i:s', strtotime('-30 day'));
        $staleDebtAccounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.updated_at, MAX(dce.created_at) AS last_cashbook_at')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->join('debt_cashbook_entries dce', 'dce.user_id = u.id', 'left')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->groupBy('u.id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.updated_at')
            ->having('(MAX(dce.created_at) IS NULL OR MAX(dce.created_at) < ' . $db->escape($staleCutoff) . ')', null, false)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $failedImports = $db->table('salary_import_batches sib')
            ->select('sib.id, sib.filename, sib.imported_at, sib.total_rows, sib.valid_rows, sib.invalid_rows, u.name AS imported_by_name')
            ->join('users u', 'u.id = sib.imported_by', 'left')
            ->where('sib.invalid_rows >', 0)
            ->orderBy('sib.id', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        $trendByDate = [];
        $trendSeed = DashboardPeriod::trendSeed($period);
        foreach ($trendSeed as $seed) {
            $trendByDate[$seed['key']] = ['date' => $seed['date'], 'amount' => 0.0, 'count' => 0];
        }

        $trendRows = $db->table('debt_cashbook_entries')
            ->select('created_at, amount')
            ->where('direction', 'credit')
            ->whereIn('entry_type', ['confirmed_salary_deduction', 'salary_deduction', 'manual_deduction', 'full_deduction'])
            ->where('created_at >=', $period['start'])
            ->where('created_at <=', $period['end'])
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($trendRows as $row) {
            $createdAt = trim((string) ($row['created_at'] ?? ''));
            $date = $createdAt === '' ? '' : (new \DateTimeImmutable($createdAt, $storageZone))->setTimezone($businessZone)->format('Y-m-d');
            $bucketKey = DashboardPeriod::bucketKey($date, $period);
            if (!isset($trendByDate[$bucketKey])) {
                continue;
            }

            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $trendByDate[$bucketKey]['amount'] += $amount;
            $trendByDate[$bucketKey]['count'] += 1;
        }

        $trend = [];
        foreach ($trendSeed as $seed) {
            $trend[] = $trendByDate[$seed['key']];
        }

        $recentActivities = $db->table('audit_logs al')
            ->select('al.id, al.action, al.created_at, al.payload_json, actor.name AS actor_name, target.name AS target_name')
            ->join('users actor', 'actor.id = al.actor_id', 'left')
            ->join($db->prefixTable('users') . ' target', "target.id = al.entity_id AND al.entity = 'balances'", 'left', false)
            ->whereIn('al.action', [
                'ACCOUNTING_IMPORT_HR_CSV',
                'ACCOUNTING_DEDUCT_DEBT',
                'ACCOUNTING_DEDUCT_FULL_DEBT',
                'ACCOUNTING_UPDATE_CREDIT_LIMIT',
                'ACCOUNTING_RUN_SETTLEMENT',
                'ACCOUNTING_SETTLEMENT_DEDUCT',
                'ACCOUNTING_PREPARE_DEDUCTION_BATCH',
                'ACCOUNTING_SUBMIT_DEDUCTION_BATCH',
                'ACCOUNTING_CONFIRM_DEDUCTION_RESULT',
                'ACCOUNTING_RECONCILE_DEDUCTION_BATCH',
                'ACCOUNTING_FINALIZE_DEDUCTION_BATCH',
                'OPEN_DEBT_INVESTIGATION',
                'RECOMMEND_DEBT_INVESTIGATION',
                'APPROVE_AND_POST_DEBT_REVERSAL',
            ])
            ->where('al.created_at >=', $period['start'])
            ->where('al.created_at <=', $period['end'])
            ->orderBy('al.id', 'DESC')
            ->limit(10)
            ->get()
            ->getResultArray();

        $activityFeed = array_map(static function (array $row): array {
            $action = (string) ($row['action'] ?? '');
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $labels = [
                'ACCOUNTING_IMPORT_HR_CSV' => 'Imported HR CSV',
                'ACCOUNTING_DEDUCT_DEBT' => 'Manual debt deduction',
                'ACCOUNTING_DEDUCT_FULL_DEBT' => 'Full debt deduction',
                'ACCOUNTING_UPDATE_CREDIT_LIMIT' => 'Updated credit limit',
                'ACCOUNTING_RUN_SETTLEMENT' => 'Ran monthly settlement',
                'ACCOUNTING_SETTLEMENT_DEDUCT' => 'Settlement deduction entry',
                'ACCOUNTING_PREPARE_DEDUCTION_BATCH' => 'Prepared deduction batch',
                'ACCOUNTING_SUBMIT_DEDUCTION_BATCH' => 'Submitted deduction batch',
                'ACCOUNTING_CONFIRM_DEDUCTION_RESULT' => 'Confirmed payroll result',
                'ACCOUNTING_RECONCILE_DEDUCTION_BATCH' => 'Reconciled deduction batch',
                'ACCOUNTING_FINALIZE_DEDUCTION_BATCH' => 'Finalized deduction period',
                'OPEN_DEBT_INVESTIGATION' => 'Opened debt investigation',
                'RECOMMEND_DEBT_INVESTIGATION' => 'Recommended debt correction',
                'APPROVE_AND_POST_DEBT_REVERSAL' => 'Approved debt correction',
            ];

            return [
                'id' => (int) ($row['id'] ?? 0),
                'label' => $labels[$action] ?? $action,
                'actor_name' => $row['actor_name'] ?: 'Unknown',
                'target_name' => $row['target_name'] ?: null,
                'amount' => (float) ($payload['deducted_amount'] ?? $payload['confirmed_amount'] ?? $payload['amount'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $recentActivities);

        return [
            'status' => 'success',
            'period' => DashboardPeriod::publicMeta($period),
            'summary' => [
                'total_accounts' => (int) ($accountsRow['total_accounts'] ?? 0),
                'with_debt' => (int) ($accountsRow['with_debt'] ?? 0),
                'total_debt' => (float) ($accountsRow['total_debt'] ?? 0),
                'today_deduction_count' => $periodDeductions['count'],
                'today_deduction_amount' => $periodDeductions['amount'],
                'period_deduction_count' => $periodDeductions['count'],
                'period_deduction_amount' => $periodDeductions['amount'],
                'over_limit_count' => (int) ($accountsRow['over_limit_count'] ?? 0),
                'last_deduction_period' => $lastPeriod['period_code'] ?? null,
                'last_deduction_label' => $lastPeriod['label'] ?? null,
                'last_deduction_status' => $lastPeriod['status'] ?? null,
                'last_deduction_at' => $lastPeriod['updated_at'] ?? null,
            ],
            'top_debt_accounts' => array_map(static fn(array $row): array => [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'employee_id' => $row['employee_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'profile_image_url' => $row['profile_image_url'] ?? null,
                'current_debt' => (float) ($row['current_debt'] ?? 0),
                'credit_limit' => (float) ($row['credit_limit'] ?? 0),
            ], $topDebtAccounts),
            'alerts' => [
                'over_limit' => array_map(static fn(array $row): array => [
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'current_debt' => (float) ($row['current_debt'] ?? 0),
                    'credit_limit' => (float) ($row['credit_limit'] ?? 0),
                    'over_amount' => max(0, (float) ($row['current_debt'] ?? 0) - (float) ($row['credit_limit'] ?? 0)),
                ], $overLimitAccounts),
                'stale_debts' => array_map(static fn(array $row): array => [
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'current_debt' => (float) ($row['current_debt'] ?? 0),
                    'last_cashbook_at' => $row['last_cashbook_at'] ?? null,
                ], $staleDebtAccounts),
                'failed_imports' => array_map(static fn(array $row): array => [
                    'id' => (int) ($row['id'] ?? 0),
                    'filename' => $row['filename'],
                    'imported_at' => $row['imported_at'],
                    'imported_by_name' => $row['imported_by_name'] ?: 'Unknown',
                    'total_rows' => (int) ($row['total_rows'] ?? 0),
                    'valid_rows' => (int) ($row['valid_rows'] ?? 0),
                    'invalid_rows' => (int) ($row['invalid_rows'] ?? 0),
                ], $failedImports),
            ],
            'trend' => $trend,
            'activity' => $activityFeed,
        ];
    }

    /** @return array{status:string,date:string,deduction_count:int,deducted_amount:float} */
    public function dailySummary(BaseConnection $db): array
    {
        [$start, $end, $businessDate] = $this->businessDayStorageBounds();
        $summary = $this->deductionSummaryForStorageRange($db, $start, $end);

        return [
            'status' => 'success',
            'date' => $businessDate,
            'deduction_count' => $summary['count'],
            'deducted_amount' => $summary['amount'],
        ];
    }

    /** @return array{count:int,amount:float} */
    private function deductionSummaryForStorageRange(BaseConnection $db, string $start, string $end): array
    {
        // Confirmed batch items are authoritative for the new payroll workflow.
        // Exclude their mirrored cashbook rows so the same result is not counted twice.
        $legacy = $db->table('debt_cashbook_entries')
            ->select('COUNT(*) AS entry_count, COALESCE(SUM(amount), 0) AS total_amount')
            ->where('direction', 'credit')
            ->whereIn('entry_type', ['salary_deduction', 'manual_deduction', 'full_deduction'])
            ->where('created_at >=', $start)
            ->where('created_at <=', $end)
            ->get()
            ->getRowArray() ?? [];

        $batch = $db->table('deduction_batch_items')
            ->select('COUNT(*) AS entry_count, COALESCE(SUM(confirmed_amount), 0) AS total_amount')
            ->where('confirmed_amount >', 0)
            ->where('confirmed_at >=', $start)
            ->where('confirmed_at <=', $end)
            ->get()
            ->getRowArray() ?? [];

        return [
            'count' => (int) ($legacy['entry_count'] ?? 0) + (int) ($batch['entry_count'] ?? 0),
            'amount' => (float) ($legacy['total_amount'] ?? 0) + (float) ($batch['total_amount'] ?? 0),
        ];
    }

    /** @return array{0:string,1:string,2:string} */
    private function businessDayStorageBounds(): array
    {
        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        $businessNow = new \DateTimeImmutable('now', $businessZone);
        $start = $businessNow->setTime(0, 0)->setTimezone($storageZone);
        $end = $businessNow->setTime(23, 59, 59)->setTimezone($storageZone);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $businessNow->format('Y-m-d')];
    }
}
