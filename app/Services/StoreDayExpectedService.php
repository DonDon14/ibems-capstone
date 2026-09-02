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

        $businessDate = (string) ($session['business_date'] ?? ibems_business_date());
        $db = Database::connect();
        $paymentBuilder = $db->table('transactions t');
        if ($db->tableExists('transaction_payments')) {
            // Payment lines are authoritative for split tenders. The LEFT JOIN keeps
            // historical transactions created before payment lines were introduced.
            $paymentBuilder
                ->select('COALESCE(tp.payment_method, t.payment_method) AS payment_method, COALESCE(SUM(CASE WHEN tp.id IS NOT NULL THEN tp.amount ELSE t.amount END), 0) AS total_sales', false)
                ->join('transaction_payments tp', 'tp.transaction_id = t.id', 'left')
                ->groupBy('COALESCE(tp.payment_method, t.payment_method)', false);
        } else {
            $paymentBuilder
                ->select('t.payment_method AS payment_method, COALESCE(SUM(t.amount), 0) AS total_sales', false)
                ->groupBy('t.payment_method');
        }
        $dayBounds = ibems_business_day_utc_bounds($businessDate);
        $paymentRows = $paymentBuilder
            ->where('t.store_id', $storeId)->where('t.created_at >=', $dayBounds['start'])
            ->where('t.created_at <=', $dayBounds['end'])->get()->getResultArray();
        $cashSales = $ecashSales = $debtSales = 0.0;
        $paymentMethodSales = [];
        foreach ($paymentRows as $row) {
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            $amount = (float) ($row['total_sales'] ?? 0);
            if ($method === 'cash') $cashSales += $amount;
            elseif ($method === 'debt') $debtSales += $amount;
            else $ecashSales += $amount;
            $paymentMethodSales[$method] = ($paymentMethodSales[$method] ?? 0) + $amount;
        }

        $cashIn = $cashOut = $ecashIn = $ecashOut = $cashDebtPayments = $ecashDebtPayments = 0.0;
        $collectionByMethod = [];
        $collectionByAccount = [];
        $movements = $db->table('store_cash_movements')->select('channel, movement_type, amount, reason')
            ->where('store_id', $storeId)->where('business_date', $businessDate)->get()->getResultArray();
        foreach ($movements as $row) {
            $ecash = strtolower((string) ($row['channel'] ?? 'cash')) === 'ecash';
            $out = strtolower((string) ($row['movement_type'] ?? 'cash_in')) === 'cash_out';
            $amount = (float) ($row['amount'] ?? 0);
            $debtPayment = stripos((string) ($row['reason'] ?? ''), 'Debt repayment') === 0;
            if ($debtPayment) {
                $collectionMethod = $ecash ? 'electronic' : 'cash';
                if (preg_match('/(?:^|\|)\s*Method:\s*([a-z0-9_-]+)/i', (string) ($row['reason'] ?? ''), $matches)) {
                    $collectionMethod = strtolower($matches[1]);
                }
                $collectionByMethod[$collectionMethod] = ($collectionByMethod[$collectionMethod] ?? 0) + $amount;
                if (preg_match('/(?:^|\|)\s*Account:\s*(\d+)/i', (string) ($row['reason'] ?? ''), $accountMatches)) {
                    $accountId = (int) $accountMatches[1];
                    $collectionByAccount[$accountId] = ($collectionByAccount[$accountId] ?? 0) + $amount;
                }
            }
            if ($ecash) {
                if ($out) $ecashOut += $amount; else { $ecashIn += $amount; if ($debtPayment) $ecashDebtPayments += $amount; }
            } else {
                if ($out) $cashOut += $amount; else { $cashIn += $amount; if ($debtPayment) $cashDebtPayments += $amount; }
            }
        }

        $expectedCash = (float) ($session['opening_cash'] ?? 0) + $cashSales + $cashIn - $cashOut;
        $expectedEcash = (float) ($session['opening_ecash'] ?? 0) + $ecashSales + $ecashIn - $ecashOut;
        $accountBalances = [];
        $accountSalesByMethod = [];
        $accountOpeningTotal = 0.0;
        if ($db->tableExists('payment_destination_accounts') && $db->tableExists('transaction_payments')) {
            $accountRows = $db->table('payment_destination_accounts a')
                ->select('a.id, a.account_name, a.account_number, m.code AS payment_method, m.label AS payment_method_label, a.image_url, COALESCE(SUM(CASE WHEN t.id IS NOT NULL THEN tp.amount ELSE 0 END), 0) AS sales', false)
                ->join('store_payment_methods m', 'm.id = a.payment_method_id')
                ->join('transaction_payments tp', 'tp.destination_account_id = a.id', 'left')
                ->join('transactions t', "t.id = tp.transaction_id AND t.store_id = " . $db->escape($storeId) . " AND t.created_at >= " . $db->escape($dayBounds['start']) . " AND t.created_at <= " . $db->escape($dayBounds['end']), 'left', false)
                ->where('a.store_id', $storeId)->where('a.is_active', true)
                ->groupBy('a.id, a.account_name, a.account_number, m.code, m.label, m.sort_order, a.image_url')->orderBy('m.sort_order', 'ASC')->get()->getResultArray();
            $openingByAccount = [];
            if ($db->tableExists('store_day_payment_account_balances') && (int) ($session['id'] ?? 0) > 0) {
                foreach ($db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) $session['id'])->get()->getResultArray() as $balanceRow) {
                    $openingByAccount[(int) $balanceRow['destination_account_id']] = (float) ($balanceRow['opening_balance'] ?? 0);
                }
            }
            foreach ($accountRows as $row) { $accountId = (int) $row['id']; $opening = (float) ($openingByAccount[$accountId] ?? 0); $method = (string) $row['payment_method']; $sales = (float) ($row['sales'] ?? 0); $collections = (float) ($collectionByAccount[$accountId] ?? 0); $accountOpeningTotal += $opening; $accountSalesByMethod[$method] = ($accountSalesByMethod[$method] ?? 0) + $sales; $accountBalances[] = [
                'id' => (int) $row['id'], 'account_name' => (string) $row['account_name'], 'account_number' => (string) $row['account_number'],
                'payment_method' => $method, 'payment_method_label' => (string) $row['payment_method_label'],
                'image_url' => (string) ($row['image_url'] ?? ''), 'sales' => $sales, 'collections' => $collections,
                'opening_balance' => $opening, 'expected_balance' => $opening + $sales + $collections,
            ]; }
        }
        $methodLabels = [];
        if ($db->tableExists('store_payment_methods')) {
            foreach ($db->table('store_payment_methods')->select('code, label')->where('store_id', $storeId)->get()->getResultArray() as $methodRow) {
                $methodLabels[(string) $methodRow['code']] = (string) $methodRow['label'];
            }
        }
        $salesBreakdown = [];
        foreach ($paymentMethodSales as $method => $amount) {
            $salesBreakdown[] = [
                'payment_method' => $method,
                'payment_method_label' => $methodLabels[$method] ?? ucfirst(str_replace('_', ' ', $method)),
                'amount' => $amount,
                'is_collected' => $method !== 'debt',
            ];
        }
        $collectionBreakdown = [];
        foreach ($collectionByMethod as $method => $amount) {
            $collectionBreakdown[] = [
                'payment_method' => $method,
                'payment_method_label' => $method === 'electronic' ? 'Legacy electronic' : ($methodLabels[$method] ?? ucfirst(str_replace('_', ' ', $method))),
                'amount' => $amount,
            ];
        }
        $unassignedBalances = [];
        foreach ($paymentRows as $row) {
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            if (in_array($method, ['cash', 'debt'], true)) continue;
            $unassignedSales = max(0, (float) ($row['total_sales'] ?? 0) - (float) ($accountSalesByMethod[$method] ?? 0));
            if ($unassignedSales <= 0.004) continue;
            $unassignedBalances[] = ['key' => 'method:' . $method, 'payment_method' => $method,
                'payment_method_label' => $methodLabels[$method] ?? ucfirst($method), 'opening_balance' => 0.0,
                'sales' => $unassignedSales, 'expected_balance' => $unassignedSales];
        }
        $legacyOpening = max(0, (float) ($session['opening_ecash'] ?? 0) - $accountOpeningTotal);
        $assignedCollections = array_sum($collectionByAccount);
        $unassignedAdjustments = $legacyOpening + $ecashIn - $ecashOut - $assignedCollections;
        if (abs($unassignedAdjustments) > 0.004) {
            $unassignedBalances[] = ['key' => 'legacy:electronic', 'payment_method' => 'electronic_adjustments',
                'payment_method_label' => 'Unassigned electronic adjustments', 'opening_balance' => $legacyOpening,
                'sales' => $ecashIn - $ecashOut, 'expected_balance' => $unassignedAdjustments];
        }
        return [
            'cash_sales' => $cashSales, 'ecash_sales' => $ecashSales, 'debt_sales' => $debtSales,
            'cash_debt_payments' => $cashDebtPayments, 'ecash_debt_payments' => $ecashDebtPayments,
            'cash_in' => $cashIn, 'cash_out' => $cashOut, 'ecash_in' => $ecashIn, 'ecash_out' => $ecashOut,
            'expected_cash_on_hand' => $expectedCash, 'expected_ecash_on_hand' => $expectedEcash,
            'expected_total_on_hand' => $expectedCash + $expectedEcash,
            'payment_account_balances' => $accountBalances,
            'unassigned_payment_balances' => $unassignedBalances,
            'payment_method_sales' => $salesBreakdown,
            'payment_method_collections' => $collectionBreakdown,
        ];
    }
}
