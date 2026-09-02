<?php

namespace App\Services;

use Config\Database;

final class AdminDebtQueryService
{
    public function summary(): array
    {
        $db = Database::connect();
        $todayBounds = ibems_business_day_utc_bounds();
        $todayStart = $todayBounds['start'];
        $todayEnd = $todayBounds['end'];

        $summary = $db->table('balances')
            ->select('COUNT(*) AS account_count, SUM(CASE WHEN current_debt > 0 THEN 1 ELSE 0 END) AS debt_accounts, COALESCE(SUM(current_debt), 0) AS total_debt')
            ->get()
            ->getRowArray();

        $topDebts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.profile_image_url, b.current_debt, b.credit_limit')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->where('b.current_debt >', 0)
            ->orderBy('b.current_debt', 'DESC')
            ->limit(25)
            ->get()
            ->getResultArray();

        $auditRows = $db->table('audit_logs')
            ->select('payload_json')
            ->whereIn('action', ['ACCOUNTING_DEDUCT_DEBT', 'ACCOUNTING_DEDUCT_FULL_DEBT'])
            ->where('created_at >=', $todayStart)
            ->where('created_at <=', $todayEnd)
            ->get()
            ->getResultArray();

        $todayDeductionCount = 0;
        $todayDeductionAmount = 0.0;
        foreach ($auditRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $amount = (float) ($payload['deducted_amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $todayDeductionCount++;
            $todayDeductionAmount += $amount;
        }

        return $this->result(200, [
            'status' => 'success',
            'summary' => [
                'account_count' => (int) ($summary['account_count'] ?? 0),
                'debt_accounts' => (int) ($summary['debt_accounts'] ?? 0),
                'total_debt' => (float) ($summary['total_debt'] ?? 0),
                'today_deduction_count' => $todayDeductionCount,
                'today_deduction_amount' => $todayDeductionAmount,
            ],
            'top_debts' => array_map(static function (array $row): array {
                return [
                    'user_id' => (int) $row['user_id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'current_debt' => (float) $row['current_debt'],
                    'credit_limit' => (float) $row['credit_limit'],
                ];
            }, $topDebts),
        ]);
    }

    public function detail(int $userId, array $filters): array
    {
        if ($userId <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid user account.',
            ]);
        }

        $historyPage = max(1, (int) ($filters['page'] ?? null ?? 1));
        $historyPageSize = max(5, min(50, (int) ($filters['page_size'] ?? null ?? 10)));
        $historyStoreId = max(0, (int) ($filters['store_id'] ?? null ?? 0));
        $historyDateFrom = trim((string) ($filters['date_from'] ?? null ?? ''));
        $historyDateTo = trim((string) ($filters['date_to'] ?? null ?? ''));
        $historyDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateFrom) ? $historyDateFrom : '';
        $historyDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateTo) ? $historyDateTo : '';
        if ($historyDateFrom !== '' && $historyDateTo !== '' && $historyDateFrom > $historyDateTo) {
            return $this->result(422, [
                'status' => 'error',
                'message' => 'The start date must be on or before the end date.',
            ]);
        }

        $db = Database::connect();
        $optionalUserFields = [];
        foreach (['profile_image_url', 'base_salary', 'employment_type', 'salary_grade', 'salary_step', 'salary_effective_date'] as $field) {
            $optionalUserFields[] = $db->fieldExists($field, 'users') ? 'u.' . $field : 'NULL AS ' . $field;
        }
        $creditRateSelect = $db->fieldExists('credit_rate', 'balances')
            ? 'b.credit_rate'
            : (string) SalaryCreditPolicy::DEFAULT_CREDIT_RATE . ' AS credit_rate';

