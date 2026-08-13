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
        $accountBalances = [];
        if ($db->tableExists('payment_destination_accounts') && $db->tableExists('transaction_payments')) {
            $accountRows = $db->table('payment_destination_accounts a')
                ->select('a.id, a.account_name, a.account_number, m.code AS payment_method, m.label AS payment_method_label, a.image_url, COALESCE(SUM(CASE WHEN t.id IS NOT NULL THEN tp.amount ELSE 0 END), 0) AS sales', false)
                ->join('store_payment_methods m', 'm.id = a.payment_method_id')
                ->join('transaction_payments tp', 'tp.destination_account_id = a.id', 'left')
                ->join('transactions t', "t.id = tp.transaction_id AND t.store_id = " . $db->escape($storeId) . " AND t.created_at >= " . $db->escape($businessDate . ' 00:00:00') . " AND t.created_at <= " . $db->escape($businessDate . ' 23:59:59'), 'left', false)
                ->where('a.store_id', $storeId)->where('a.is_active', true)
                ->groupBy('a.id, a.account_name, a.account_number, m.code, m.label, a.image_url')->orderBy('m.sort_order', 'ASC')->get()->getResultArray();
            $openingByAccount = [];
            if ($db->tableExists('store_day_payment_account_balances') && (int) ($session['id'] ?? 0) > 0) {
                foreach ($db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) $session['id'])->get()->getResultArray() as $balanceRow) {
                    $openingByAccount[(int) $balanceRow['destination_account_id']] = (float) ($balanceRow['opening_balance'] ?? 0);
                }
            }
            foreach ($accountRows as $row) { $opening = (float) ($openingByAccount[(int) $row['id']] ?? 0); $accountBalances[] = [
                'id' => (int) $row['id'], 'account_name' => (string) $row['account_name'], 'account_number' => (string) $row['account_number'],
                'payment_method' => (string) $row['payment_method'], 'payment_method_label' => (string) $row['payment_method_label'],
                'image_url' => (string) ($row['image_url'] ?? ''), 'sales' => (float) ($row['sales'] ?? 0),
                'opening_balance' => $opening, 'expected_balance' => $opening + (float) ($row['sales'] ?? 0),
            ]; }
        }
        return [
            'cash_sales' => $cashSales, 'ecash_sales' => $ecashSales, 'debt_sales' => $debtSales,
            'cash_debt_payments' => $cashDebtPayments, 'ecash_debt_payments' => $ecashDebtPayments,
            'cash_in' => $cashIn, 'cash_out' => $cashOut, 'ecash_in' => $ecashIn, 'ecash_out' => $ecashOut,
            'expected_cash_on_hand' => $expectedCash, 'expected_ecash_on_hand' => $expectedEcash,
            'expected_total_on_hand' => $expectedCash + $expectedEcash,
            'payment_account_balances' => $accountBalances,
        ];
    }
}
