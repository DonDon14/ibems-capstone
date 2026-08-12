<?php

namespace App\Services;

use Config\Database;

final class StoreDayExpectedService
{
    public function calculate(int $storeId, array $session): array
    {
        $empty = [
            'cash_sales' => 0.0, 'ecash_sales' => 0.0, 'debt_sales' => 0.0,
            'cash_debt_payments' => 0.0, 'ecash_debt_payments' => 0.0,
            'cash_in' => 0.0, 'cash_out' => 0.0, 'ecash_in' => 0.0, 'ecash_out' => 0.0,
            'expected_cash_on_hand' => 0.0, 'expected_ecash_on_hand' => 0.0, 'expected_total_on_hand' => 0.0,
        ];
        if ($storeId <= 0) return $empty;

        $businessDate = (string) ($session['business_date'] ?? date('Y-m-d'));
        $db = Database::connect();
        $paymentRows = $db->table('transactions')->select('payment_method, COALESCE(SUM(amount), 0) AS total_sales')
            ->where('store_id', $storeId)->where('created_at >=', $businessDate . ' 00:00:00')
            ->where('created_at <=', $businessDate . ' 23:59:59')->groupBy('payment_method')->get()->getResultArray();
        $cashSales = $ecashSales = $debtSales = 0.0;
        foreach ($paymentRows as $row) {
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            $amount = (float) ($row['total_sales'] ?? 0);
            if ($method === 'cash') $cashSales += $amount;
            elseif ($method === 'debt') $debtSales += $amount;
            else $ecashSales += $amount;
        }

        $cashIn = $cashOut = $ecashIn = $ecashOut = $cashDebtPayments = $ecashDebtPayments = 0.0;
        $movements = $db->table('store_cash_movements')->select('channel, movement_type, amount, reason')
            ->where('store_id', $storeId)->where('business_date', $businessDate)->get()->getResultArray();
        foreach ($movements as $row) {
            $ecash = strtolower((string) ($row['channel'] ?? 'cash')) === 'ecash';
            $out = strtolower((string) ($row['movement_type'] ?? 'cash_in')) === 'cash_out';
            $amount = (float) ($row['amount'] ?? 0);
            $debtPayment = stripos((string) ($row['reason'] ?? ''), 'Debt repayment') === 0;
            if ($ecash) {
                if ($out) $ecashOut += $amount; else { $ecashIn += $amount; if ($debtPayment) $ecashDebtPayments += $amount; }
            } else {
                if ($out) $cashOut += $amount; else { $cashIn += $amount; if ($debtPayment) $cashDebtPayments += $amount; }
            }
        }

        $expectedCash = (float) ($session['opening_cash'] ?? 0) + $cashSales + $cashIn - $cashOut;
        $expectedEcash = (float) ($session['opening_ecash'] ?? 0) + $ecashSales + $ecashIn - $ecashOut;
        return [
            'cash_sales' => $cashSales, 'ecash_sales' => $ecashSales, 'debt_sales' => $debtSales,
            'cash_debt_payments' => $cashDebtPayments, 'ecash_debt_payments' => $ecashDebtPayments,
            'cash_in' => $cashIn, 'cash_out' => $cashOut, 'ecash_in' => $ecashIn, 'ecash_out' => $ecashOut,
            'expected_cash_on_hand' => $expectedCash, 'expected_ecash_on_hand' => $expectedEcash,
            'expected_total_on_hand' => $expectedCash + $expectedEcash,
        ];
    }
}
