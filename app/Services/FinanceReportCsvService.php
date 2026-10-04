<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Exports the same already-built report sections and integer-cent totals shown on screen. */
final class FinanceReportCsvService
{
    /** @param array<string,mixed> $report @param array<string,mixed> $deductions @param array<string,mixed> $waste @param list<array<string,mixed>> $stores */
    public function render(array $report, array $deductions, array $waste, array $stores): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare the finance CSV.');
        }
        try {
            $names = array_column($stores, 'store_name', 'id');
            $write = static function (array $cells) use ($stream): void {
                $safe = array_map(static function (mixed $value): string {
                    $text = (string) ($value ?? '');
                    return preg_match('/^[\s]*[=+@-]/u', $text) === 1 ? "'" . $text : $text;
                }, $cells);
                if (fputcsv($stream, $safe, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('Unable to write the finance CSV.');
                }
            };
            $row = static function (string $section, array $values = []) use ($write): void {
                $columns = array_replace(array_fill(0, 13, ''), $values);
                $write(array_merge([$section], $columns));
            };
            $money = static fn (mixed $cents): string => number_format((int) $cents / 100, 2, '.', '');
            $localTime = static fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');

            $write(['Section', 'Store', 'Date / time', 'Name / account', 'ID / reference', 'Audience / source',
                'Transactions', 'Quantity', 'Unit', 'Sales', 'Cash', 'Debt / charge', 'Other', 'Deducted']);
            $row('Report range', [1 => $report['from_date'] . ' to ' . $report['to_date'], 4 => 'Asia/Manila']);
            $row('Store filter', [0 => (int) $report['store_id'] > 0
                ? ($names[(int) $report['store_id']] ?? ('Store #' . $report['store_id'])) : 'All stores']);
            $row('Deduction scope', [0 => 'All stores']);

            foreach ($report['stores'] as $store) {
                $row('Sales by store', [0 => $store['name'], 5 => $store['transactions'], 8 => $money($store['sales_cents']),
                    9 => $money($store['cash_cents']), 10 => $money($store['debt_cents']), 11 => $money($store['other_cents'])]);
            }
            $totals = $report['totals'];
            $row('Sales total', [0 => 'All shown stores', 5 => $totals['transactions'], 8 => $money($totals['sales_cents']),
                9 => $money($totals['cash_cents']), 10 => $money($totals['debt_cents']), 11 => $money($totals['other_cents'])]);

            foreach ($report['customer_types'] as $customer) {
                $row('Customer type', [2 => $customer['customer_label'], 4 => ucfirst((string) $customer['audience']),
                    5 => $customer['transactions'], 8 => $money($customer['sales_cents'])]);
            }
            foreach ($report['products'] as $product) {
                $row('Products sold', [0 => $names[(int) $product['store_id']] ?? ('Store #' . $product['store_id']),
                    2 => $product['product_label'] ?? ($product['product_name'] ?? ('Product #' . $product['product_id'])), 6 => $product['quantity'],
                    8 => $money($product['revenue_cents'])]);
            }
            foreach ($report['employee_purchases'] as $employee) {
                $row('Purchases by employee', [2 => $employee['employee_name'], 3 => $employee['employee_id'],
                    5 => $employee['transactions'], 8 => $money($employee['sales_cents'])]);
            }
            foreach ($report['charges'] as $charge) {
                $account = $charge['department_name']
                    ? 'Department · ' . $charge['department_name']
                    : (($charge['employee_name'] ?? 'Unknown employee') . ' · ' . ($charge['employee_id'] ?? 'No ID'));
                $row('Debt / charge transactions', [0 => $charge['store_name'], 1 => $localTime((string) $charge['created_at']),
                    2 => $account, 3 => $charge['client_txn_id'], 10 => $money($charge['charge_cents'])]);
            }
            foreach ($deductions['rows'] as $deduction) {
                $row('Confirmed deductions', [1 => $localTime((string) $deduction['recorded_at']),
                    2 => $deduction['employee_name'] . ' · ' . ($deduction['employee_id'] ?? 'No ID'),
                    3 => ($deduction['period_code'] ? $deduction['period_code'] . ' · ' : '') . $deduction['reference'],
                    4 => $deduction['source'],
                    12 => $money($deduction['amount_cents'])]);
            }
            $row('Deductions total', [0 => 'All stores', 5 => $deductions['count'], 12 => $money($deductions['total_cents'])]);
            foreach ($waste['rows'] as $item) {
                $row('Explicit waste', [0 => $item['store_name'], 1 => $item['business_date'], 2 => $item['item_name'],
                    4 => $item['source'] . ' · ' . ucfirst((string) $item['disposition']), 6 => $item['quantity'], 7 => $item['unit']]);
            }
            foreach ($waste['unit_totals'] ?? [] as $unit => $quantity) {
                $row('Waste subtotal', [6 => $quantity, 7 => $unit]);
            }

            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new RuntimeException('Unable to finish the finance CSV.');
            }
            return "\xEF\xBB\xBF" . $csv;
        } finally {
            fclose($stream);
        }
    }
}
