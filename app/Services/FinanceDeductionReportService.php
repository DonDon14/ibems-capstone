<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Read-only deduction report; batch cashbook mirrors are deliberately excluded. */
final class FinanceDeductionReportService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array{rows:array<int,array<string,mixed>>,total_cents:int,count:int} */
    public function build(string $fromDate, string $toDate): array
    {
        foreach ([$fromDate, $toDate] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Choose valid dates in YYYY-MM-DD format.');
            }
        }
        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('Choose a valid date range.');
        }
        $from = ibems_business_day_utc_bounds($fromDate)['start'];
        $to = ibems_business_day_utc_bounds($toDate)['end'];

        return $this->buildForStorageRange($from, $to);
    }

    /** @return array{rows:array<int,array<string,mixed>>,total_cents:int,count:int} */
    public function buildForStorageRange(string $from, string $to): array
    {

        $rows = [];
        $batchRows = $this->db->table('deduction_batch_items i')
            ->select('i.id, i.confirmed_at AS recorded_at, i.confirmed_amount AS amount, i.result_reference AS reference, u.name AS employee_name, u.employee_id, dp.period_code')
            ->join('users u', 'u.id = i.user_id', 'inner')
            ->join('deduction_batches b', 'b.id = i.batch_id', 'inner')
            ->join('deduction_periods dp', 'dp.id = b.period_id', 'inner')
            ->where('i.confirmed_amount >', 0)
            ->where('i.confirmed_at >=', $from)->where('i.confirmed_at <=', $to)
            ->get()->getResultArray();
        foreach ($batchRows as $row) {
            $rows[] = [
                'source' => 'Confirmed payroll batch',
                'recorded_at' => $row['recorded_at'],
                'employee_name' => $row['employee_name'],
                'employee_id' => $row['employee_id'],
                'reference' => $row['reference'] ?: ('Batch item #' . $row['id']),
                'period_code' => $row['period_code'],
                'amount_cents' => $this->cents($row['amount']),
            ];
        }

        $legacyRows = $this->db->table('debt_cashbook_entries e')
            ->select('e.id, e.user_id, e.created_at AS recorded_at, e.amount, e.reference_type, e.reference_id, u.name AS employee_name, u.employee_id')
            ->join('users u', 'u.id = e.user_id', 'inner')
            ->where('e.direction', 'credit')
            ->whereIn('e.entry_type', ['salary_deduction', 'manual_deduction', 'full_deduction'])
            ->where('e.created_at >=', $from)->where('e.created_at <=', $to)
            ->get()->getResultArray();
        $mirrorKeys = $this->confirmedBatchMirrorKeys($legacyRows);
        foreach ($legacyRows as $row) {
            $referenceType = trim((string) ($row['reference_type'] ?? ''));
            $referenceId = (int) ($row['reference_id'] ?? 0);
            $mirrorKey = $referenceType . ':' . $referenceId . ':' . $row['user_id'] . ':' . $this->cents($row['amount']);
            if (isset($mirrorKeys[$mirrorKey])) {
                continue;
            }
            $reference = trim((string) ($row['reference_type'] ?? ''));
            $rows[] = [
                'source' => 'Legacy deduction',
                'recorded_at' => $row['recorded_at'],
                'employee_name' => $row['employee_name'],
                'employee_id' => $row['employee_id'],
                'reference' => $reference !== '' ? $reference . ' #' . ($row['reference_id'] ?? $row['id']) : 'Cashbook #' . $row['id'],
                'period_code' => null,
                'amount_cents' => $this->cents($row['amount']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['recorded_at'], (string) $a['recorded_at']));

        return ['rows' => $rows, 'count' => count($rows), 'total_cents' => array_sum(array_column($rows, 'amount_cents'))];
    }

    /** @param array<int,array<string,mixed>> $legacyRows
     *  @return array<string,bool>
     */
    private function confirmedBatchMirrorKeys(array $legacyRows): array
    {
        $batchIds = [];
        $itemIds = [];
        foreach ($legacyRows as $row) {
            $id = (int) ($row['reference_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($row['reference_type'] === 'deduction_batch') {
                $batchIds[$id] = $id;
            } elseif ($row['reference_type'] === 'deduction_batch_item') {
                $itemIds[$id] = $id;
            }
        }
        if ($batchIds === [] && $itemIds === []) {
            return [];
        }

        $query = $this->db->table('deduction_batch_items')
            ->select('id, batch_id, user_id, confirmed_amount')
            ->where('confirmed_amount >', 0)
            ->groupStart();
        if ($batchIds !== []) {
            $query->whereIn('batch_id', array_values($batchIds));
        }
        if ($itemIds !== []) {
            if ($batchIds !== []) {
                $query->orWhereIn('id', array_values($itemIds));
            } else {
                $query->whereIn('id', array_values($itemIds));
            }
        }
        $keys = [];
        foreach ($query->groupEnd()->get()->getResultArray() as $item) {
            $suffix = ':' . $item['user_id'] . ':' . $this->cents($item['confirmed_amount']);
            $keys['deduction_batch:' . $item['batch_id'] . $suffix] = true;
            $keys['deduction_batch_item:' . $item['id'] . $suffix] = true;
        }
        return $keys;
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
