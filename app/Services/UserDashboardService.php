<?php

namespace App\Services;

use Config\Database;

final class UserDashboardService
{
    public function data(int $userId, string $requestedPeriod = 'day'): array
    {
        if ($userId <= 0) {
            return $this->result(401, [
                'status' => 'error',
                'message' => 'Not authenticated.',
            ]);
        }

        $db = Database::connect();
        $period = DashboardPeriod::resolve($requestedPeriod);

        $balance = $db->table('balances')
            ->select('credit_limit, current_debt')
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        $txnSummary = $db->table('transactions')
            ->select('COUNT(*) AS txn_count, COALESCE(SUM(amount),0) AS total_spent')
            ->where('user_id', $userId)
            ->where('created_at >=', $period['start'])
            ->where('created_at <=', $period['end'])
            ->get()
            ->getRowArray();

        $cashbookSummaryRows = $db->table('debt_cashbook_entries')
            ->select('direction, COALESCE(SUM(amount),0) AS total_amount')
            ->where('user_id', $userId)
            ->where('created_at >=', $period['start'])
            ->where('created_at <=', $period['end'])
            ->groupBy('direction')
            ->get()
            ->getResultArray();

        $debtAddedTotal = 0.0;
        $deductedTotal = 0.0;
        foreach ($cashbookSummaryRows as $row) {
            $direction = strtolower((string) ($row['direction'] ?? ''));
            $amount = (float) ($row['total_amount'] ?? 0);
            if ($direction === 'debit') {
                $debtAddedTotal += $amount;
            } elseif ($direction === 'credit') {
                $deductedTotal += $amount;
            }
        }

        $recentTransactions = $db->table('transactions t')
            ->select('t.id, t.client_txn_id, t.created_at, t.payment_method, t.amount, s.store_name')
            ->join('stores s', 's.id = t.store_id', 'left')
            ->where('t.user_id', $userId)
            ->where('t.created_at >=', $period['start'])
            ->where('t.created_at <=', $period['end'])
            ->orderBy('t.id', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $recentCashbook = $db->table('debt_cashbook_entries')
            ->select('id, created_at, entry_type, direction, amount, debt_after, remarks')
            ->where('user_id', $userId)
            ->where('created_at >=', $period['start'])
            ->where('created_at <=', $period['end'])
            ->orderBy('id', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        $businessZone = new \DateTimeZone('Asia/Manila');
        $storageZone = new \DateTimeZone('UTC');
        $trendByDate = [];
        $trendSeed = DashboardPeriod::trendSeed($period);
        foreach ($trendSeed as $seed) {
            $trendByDate[$seed['key']] = ['date' => $seed['date'], 'amount' => 0.0];
        }

        $trendRows = $db->table('transactions')
            ->select('created_at, amount')
            ->where('user_id', $userId)
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
            $trendByDate[$bucketKey]['amount'] += (float) ($row['amount'] ?? 0);
        }

        $trend = [];
        foreach ($trendSeed as $seed) {
            $trend[] = $trendByDate[$seed['key']];
        }

        $summary = [
            'credit_limit' => (float) ($balance['credit_limit'] ?? 0),
            'current_debt' => (float) ($balance['current_debt'] ?? 0),
            'available_credit' => max(0, (float) ($balance['credit_limit'] ?? 0) - (float) ($balance['current_debt'] ?? 0)),
            'txn_count' => (int) ($txnSummary['txn_count'] ?? 0),
            'total_spent' => (float) ($txnSummary['total_spent'] ?? 0),
            'debt_added_total' => $debtAddedTotal,
            'debt_deducted_total' => $deductedTotal,
        ];
        $summary = array_merge($summary, $this->debtStatus($summary['credit_limit'], $summary['current_debt']));

        return $this->result(200, [
            'status' => 'success',
            'period' => DashboardPeriod::publicMeta($period),
            'summary' => $summary,
            'trend' => $trend,
            'recent_transactions' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'client_txn_id' => (string) ($row['client_txn_id'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'payment_method' => (string) ($row['payment_method'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'store_name' => (string) ($row['store_name'] ?? ''),
                ];
            }, $recentTransactions),
            'recent_cashbook' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'entry_type' => (string) ($row['entry_type'] ?? ''),
                    'direction' => (string) ($row['direction'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'debt_after' => (float) ($row['debt_after'] ?? 0),
                    'remarks' => (string) ($row['remarks'] ?? ''),
                ];
            }, $recentCashbook),
        ]);
    }

    /** @return array<string, float|string> */
    public function debtStatus(float $creditLimit, float $currentDebt): array
    {
        if ($currentDebt <= 0) {
            return [
                'debt_status' => 'paid',
                'debt_status_label' => 'Paid',
                'debt_status_tone' => 'success',
                'debt_status_message' => 'Your account has no unpaid debt.',
                'debt_ratio' => 0.0,
            ];
        }

        if ($creditLimit > 0 && $currentDebt > $creditLimit) {
            return [
                'debt_status' => 'over_limit',
                'debt_status_label' => 'Over Limit',
                'debt_status_tone' => 'danger',
                'debt_status_message' => 'Your current debt is above your credit limit. Please coordinate with Accounting before adding more debt purchases.',
                'debt_ratio' => $currentDebt / $creditLimit,
            ];
        }

        $ratio = $creditLimit > 0 ? $currentDebt / $creditLimit : 1.0;
        if ($ratio >= 0.5) {
            return [
                'debt_status' => 'partially_settled',
                'debt_status_label' => 'Partially Settled',
                'debt_status_tone' => 'warning',
                'debt_status_message' => 'You have an unpaid balance and have used more than half of your credit limit.',
                'debt_ratio' => $ratio,
            ];
        }

        return [
            'debt_status' => 'unpaid',
            'debt_status_label' => 'Unpaid',
            'debt_status_tone' => 'info',
            'debt_status_message' => 'You have an unpaid balance within your available credit limit.',
            'debt_ratio' => $ratio,
        ];
    }
    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
