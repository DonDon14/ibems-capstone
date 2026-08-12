<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class IbemsDataAudit extends BaseCommand
{
    protected $group       = 'IBEMS';
    protected $name        = 'ibems:data-audit';
    protected $description = 'Audit IBEMS data integrity for balances, inventory, transactions, and store mappings.';
    protected $usage       = 'ibems:data-audit';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params): void
    {
        $errors = 0;
        $warnings = 0;

        CLI::write('IBEMS Data Integrity Audit', 'yellow');
        CLI::newLine();

        try {
            $db = Database::connect();
            $db->initialize();
            CLI::write('[OK] Database connection established', 'green');
        } catch (\Throwable $e) {
            CLI::write('[FAIL] Database connection failed: ' . $e->getMessage(), 'red');
            exit(1);
        }

        // 1) Balances integrity
        $negativeDebt = (int) $db->table('balances')->where('current_debt <', 0)->countAllResults();
        if ($negativeDebt > 0) {
            CLI::write("[FAIL] balances with negative current_debt: {$negativeDebt}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] No balances with negative current_debt', 'green');
        }

        $negativeLimit = (int) $db->table('balances')->where('credit_limit <', 0)->countAllResults();
        if ($negativeLimit > 0) {
            CLI::write("[FAIL] balances with negative credit_limit: {$negativeLimit}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] No balances with negative credit_limit', 'green');
        }

        $debtOverLimit = (int) $db->table('balances')->where('current_debt > credit_limit')->countAllResults();
        if ($debtOverLimit > 0) {
            CLI::write("[WARN] balances where current_debt exceeds credit_limit: {$debtOverLimit}", 'light_yellow');
            $warnings++;
        } else {
            CLI::write('[OK] No balances exceeding credit_limit', 'green');
        }

        // 2) Products / inventory integrity
        $negativeStock = (int) $db->table('products')->where('stock_qty <', 0)->countAllResults();
        if ($negativeStock > 0) {
            CLI::write("[FAIL] products with negative stock_qty: {$negativeStock}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] No products with negative stock_qty', 'green');
        }

        $missingProductCategory = (int) $db->table('products')
            ->groupStart()
            ->where('category IS NULL', null, false)
            ->orWhere('category', '')
            ->groupEnd()
            ->countAllResults();
        if ($missingProductCategory > 0) {
            CLI::write("[WARN] products with empty category: {$missingProductCategory}", 'light_yellow');
            $warnings++;
        } else {
            CLI::write('[OK] All products have category values', 'green');
        }

        // 3) Transactions integrity
        $txnWithoutItems = (int) $db->query(
            'SELECT COUNT(*) AS c FROM transactions t LEFT JOIN transaction_items ti ON ti.transaction_id = t.id WHERE ti.id IS NULL'
        )->getRow('c');
        if ($txnWithoutItems > 0) {
            CLI::write("[FAIL] transactions without transaction_items: {$txnWithoutItems}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] Every transaction has at least one item', 'green');
        }

        $invalidItemQty = (int) $db->table('transaction_items')->where('qty <=', 0)->countAllResults();
        if ($invalidItemQty > 0) {
            CLI::write("[FAIL] transaction_items with qty <= 0: {$invalidItemQty}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] transaction_items qty are valid', 'green');
        }

        $invalidItemPrice = (int) $db->table('transaction_items')->where('unit_price <', 0)->countAllResults();
        if ($invalidItemPrice > 0) {
            CLI::write("[FAIL] transaction_items with negative unit_price: {$invalidItemPrice}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] transaction_items unit_price are non-negative', 'green');
        }

        $txnMismatchCount = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM (
                 SELECT t.id, t.amount AS txn_amount, COALESCE(SUM(ti.line_total), 0) AS items_total
                 FROM transactions t
                 LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
                 GROUP BY t.id, t.amount
             ) x
             WHERE ABS(x.txn_amount - x.items_total) > 0.01'
        )->getRow('c');
        if ($txnMismatchCount > 0) {
            CLI::write("[WARN] transactions with amount mismatch vs items total: {$txnMismatchCount}", 'light_yellow');
            $warnings++;
        } else {
            CLI::write('[OK] transaction amount matches summed line totals', 'green');
        }

        $debtWithoutUser = (int) $db->table('transactions')
            ->where('LOWER(payment_method)', 'debt')
            ->where('user_id IS NULL', null, false)
            ->countAllResults();
        if ($debtWithoutUser > 0) {
            CLI::write("[FAIL] debt transactions with null user_id: {$debtWithoutUser}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] debt transactions always have user_id', 'green');
        }

        // 4) Store mapping integrity
        $invalidStoreOfficers = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM stores s
             LEFT JOIN users u ON u.id = s.officer_id
             WHERE s.officer_id IS NOT NULL AND u.id IS NULL'
        )->getRow('c');
        if ($invalidStoreOfficers > 0) {
            CLI::write("[FAIL] stores with invalid officer mapping: {$invalidStoreOfficers}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] all assigned store officers reference valid users', 'green');
        }

        $orphanSupervisorMappings = $db->tableExists('store_supervisors')
            ? (int) $db->query(
                'SELECT COUNT(*) AS c
                 FROM store_supervisors ss
                 LEFT JOIN stores s ON s.id = ss.store_id
                 LEFT JOIN users u ON u.id = ss.user_id
                 WHERE s.id IS NULL OR u.id IS NULL'
            )->getRow('c')
            : -1;
        if ($orphanSupervisorMappings < 0) {
            CLI::write('[FAIL] store_supervisors table is missing', 'red');
            $errors++;
        } elseif ($orphanSupervisorMappings > 0) {
            CLI::write("[FAIL] orphaned store supervisor mappings: {$orphanSupervisorMappings}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] all store supervisor mappings are valid', 'green');
        }

        $orphanPayments = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM transactions t
             LEFT JOIN stores s ON s.id = t.store_id
             WHERE s.id IS NULL'
        )->getRow('c');
        if ($orphanPayments > 0) {
            CLI::write("[FAIL] transactions with invalid store_id: {$orphanPayments}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] all transactions reference valid stores', 'green');
        }

        // 5) Governed debt workflow integrity
        $invalidDeductionResults = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM deduction_batch_items
             WHERE requested_amount < 0
                OR confirmed_amount < 0
                OR confirmed_amount > requested_amount
                OR carryover_amount < 0'
        )->getRow('c');
        if ($invalidDeductionResults > 0) {
            CLI::write("[FAIL] invalid deduction batch result amounts: {$invalidDeductionResults}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] deduction result amounts are valid', 'green');
        }

        $finalizedWithPendingItems = (int) $db->query(
            "SELECT COUNT(*) AS c
             FROM deduction_batches db
             JOIN deduction_batch_items dbi ON dbi.batch_id = db.id
             WHERE db.status = 'finalized' AND dbi.result_status = 'pending'"
        )->getRow('c');
        if ($finalizedWithPendingItems > 0) {
            CLI::write("[FAIL] finalized deduction batches with pending results: {$finalizedWithPendingItems}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] finalized deduction batches have no pending results', 'green');
        }

        $selfApprovedInvestigations = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM debt_investigations
             WHERE approved_by IS NOT NULL AND approved_by = recommended_by'
        )->getRow('c');
        if ($selfApprovedInvestigations > 0) {
            CLI::write("[FAIL] self-approved debt investigations: {$selfApprovedInvestigations}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] debt investigation approvals are independently assigned', 'green');
        }

        $closedReversalsWithoutLedger = (int) $db->query(
            "SELECT COUNT(*) AS c
             FROM debt_investigations
             WHERE status = 'closed'
               AND recommended_action IN ('partial_reversal', 'full_reversal')
               AND (recommended_amount > 0 AND reversal_cashbook_entry_id IS NULL)"
        )->getRow('c');
        if ($closedReversalsWithoutLedger > 0) {
            CLI::write("[FAIL] closed debt reversals without linked cashbook entries: {$closedReversalsWithoutLedger}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] closed debt reversals link to cashbook entries', 'green');
        }

        $invalidPinAttemptState = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM debt_pin_security
             WHERE failed_attempts < 0 OR failed_attempts > 5'
        )->getRow('c');
        if ($invalidPinAttemptState > 0) {
            CLI::write("[FAIL] invalid debt PIN attempt state rows: {$invalidPinAttemptState}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] debt PIN attempt state is within policy limits', 'green');
        }

        $varianceWithoutCase = (int) $db->query(
            "SELECT COUNT(*) AS c
             FROM store_day_sessions sds
             LEFT JOIN store_day_variance_cases c ON c.store_day_session_id = sds.id
             WHERE sds.review_status <> 'not_required' AND c.id IS NULL"
        )->getRow('c');
        if ($varianceWithoutCase > 0) {
            CLI::write("[FAIL] reviewable store-day variances without cases: {$varianceWithoutCase}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] every reviewable store-day variance has a case', 'green');
        }

        $orphanCaseEvents = (int) $db->query(
            'SELECT COUNT(*) AS c
             FROM store_day_variance_case_events e
             LEFT JOIN store_day_variance_cases c ON c.id = e.case_id
             WHERE c.id IS NULL'
        )->getRow('c');
        if ($orphanCaseEvents > 0) {
            CLI::write("[FAIL] orphaned store-day variance case events: {$orphanCaseEvents}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] store-day variance case events have valid cases', 'green');
        }

        $invalidResolvedCases = (int) $db->query(
            "SELECT COUNT(*) AS c
             FROM store_day_variance_cases c
             JOIN store_day_sessions sds ON sds.id = c.store_day_session_id
             WHERE c.status = 'resolved'
               AND (c.resolved_by IS NULL OR c.resolved_at IS NULL OR c.disposition IS NULL
                    OR sds.review_status NOT IN ('approved', 'waived', 'corrected'))"
        )->getRow('c');
        if ($invalidResolvedCases > 0) {
            CLI::write("[FAIL] resolved variance cases with incomplete disposition: {$invalidResolvedCases}", 'red');
            $errors++;
        } else {
            CLI::write('[OK] resolved variance cases have complete dispositions', 'green');
        }

        $orphanEvidence = (int) $db->query('SELECT COUNT(*) AS c FROM store_day_variance_case_attachments a LEFT JOIN store_day_variance_cases c ON c.id = a.case_id LEFT JOIN users u ON u.id = a.uploaded_by WHERE c.id IS NULL OR u.id IS NULL')->getRow('c');
        $orphanHandoffs = (int) $db->query('SELECT COUNT(*) AS c FROM store_day_variance_case_handoffs h LEFT JOIN store_day_variance_cases c ON c.id = h.case_id LEFT JOIN users sender ON sender.id = h.from_user_id LEFT JOIN users recipient ON recipient.id = h.to_user_id WHERE c.id IS NULL OR sender.id IS NULL OR recipient.id IS NULL')->getRow('c');
        if ($orphanEvidence + $orphanHandoffs > 0) {
            CLI::write('[FAIL] orphaned variance evidence or handoff records: ' . ($orphanEvidence + $orphanHandoffs), 'red');
            $errors++;
        } else {
            CLI::write('[OK] variance evidence and handoffs have valid ownership', 'green');
        }

        CLI::newLine();
        CLI::write("Warnings: {$warnings}", $warnings > 0 ? 'light_yellow' : 'green');
        CLI::write("Errors: {$errors}", $errors > 0 ? 'red' : 'green');
        CLI::newLine();

        if ($errors > 0) {
            CLI::write('Data audit failed. Resolve critical data issues before deployment.', 'red');
            exit(1);
        }

        CLI::write('Data audit passed.', 'green');
    }
}

