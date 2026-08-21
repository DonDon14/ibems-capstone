<?php

namespace App\Services;

use Config\Database;

class DebtPeriodRegisterService
{
    public function build(array $period): array
    {
        $start = (string) ($period['date_start'] ?? '');
        $end = (string) ($period['date_end'] ?? '');
        if ($start === '' || $end === '') {
            return [];
        }

        $db = Database::connect();
        $accounts = $db->table('balances b')
            ->select('u.id AS user_id, u.employee_id, u.name, u.email, u.user_type, u.base_salary, u.is_active, b.credit_limit, b.current_debt')
            ->join('users u', 'u.id = b.user_id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff'])
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();

        $entriesByUser = [];
        try {
            $entries = $db->table('debt_cashbook_entries')
                ->select('user_id, direction, amount, created_at')
                ->orderBy('created_at', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($entries as $entry) {
                $entriesByUser[(int) ($entry['user_id'] ?? 0)][] = $entry;
            }
        } catch (\Throwable) {
            $entriesByUser = [];
        }

        return array_map(function (array $account) use ($entriesByUser, $start, $end): array {
            $userId = (int) $account['user_id'];
            $periodDebits = 0.0;
            $periodCredits = 0.0;
            $afterDebits = 0.0;
            $afterCredits = 0.0;
            foreach ($entriesByUser[$userId] ?? [] as $entry) {
                $amount = round((float) ($entry['amount'] ?? 0), 2);
                $isDebit = strtolower((string) ($entry['direction'] ?? '')) === 'debit';
                $date = substr((string) ($entry['created_at'] ?? ''), 0, 10);
                if ($date >= $start && $date <= $end) {
                    if ($isDebit) {
                        $periodDebits += $amount;
                    } else {
                        $periodCredits += $amount;
                    }
                } elseif ($date > $end) {
                    if ($isDebit) {
                        $afterDebits += $amount;
                    } else {
                        $afterCredits += $amount;
                    }
                }
            }

            $currentDebt = round((float) ($account['current_debt'] ?? 0), 2);
            $cutoffDebt = max(0, round($currentDebt - $afterDebits + $afterCredits, 2));
            $opening = max(0, round($cutoffDebt - $periodDebits + $periodCredits, 2));

            return $account + [
                'opening_debt' => max(0, round($opening, 2)),
                'period_debits' => round($periodDebits, 2),
                'period_credits' => round($periodCredits, 2),
                'cutoff_debt' => $cutoffDebt,
                'salary_reference' => max(0, round((float) ($account['base_salary'] ?? 0), 2)),
                'history_complete' => true,
            ];
        }, $accounts);
    }
}
