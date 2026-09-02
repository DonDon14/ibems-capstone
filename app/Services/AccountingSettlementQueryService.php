<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class AccountingSettlementQueryService
{
    /** @return array<string, mixed> */
    public function preview(BaseConnection $db, string $runMonth): array
    {
        $rows = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, u.base_salary, b.current_debt')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->get()
            ->getResultArray();

        $accounts = [];
        $totalDebtBefore = 0.0;
        $totalDeductible = 0.0;
        $processableCount = 0;
        $skippedCount = 0;

        foreach ($rows as $row) {
            $currentDebt = (float) ($row['current_debt'] ?? 0);
            $monthlySalary = max(0, (float) ($row['base_salary'] ?? 0));
            $deductible = min($currentDebt, $monthlySalary);
            $newDebt = max(0, $currentDebt - $deductible);
            $isProcessable = $deductible > 0;

            $totalDebtBefore += $currentDebt;
            $totalDeductible += $deductible;
            $isProcessable ? $processableCount++ : $skippedCount++;

            $accounts[] = [
                'user_id' => (int) $row['user_id'],
                'employee_id' => $row['employee_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'category' => $row['user_type'],
                'monthly_salary' => $monthlySalary,
                'current_debt' => $currentDebt,
                'deductible_amount' => $deductible,
                'new_debt' => $newDebt,
                'processable' => $isProcessable,
            ];
        }

        return [
            'status' => 'success',
            'run_month' => $runMonth,
            'summary' => [
                'candidate_count' => count($accounts),
                'processable_count' => $processableCount,
                'skipped_count' => $skippedCount,
                'total_debt_before' => $totalDebtBefore,
                'total_deductible' => $totalDeductible,
                'total_debt_after' => max(0, $totalDebtBefore - $totalDeductible),
            ],
            'accounts' => $accounts,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function runs(BaseConnection $db, int $limit): array
    {
        $rows = $db->table('settlement_runs sr')
            ->select('sr.id, sr.run_month, sr.run_at, sr.total_accounts, sr.total_debt_before, sr.notes, u.name AS run_by_name')
            ->join('users u', 'u.id = sr.run_by', 'left')
            ->orderBy('sr.id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();

        return array_map(static function (array $row): array {
            $notes = json_decode((string) ($row['notes'] ?? ''), true);

            return [
                'id' => (int) $row['id'],
                'run_month' => $row['run_month'],
                'run_at' => $row['run_at'],
                'run_by_name' => $row['run_by_name'] ?: 'Unknown',
                'total_accounts' => (int) ($row['total_accounts'] ?? 0),
                'total_debt_before' => (float) ($row['total_debt_before'] ?? 0),
                'notes' => is_array($notes) ? $notes : [],
            ];
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    public function runDetails(BaseConnection $db, int $runId): ?array
    {
        $run = $db->table('settlement_runs sr')
            ->select('sr.id, sr.run_month, sr.run_at, sr.total_accounts, sr.total_debt_before, sr.notes, u.name AS run_by_name')
            ->join('users u', 'u.id = sr.run_by', 'left')
            ->where('sr.id', $runId)
            ->get()
            ->getRowArray();
        if (!$run) {
            return null;
        }

        $auditRows = $db->table('audit_logs al')
            ->select('al.id, al.created_at, al.entity_id, al.payload_json, u.employee_id, u.name, u.email, u.user_type')
            ->join('users u', 'u.id = al.entity_id', 'left')
            ->where('al.action', 'ACCOUNTING_SETTLEMENT_DEDUCT')
            ->like('al.payload_json', '"run_id":' . $runId)
            ->orderBy('al.id', 'ASC')
            ->get()
            ->getResultArray();

        $items = [];
        $totalDeducted = 0.0;
        $totalDebtAfter = 0.0;
        foreach ($auditRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload) || (int) ($payload['run_id'] ?? 0) !== $runId) {
                continue;
            }

            $deducted = (float) ($payload['deducted_amount'] ?? 0);
            $newDebt = (float) ($payload['new_debt'] ?? 0);
            $totalDeducted += $deducted;
            $totalDebtAfter += $newDebt;
            $items[] = [
                'user_id' => (int) ($row['entity_id'] ?? 0),
                'employee_id' => $row['employee_id'] ?? null,
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'category' => $row['user_type'] ?? null,
                'deducted_amount' => $deducted,
                'previous_debt' => (float) ($payload['previous_debt'] ?? 0),
                'new_debt' => $newDebt,
                'monthly_salary' => (float) ($payload['monthly_salary'] ?? 0),
                'created_at' => $row['created_at'],
            ];
        }

        $notes = json_decode((string) ($run['notes'] ?? ''), true);

        return [
            'status' => 'success',
            'run' => [
                'id' => (int) $run['id'],
                'run_month' => $run['run_month'],
                'run_at' => $run['run_at'],
                'run_by_name' => $run['run_by_name'] ?: 'Unknown',
                'total_accounts' => (int) ($run['total_accounts'] ?? 0),
                'total_debt_before' => (float) ($run['total_debt_before'] ?? 0),
                'notes' => is_array($notes) ? $notes : [],
            ],
            'summary' => [
                'processed_accounts' => count($items),
                'total_deducted' => $totalDeducted,
                'total_debt_after' => $totalDebtAfter,
            ],
            'items' => $items,
        ];
    }
}
