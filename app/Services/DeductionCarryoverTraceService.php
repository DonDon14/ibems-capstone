<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/** The latest earlier confirmed result that left an employee balance open. */
final class DeductionCarryoverTraceService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @param list<int> $userIds @param array<string,mixed> $period @return array<int,array<string,mixed>> */
    public function priorResults(array $userIds, array $period): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        $start = (string) ($period['date_start'] ?? '');
        if ($userIds === [] || $start === '') {
            return [];
        }

        $rows = $this->db->table('deduction_batch_items i')
            ->select('i.user_id, i.result_status, i.confirmed_at, i.carryover_amount, i.result_reference, p.period_code, p.label, p.date_end')
            ->join('deduction_batches b', 'b.id = i.batch_id', 'inner')
            ->join('deduction_periods p', 'p.id = b.period_id', 'inner')
            ->whereIn('i.user_id', $userIds)
            ->where('p.date_end <', $start)
            ->where('i.result_status !=', 'pending')
            ->where('i.carryover_amount >', 0)
            ->whereIn('b.status', ['partially_processed', 'processed', 'reconciled', 'finalized'])
            ->orderBy('p.date_end', 'DESC')
            ->orderBy('i.confirmed_at', 'DESC')
            ->orderBy('i.id', 'DESC')
            ->get()->getResultArray();

        $timezone = new DateTimeZone('Asia/Manila');
        $utc = new DateTimeZone('UTC');
        $today = ibems_now()->setTimezone($timezone)->setTime(0, 0);
        $result = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            if (isset($result[$userId])) {
                continue;
            }
            $confirmedAt = (string) ($row['confirmed_at'] ?? '');
            $confirmed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $confirmedAt, $utc);
            $confirmedDay = $confirmed ? DateTimeImmutable::createFromFormat('!Y-m-d', $confirmed->setTimezone($timezone)->format('Y-m-d'), $timezone) : false;
            $result[$userId] = [
                'period_code' => (string) ($row['period_code'] ?? ''),
                'period_label' => (string) ($row['label'] ?? ''),
                'result_status' => (string) $row['result_status'],
                'result_reference' => (string) ($row['result_reference'] ?? ''),
                'confirmed_at' => (string) ($row['confirmed_at'] ?? ''),
                'balance_after_result' => (float) $row['carryover_amount'],
                'days_since_result' => $confirmedDay ? max(0, (int) $confirmedDay->diff($today)->format('%r%a')) : null,
            ];
        }

        return $result;
    }
}
