<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class AccountingDebtQueryService
{
    /** @return array<string, mixed> */
    public function listAccounts(BaseConnection $db, string $search, bool $debtOnly, int $limit): array
    {
        $salaryProfileSelect = $this->salaryProfileSelect($db, 'u');
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances')
            ? 'b.credit_rate'
            : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';
        $profileImageSelect = $db->fieldExists('profile_image_url', 'users')
            ? 'u.profile_image_url'
            : 'NULL AS profile_image_url';

        $query = $db->table('users u')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, ' . $profileImageSelect . ', u.user_type, u.is_active, u.base_salary, ' . $salaryProfileSelect . ', b.user_id AS balance_user_id, b.credit_limit, b.current_debt, b.updated_at, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff']);

        if ($debtOnly) {
            $query->where('b.current_debt >', 0);
        }

        if ($search !== '') {
            $query->groupStart()
                ->like('u.name', $search)
                ->orLike('u.email', $search)
                ->orLike('u.employee_id', $search)
                ->groupEnd();
        }

        $rows = $query
            ->orderBy('u.name', 'ASC')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->getResultArray();

        $data = array_map(fn(array $row): array => $this->normalizeAccount($row), $rows);
        $advancePayments = $this->cashbookRows($db, ['store_repayment'], 100);
        $operatorAccountabilities = $this->cashbookRows($db, ['operator_shortage'], 100);

        return [
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
            'summary' => [
                'employee_account_count' => count($data),
                'configured_account_count' => count(array_filter($data, static fn(array $row): bool => (bool) ($row['financial_profile_configured'] ?? false))),
                'needs_setup_count' => count(array_filter($data, static fn(array $row): bool => !(bool) ($row['financial_profile_configured'] ?? false))),
                'employee_debt_accounts' => count(array_filter($data, static fn(array $row): bool => (float) ($row['current_debt'] ?? 0) > 0)),
                'employee_debt_total' => array_reduce($data, static fn(float $sum, array $row): float => $sum + (float) ($row['current_debt'] ?? 0), 0.0),
                'advance_payment_count' => count($advancePayments),
                'advance_payment_total' => array_reduce($advancePayments, static fn(float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0), 0.0),
                'operator_accountability_count' => count($operatorAccountabilities),
                'operator_accountability_total' => array_reduce($operatorAccountabilities, static fn(float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0), 0.0),
            ],
            'advance_payments' => $advancePayments,
            'operator_accountabilities' => $operatorAccountabilities,
        ];
    }

    /** @return array<string, mixed>|null */
    public function profile(BaseConnection $db, int $userId): ?array
    {
        $salaryProfileSelect = $this->salaryProfileSelect($db, 'u');
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances')
            ? 'b.credit_rate'
            : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';
        $profileImageSelect = $db->fieldExists('profile_image_url', 'users')
            ? 'u.profile_image_url'
            : 'NULL AS profile_image_url';

        $row = $db->table('users u')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, ' . $profileImageSelect . ', u.user_type, u.base_salary, ' . $salaryProfileSelect . ', b.user_id AS balance_user_id, b.credit_limit, b.current_debt, b.updated_at, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.is_active', true)
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        return $row ? $this->normalizeAccount($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function history(BaseConnection $db, int $userId, int $limit): array
    {
        $rows = $db->table('audit_logs al')
            ->select('al.id, al.created_at, al.action, al.payload_json, u.name AS actor_name')
            ->join('users u', 'u.id = al.actor_id', 'left')
            ->where('al.entity', 'balances')
            ->where('al.entity_id', $userId)
            ->whereIn('al.action', [
                'ACCOUNTING_DEDUCT_DEBT',
                'ACCOUNTING_DEDUCT_FULL_DEBT',
                'ACCOUNTING_UPDATE_CREDIT_LIMIT',
                'ACCOUNTING_UPDATE_FINANCIAL_PROFILE',
                'STORE_DEBT_REPAYMENT',
            ])
            ->orderBy('al.id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();

        return array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);

            return [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'action' => $row['action'],
                'actor_name' => $row['actor_name'] ?: 'Unknown',
                'payload' => is_array($payload) ? $payload : [],
            ];
        }, $rows);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeAccount(array $row): array
    {
        $row['financial_profile_configured'] = $row['balance_user_id'] !== null
            && trim((string) ($row['employment_type'] ?? '')) !== ''
            && trim((string) ($row['salary_grade'] ?? '')) !== ''
            && trim((string) ($row['salary_effective_date'] ?? '')) !== ''
            && (int) ($row['salary_schedule_id'] ?? 0) > 0;
        unset($row['balance_user_id']);

        $creditLimit = (float) ($row['credit_limit'] ?? 0);
        $currentDebt = (float) ($row['current_debt'] ?? 0);
        $row['credit_limit'] = $creditLimit;
        $row['credit_percentage'] = SalaryCreditPolicy::percentageFromRate((float) ($row['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE));
        $row['current_debt'] = $currentDebt;
        $row['available_credit'] = max(0, $creditLimit - $currentDebt);

        return $row + $this->debtStatus($creditLimit, $currentDebt);
    }

    private function salaryProfileSelect(BaseConnection $db, string $alias): string
    {
        $parts = [];
        foreach (['employment_type', 'salary_grade', 'salary_step', 'salary_effective_date', 'salary_schedule_id'] as $field) {
            $parts[] = $db->fieldExists($field, 'users')
                ? $alias . '.' . $field
                : 'NULL AS ' . $field;
        }

        return implode(', ', $parts);
    }

    /** @return array{debt_status:string,debt_status_label:string,debt_status_tone:string,debt_ratio:float} */
    private function debtStatus(float $creditLimit, float $currentDebt): array
    {
        if ($currentDebt <= 0) {
            return ['debt_status' => 'no_outstanding_debt', 'debt_status_label' => 'No Outstanding Debt', 'debt_status_tone' => 'success', 'debt_ratio' => 0.0];
        }
        if ($creditLimit > 0 && $currentDebt > $creditLimit) {
            return ['debt_status' => 'over_limit', 'debt_status_label' => 'Over Limit', 'debt_status_tone' => 'danger', 'debt_ratio' => $currentDebt / $creditLimit];
        }

        $ratio = $creditLimit > 0 ? $currentDebt / $creditLimit : 1.0;
        if ($creditLimit > 0 && $ratio >= 1.0) {
            return ['debt_status' => 'at_credit_limit', 'debt_status_label' => 'At Credit Limit', 'debt_status_tone' => 'warning', 'debt_ratio' => $ratio];
        }

        return ['debt_status' => 'outstanding', 'debt_status_label' => 'Outstanding', 'debt_status_tone' => 'info', 'debt_ratio' => $ratio];
    }

    /** @param list<string> $entryTypes @return list<array<string, mixed>> */
    private function cashbookRows(BaseConnection $db, array $entryTypes, int $limit): array
    {
        if ($entryTypes === [] || !$db->tableExists('debt_cashbook_entries')) {
            return [];
        }

        $rows = $db->table('debt_cashbook_entries dce')
            ->select('dce.id, dce.user_id, dce.entry_type, dce.direction, dce.amount, dce.debt_before, dce.debt_after, dce.reference_type, dce.reference_id, dce.remarks, dce.meta_json, dce.created_at, u.employee_id, u.name, u.email, u.user_type, actor.name AS actor_name')
            ->join('users u', 'u.id = dce.user_id', 'left')
            ->join('users actor', 'actor.id = dce.actor_id', 'left')
            ->whereIn('dce.entry_type', $entryTypes)
            ->orderBy('dce.id', 'DESC')
            ->limit(max(1, min(300, $limit)))
            ->get()
            ->getResultArray();

        return array_map(static function (array $row): array {
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];

            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'employee_id' => $row['employee_id'] ?? null,
                'name' => $row['name'] ?: 'Unknown',
                'email' => $row['email'] ?? null,
                'user_type' => $row['user_type'] ?? null,
                'entry_type' => (string) ($row['entry_type'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'debt_before' => (float) ($row['debt_before'] ?? 0),
                'debt_after' => (float) ($row['debt_after'] ?? 0),
                'reference_type' => $row['reference_type'] ?? null,
                'reference_id' => $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
                'remarks' => $row['remarks'] ?? null,
                'actor_name' => $row['actor_name'] ?: 'System',
                'created_at' => $row['created_at'] ?? null,
                'meta' => $meta,
                'store_name' => $meta['store_name'] ?? null,
                'channel' => $meta['channel'] ?? null,
                'business_date' => $meta['business_date'] ?? null,
            ];
        }, $rows);
    }
}
