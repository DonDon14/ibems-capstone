<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Counts only explicitly marked discarded/spoiled leftovers and stock reductions. */
final class FinanceWasteReportService
{
    private const WASTE_DISPOSITIONS = ['waste', 'discarded', 'spoiled'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array{rows:array<int,array<string,mixed>>,total_quantity:int,unit_totals:array<string,int>} */
    public function build(string $fromDate, string $toDate, int $storeId = 0): array
    {
        $rows = [];
        $batches = $this->db->table('food_daily_batches b')
            ->select('b.id, b.store_id, s.store_name, b.business_date, f.name AS item_name, b.leftover, b.disposition')
            ->join('stores s', 's.id = b.store_id', 'inner')
            ->join('product_families f', 'f.id = b.family_id', 'inner')
            ->where('b.closed', 1)->where('b.leftover >', 0)
            ->where('b.business_date >=', $fromDate)->where('b.business_date <=', $toDate);
        if ($storeId > 0) {
            $batches->where('b.store_id', $storeId);
        }
        foreach ($batches->get()->getResultArray() as $row) {
            $this->appendIfWaste($rows, $row, 'Shared batch', 'portions');
        }

        $offerings = $this->db->table('daily_food_offerings o')
            ->select('o.id, o.store_id, s.store_name, o.business_date, p.name AS item_name, o.leftover, o.disposition')
            ->join('stores s', 's.id = o.store_id', 'inner')
            ->join('products p', 'p.id = o.product_id', 'inner')
            ->where('o.closed', 1)->where('o.leftover >', 0)->where('o.batch_id', 0)
            ->where('o.business_date >=', $fromDate)->where('o.business_date <=', $toDate);
        if ($storeId > 0) {
            $offerings->where('o.store_id', $storeId);
        }
        foreach ($offerings->get()->getResultArray() as $row) {
            $this->appendIfWaste($rows, $row, 'Independent offering', 'servings');
        }

        if ($this->db->tableExists('inventory_movements')
            && $this->db->fieldExists('waste_disposition', 'inventory_movements')) {
            $fromUtc = ibems_business_day_utc_bounds($fromDate)['start'];
            $toUtc = ibems_business_day_utc_bounds($toDate)['end'];
            $movements = $this->db->table('inventory_movements im')
                ->select('im.id, im.store_id, s.store_name, im.created_at, p.name AS item_name, p.unit_code, im.qty, im.waste_disposition')
                ->join('stores s', 's.id = im.store_id', 'inner')
                ->join('products p', 'p.id = im.product_id', 'inner')
                ->where('im.type', 'adjustment')->where('im.qty <', 0)
                ->whereIn('im.waste_disposition', ['discarded', 'spoiled'])
                ->where('im.created_at >=', $fromUtc)->where('im.created_at <=', $toUtc);
            if ($storeId > 0) {
                $movements->where('im.store_id', $storeId);
            }
            foreach ($movements->get()->getResultArray() as $movement) {
                $recordedAt = new \DateTimeImmutable((string) $movement['created_at'], new \DateTimeZone('UTC'));
                $rows[] = [
                    'business_date' => $recordedAt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d'),
                    'store_name' => $movement['store_name'],
                    'item_name' => $movement['item_name'],
                    'source' => 'Inventory reduction',
                    'quantity' => abs((int) $movement['qty']),
                    'unit' => (string) ($movement['unit_code'] ?: 'piece'),
                    'disposition' => $movement['waste_disposition'],
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['business_date'], $a['business_date']));
        $unitTotals = [];
        foreach ($rows as $row) {
            $unit = (string) $row['unit'];
            $unitTotals[$unit] = ($unitTotals[$unit] ?? 0) + (int) $row['quantity'];
        }
        ksort($unitTotals);
        return ['rows' => $rows, 'total_quantity' => array_sum(array_column($rows, 'quantity')), 'unit_totals' => $unitTotals];
    }

    private function appendIfWaste(array &$rows, array $row, string $source, string $unit): void
    {
        $disposition = strtolower(trim((string) $row['disposition']));
        if (!in_array($disposition, self::WASTE_DISPOSITIONS, true)) {
            return;
        }
        $rows[] = [
            'business_date' => $row['business_date'],
            'store_name' => $row['store_name'],
            'item_name' => $row['item_name'],
            'source' => $source,
            'quantity' => (int) $row['leftover'],
            'unit' => $unit,
            'disposition' => $disposition,
        ];
    }
}