        $user = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.user_type, u.is_active, ' . implode(', ', $optionalUserFields) . ', b.credit_limit, b.current_debt, b.updated_at AS balance_updated_at, ' . $creditRateSelect, false)
            ->join('balances b', 'b.user_id = u.id', 'left')
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        if (!$user) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'User account not found.',
            ]);
        }

        $creditLimit = (float) ($user['credit_limit'] ?? 0);
        $currentDebt = (float) ($user['current_debt'] ?? 0);
        $creditPercentage = SalaryCreditPolicy::percentageFromRate((float) ($user['credit_rate'] ?? SalaryCreditPolicy::DEFAULT_CREDIT_RATE));

        $debtHistory = [];
        $debtActivityTotals = ['total_added' => 0.0, 'total_reduced' => 0.0, 'activity_count' => 0];
        $historyTotal = 0;
        if ($db->tableExists('debt_cashbook_entries')) {
            $totalsRow = $db->table('debt_cashbook_entries')
                ->select("COUNT(*) AS activity_count, COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS total_added, COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS total_reduced", false)
                ->where('user_id', $userId)
                ->get()
                ->getRowArray() ?? [];
            $debtActivityTotals = [
                'total_added' => (float) ($totalsRow['total_added'] ?? 0),
                'total_reduced' => (float) ($totalsRow['total_reduced'] ?? 0),
                'activity_count' => (int) ($totalsRow['activity_count'] ?? 0),
            ];

            $historyQuery = $db->table('debt_cashbook_entries dce')
                ->join('users actor', 'actor.id = dce.actor_id', 'left')
                ->join('transactions history_txn', "dce.reference_type = 'transaction' AND history_txn.id = dce.reference_id", 'left', false);
            if ($db->tableExists('store_cash_movements')) {
                $historyQuery->join('store_cash_movements history_movement', "dce.reference_type = 'store_cash_movement' AND history_movement.id = dce.reference_id", 'left', false);
                $historyStoreExpression = 'COALESCE(history_txn.store_id, history_movement.store_id)';
            } else {
                $historyStoreExpression = 'history_txn.store_id';
            }
            $historyQuery->join('stores history_store', 'history_store.id = ' . $historyStoreExpression, 'left', false)
                ->where('dce.user_id', $userId);
            if ($historyStoreId > 0) {
                $historyQuery->where($historyStoreExpression, $historyStoreId, false);
            }
            if ($historyDateFrom !== '') {
                $historyQuery->where('dce.created_at >=', ibems_business_day_utc_bounds($historyDateFrom)['start']);
            }
            if ($historyDateTo !== '') {
                $historyQuery->where('dce.created_at <=', ibems_business_day_utc_bounds($historyDateTo)['end']);
            }

            $historyTotal = (clone $historyQuery)->countAllResults();
            $historyTotalPages = max(1, (int) ceil($historyTotal / $historyPageSize));
            $historyPage = min($historyPage, $historyTotalPages);
            $debtHistory = $historyQuery
                ->select('dce.id, dce.entry_type, dce.direction, dce.amount, dce.debt_before, dce.debt_after, dce.reference_type, dce.reference_id, dce.remarks, dce.created_at, actor.name AS actor_name, history_store.id AS store_id, history_store.store_name')
                ->orderBy('dce.id', 'DESC')
                ->limit($historyPageSize, ($historyPage - 1) * $historyPageSize)
                ->get()
                ->getResultArray();
        }

        $storesById = [];
        if ($db->tableExists('debt_cashbook_entries') && $db->tableExists('transactions') && $db->tableExists('stores')) {
            $debtByStore = $db->table('debt_cashbook_entries dce')
                ->select('s.id AS store_id, s.store_name, COUNT(dce.id) AS debt_transaction_count, COALESCE(SUM(dce.amount), 0) AS debt_added, MAX(dce.created_at) AS last_activity_at')
                ->join('transactions t', "dce.reference_type = 'transaction' AND t.id = dce.reference_id", 'inner', false)
                ->join('stores s', 's.id = t.store_id', 'inner')
                ->where('dce.user_id', $userId)
                ->where('dce.direction', 'debit')
                ->groupBy('s.id, s.store_name')
                ->get()
                ->getResultArray();
            foreach ($debtByStore as $row) {
                $storeId = (int) ($row['store_id'] ?? 0);
                $storesById[$storeId] = [
                    'store_id' => $storeId,
                    'store_name' => $row['store_name'] ?: 'Unknown store',
                    'debt_transaction_count' => (int) ($row['debt_transaction_count'] ?? 0),
                    'debt_added' => (float) ($row['debt_added'] ?? 0),
                    'store_repayments' => 0.0,
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                ];
            }

            if ($db->tableExists('store_cash_movements')) {
                $repaymentsByStore = $db->table('debt_cashbook_entries dce')
                    ->select('s.id AS store_id, s.store_name, COALESCE(SUM(dce.amount), 0) AS store_repayments, MAX(dce.created_at) AS last_activity_at')
                    ->join('store_cash_movements scm', "dce.reference_type = 'store_cash_movement' AND scm.id = dce.reference_id", 'inner', false)
                    ->join('stores s', 's.id = scm.store_id', 'inner')
                    ->where('dce.user_id', $userId)
                    ->where('dce.direction', 'credit')
                    ->groupBy('s.id, s.store_name')
                    ->get()
                    ->getResultArray();
                foreach ($repaymentsByStore as $row) {
                    $storeId = (int) ($row['store_id'] ?? 0);
                    $storesById[$storeId] ??= [
                        'store_id' => $storeId,
                        'store_name' => $row['store_name'] ?: 'Unknown store',
                        'debt_transaction_count' => 0,
                        'debt_added' => 0.0,
                        'store_repayments' => 0.0,
                        'last_activity_at' => null,
                    ];
                    $storesById[$storeId]['store_repayments'] = (float) ($row['store_repayments'] ?? 0);
                    if ((string) ($row['last_activity_at'] ?? '') > (string) ($storesById[$storeId]['last_activity_at'] ?? '')) {
                        $storesById[$storeId]['last_activity_at'] = $row['last_activity_at'];
                    }
                }
            }
        }
        $stores = array_values($storesById);
        usort($stores, static fn(array $left, array $right): int => strcmp((string) ($right['last_activity_at'] ?? ''), (string) ($left['last_activity_at'] ?? '')));

        return $this->result(200, [
            'status' => 'success',
            'data' => [
                'general' => [
                    'user_id' => (int) $user['id'],
                    'employee_id' => $user['employee_id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'profile_image_url' => $user['profile_image_url'] ?? null,
                    'user_type' => $user['user_type'],
                    'is_active' => ibems_bool($user['is_active'] ?? false),
                    'base_salary' => (float) ($user['base_salary'] ?? 0),
                    'employment_type' => $user['employment_type'] ?? null,
                    'salary_grade' => $user['salary_grade'] ?? null,
                    'salary_step' => $user['salary_step'] ?? null,
                    'salary_effective_date' => $user['salary_effective_date'] ?? null,
                ],
                'debt' => [
                    'current_debt' => $currentDebt,
                    'credit_limit' => $creditLimit,
                    'available_credit' => max(0, $creditLimit - $currentDebt),
                    'credit_percentage' => $creditPercentage,
                    'usage_percentage' => $creditLimit > 0 ? min(100, ($currentDebt / $creditLimit) * 100) : 0,
                    'total_added' => $debtActivityTotals['total_added'],
                    'total_reduced' => $debtActivityTotals['total_reduced'],
                    'activity_count' => $debtActivityTotals['activity_count'],
                    'updated_at' => $user['balance_updated_at'] ?? null,
                    'history' => array_map(static fn(array $row): array => [
                        'id' => (int) $row['id'],
                        'entry_type' => $row['entry_type'],
                        'direction' => $row['direction'],
                        'amount' => (float) $row['amount'],
                        'debt_before' => (float) $row['debt_before'],
                        'debt_after' => (float) $row['debt_after'],
                        'reference_type' => $row['reference_type'],
                        'reference_id' => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
                        'remarks' => $row['remarks'],
                        'actor_name' => $row['actor_name'] ?: 'System',
                        'store_id' => isset($row['store_id']) ? (int) $row['store_id'] : null,
                        'store_name' => $row['store_name'] ?? null,
                        'created_at' => $row['created_at'],
                    ], $debtHistory),
                    'pagination' => [
                        'page' => $historyPage,
                        'page_size' => $historyPageSize,
                        'total' => $historyTotal,
                        'total_pages' => max(1, (int) ceil($historyTotal / $historyPageSize)),
                        'store_id' => $historyStoreId > 0 ? $historyStoreId : null,
                        'date_from' => $historyDateFrom !== '' ? $historyDateFrom : null,
                        'date_to' => $historyDateTo !== '' ? $historyDateTo : null,
                    ],
                ],
                'stores' => array_map(static fn(array $row): array => [
                    'store_id' => (int) ($row['store_id'] ?? 0),
                    'store_name' => $row['store_name'] ?: 'Unknown store',
                    'debt_transaction_count' => (int) ($row['debt_transaction_count'] ?? 0),
                    'debt_added' => (float) ($row['debt_added'] ?? 0),
                    'store_repayments' => (float) ($row['store_repayments'] ?? 0),
                    'net_store_activity' => max(0, (float) ($row['debt_added'] ?? 0) - (float) ($row['store_repayments'] ?? 0)),
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                ], $stores),
            ],
        ]);
    }

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
