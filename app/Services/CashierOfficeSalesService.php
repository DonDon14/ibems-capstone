<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Read-only transaction totals for the institutional Cashier Office. */
final class CashierOfficeSalesService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array{date:string,stores:list<array<string,mixed>>,totals:array<string,int>} */
    public function daily(string $businessDate): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException('Choose a valid business date.');
        }

        $bounds = ibems_business_day_utc_bounds($businessDate);
        $stores = [];
        foreach ($this->db->table('stores')->select('id, store_name')->orderBy('store_name', 'ASC')->get()->getResultArray() as $store) {
            $id = (int) $store['id'];
            $stores[$id] = [
                'id' => $id,
                'name' => (string) $store['store_name'],
                'transactions' => 0,
                'sales_cents' => 0,
                'cash_cents' => 0,
                'debt_cents' => 0,
                'other_cents' => 0,
            ];
        }

        $transactions = $this->db->table('transactions t')
            ->select('t.store_id, COUNT(t.id) AS transaction_count, COALESCE(SUM(t.amount), 0) AS sales_amount', false)
            ->where('t.status', 'completed')
            ->where('t.created_at >=', $bounds['start'])
            ->where('t.created_at <=', $bounds['end'])
            ->groupBy('t.store_id')
            ->get()->getResultArray();
        foreach ($transactions as $row) {
            $id = (int) $row['store_id'];
            if (!isset($stores[$id])) {
                continue;
            }
            $stores[$id]['transactions'] = (int) $row['transaction_count'];
            $stores[$id]['sales_cents'] = (int) round((float) $row['sales_amount'] * 100);
        }

        $methodSql = 'COALESCE(tp.payment_method, t.payment_method)';
        $amountSql = 'COALESCE(tp.amount, t.amount)';
        $paymentQuery = $this->db->table('transactions t')
            ->select("t.store_id, {$methodSql} AS payment_method, COALESCE(SUM({$amountSql}), 0) AS payment_amount", false)
            ->where('t.status', 'completed')
            ->where('t.created_at >=', $bounds['start'])
            ->where('t.created_at <=', $bounds['end'])
            ->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left');
        foreach ($paymentQuery->groupBy('t.store_id')->groupBy($methodSql, false)->get()->getResultArray() as $row) {
            $id = (int) $row['store_id'];
            if (!isset($stores[$id])) {
                continue;
            }
            $method = strtolower((string) $row['payment_method']);
            $key = $method === 'cash' ? 'cash_cents' : ($method === 'debt' ? 'debt_cents' : 'other_cents');
            $stores[$id][$key] += (int) round((float) $row['payment_amount'] * 100);
        }

        $totals = ['transactions' => 0, 'sales_cents' => 0, 'cash_cents' => 0, 'debt_cents' => 0, 'other_cents' => 0];
        foreach ($stores as $store) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $store[$key];
            }
        }

        return ['date' => $businessDate, 'stores' => array_values($stores), 'totals' => $totals];
    }
}
