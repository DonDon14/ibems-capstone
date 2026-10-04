<?php

use App\Services\DebtInvestigationService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class DebtInvestigationServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
    }

    public function testInvestigatorCannotApproveOwnRecommendation(): void
    {
        $service = new DebtInvestigationService();
        $opened = $service->open([
            'user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'incorrect_amount',
            'summary' => 'Receipt amount does not match the items received.',
        ], 900);
        $id = (int) $opened['investigation']['id'];
        $recommended = $service->recommend($id, [
            'recommended_action' => 'partial_reversal', 'recommended_amount' => 50,
            'findings' => 'One item was charged twice on the source receipt.',
            'evidence_summary' => 'Receipt and inventory movement were compared.',
        ], 900);
        $approved = $service->approveAndPost($id, 900);

        $this->assertSame('success', $recommended['status']);
        $this->assertSame(403, $approved['code']);
        $this->assertSame(200.0, (float) Database::connect()->table('balances')->where('user_id', 501)->get()->getRow()->current_debt);
        $this->assertSame(0, Database::connect()->table('debt_cashbook_entries')->countAllResults());
    }

    public function testDifferentAccountingUserPostsAppendOnlyPartialReversal(): void
    {
        $service = new DebtInvestigationService();
        $opened = $service->open([
            'user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'duplicate_charge',
            'summary' => 'The employee reported a duplicate item charge.',
        ], 900);
        $id = (int) $opened['investigation']['id'];
        $service->recommend($id, [
            'recommended_action' => 'partial_reversal', 'recommended_amount' => 50,
            'findings' => 'The receipt confirms a duplicated fifty-peso line.',
            'evidence_summary' => 'Receipt, transaction items, and employee statement reviewed.',
        ], 900);

        $result = $service->approveAndPost($id, 901);

        $this->assertSame('success', $result['status']);
        $this->assertSame(200.0, (float) $result['debt_before']);
        $this->assertSame(150.0, (float) $result['debt_after']);
        $db = Database::connect();
        $entry = $db->table('debt_cashbook_entries')->get()->getRowArray();
        $investigation = $db->table('debt_investigations')->where('id', $id)->get()->getRowArray();
        $transaction = $db->table('transactions')->where('id', 10)->get()->getRowArray();
        $this->assertSame('investigation_reversal', $entry['entry_type']);
        $this->assertSame($id, (int) $entry['reference_id']);
        $this->assertSame('closed', $investigation['status']);
        $this->assertSame(901, (int) $investigation['approved_by']);
        $this->assertSame('completed', $transaction['status']);
        $this->assertSame(1, $db->table('transactions')->countAllResults());
    }

    public function testReversalCannotExceedSourceTransactionOrCurrentDebt(): void
    {
        $service = new DebtInvestigationService();
        $opened = $service->open([
            'user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'incorrect_amount',
            'summary' => 'The entire transaction is under investigation.',
        ], 900);
        $id = (int) $opened['investigation']['id'];
        $tooLarge = $service->recommend($id, [
            'recommended_action' => 'partial_reversal', 'recommended_amount' => 101,
            'findings' => 'The requested reversal exceeds the receipt total.',
            'evidence_summary' => 'Source transaction amount is one hundred pesos.',
        ], 900);

        $this->assertSame(400, $tooLarge['code']);
        $this->assertStringContainsString('cannot exceed', $tooLarge['message']);
    }

    public function testCumulativeSourceCeilingAndFinalizationRetry(): void
    {
        $service = new DebtInvestigationService();
        $first = $this->recommendAmount($service, 60);
        $second = $this->recommendAmount($service, 60);
        $this->assertSame('success', $service->approveAndPost($first, 901)['status']);
        $db = Database::connect();
        $before = $this->financialSnapshot();
        $this->assertSame(409, $service->approveAndPost($second, 901)['code']);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame(409, $service->approveAndPost($first, 901)['code']);
        $this->assertSame($before, $this->financialSnapshot());
        $last = $this->recommendAmount($service, 40);
        $this->assertSame('success', $service->approveAndPost($last, 901)['status']);
        $this->assertSame(100.0, (float) $db->table('balances')->get()->getRow()->current_debt);
        $this->assertSame(100.0, (float) $db->table('debt_cashbook_entries')->selectSum('amount')->get()->getRow()->amount);
    }

    public function testActualDebtPortionLimitsReceiptTotalAndNonfiniteAmounts(): void
    {
        $db = Database::connect();
        $db->table('debt_cashbook_entries')->insert(['user_id' => 501, 'entry_type' => 'debt_purchase', 'direction' => 'debit', 'amount' => 30, 'reference_type' => 'transaction', 'reference_id' => 10]);
        $service = new DebtInvestigationService();
        $id = $this->recommendAmount($service, 30);
        // A tampered or outdated recommendation is revalidated on approval.
        $db->table('debt_investigations')->where('id', $id)->update(['recommended_amount' => 31]);
        $this->assertSame(409, $service->approveAndPost($id, 901)['code']);
        $db->table('debt_investigations')->where('id', $id)->update(['recommended_amount' => 30]);
        $this->assertSame('success', $service->approveAndPost($id, 901)['status']);
        foreach ([INF, NAN] as $amount) {
            $opened = $service->open(['user_id' => 501, 'issue_type' => 'other', 'summary' => 'Review a reported account discrepancy.'], 900);
            $result = $service->recommend((int) $opened['investigation']['id'], ['recommended_action' => 'partial_reversal', 'recommended_amount' => $amount, 'findings' => 'Verified financial discrepancy details.', 'evidence_summary' => 'Synthetic evidence'], 900);
            $this->assertSame(400, $result['code']);
        }
    }

    public function testHistoricalDuplicatePostingsAllConsumeSourceCapacity(): void
    {
        $service = new DebtInvestigationService();
        $prior = $this->recommendAmount($service, 60);
        $db = Database::connect();
        foreach ([60, 40] as $amount) $db->table('debt_cashbook_entries')->insert(['user_id' => 501, 'entry_type' => 'investigation_reversal', 'direction' => 'credit', 'amount' => $amount, 'reference_type' => 'debt_investigation', 'reference_id' => $prior]);
        $db->table('debt_investigations')->where('id', $prior)->update(['status' => 'closed', 'reversal_cashbook_entry_id' => $db->insertID()]);
        $next = $this->recommendAmount($service, 0.01);
        $before = $this->financialSnapshot();
        $this->assertSame(409, $service->approveAndPost($next, 901)['code']);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function testAuditFailureRollsBackPostingAndAllowsRetry(): void
    {
        $service = new DebtInvestigationService();
        $id = $this->recommendAmount($service, 25);
        $db = Database::connect();
        $before = $this->financialSnapshot();
        $db->query("CREATE TRIGGER fail_reversal_audit BEFORE INSERT ON " . $db->prefixTable('audit_logs') . " WHEN NEW.action = 'APPROVE_AND_POST_DEBT_REVERSAL' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->assertSame(500, $service->approveAndPost($id, 901)['code']);
        $this->assertSame($before, $this->financialSnapshot());
        $db->query('DROP TRIGGER fail_reversal_audit');
        $db->resetTransStatus();
        $this->assertSame('success', $service->approveAndPost($id, 901)['status']);
    }

    public function testPostedCaseCannotPostAgainEvenIfStatusIsStale(): void
    {
        $service = new DebtInvestigationService();
        $id = $this->recommendAmount($service, 20);
        $this->assertSame('success', $service->approveAndPost($id, 901)['status']);
        Database::connect()->table('debt_investigations')->where('id', $id)->update(['status' => 'recommended']);
        $before = $this->financialSnapshot();
        $this->assertSame(409, $service->approveAndPost($id, 901)['code']);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function testSourceOptionalAndNoChangeCasesRemainSupported(): void
    {
        $service = new DebtInvestigationService();
        foreach (['partial_reversal' => 10, 'no_change' => 0] as $action => $amount) {
            $opened = $service->open(['user_id' => 501, 'issue_type' => 'other', 'summary' => 'Review synthetic opening account discrepancy.'], 900);
            $id = (int) $opened['investigation']['id'];
            $this->assertSame('success', $service->recommend($id, ['recommended_action' => $action, 'recommended_amount' => $amount, 'findings' => 'Verified financial discrepancy details.', 'evidence_summary' => 'Synthetic evidence'], 900)['status']);
            $this->assertSame('success', $service->approveAndPost($id, 901)['status']);
            $this->assertSame(409, $service->approveAndPost($id, 901)['code']);
        }
        $this->assertSame(190.0, (float) Database::connect()->table('balances')->get()->getRow()->current_debt);
        $this->assertSame(1, Database::connect()->table('debt_cashbook_entries')->countAllResults());
    }

    public function testSplitChargeCanBeInvestigatedOnlyUpToItsDebtPortion(): void
    {
        $db = Database::connect();
        $db->table('transactions')->where('id', 10)->update(['payment_method' => 'split']);
        $db->query('ALTER TABLE ' . $db->prefixTable('users') . ' ADD COLUMN employee_id TEXT');
        $db->query('ALTER TABLE ' . $db->prefixTable('users') . ' ADD COLUMN name TEXT');
        foreach (['client_txn_id TEXT', 'store_id INTEGER', 'created_at TEXT'] as $column) {
            $db->query('ALTER TABLE ' . $db->prefixTable('transactions') . ' ADD COLUMN ' . $column);
        }
        $db->query('CREATE TABLE ' . $db->prefixTable('transaction_payments') . ' (id INTEGER PRIMARY KEY, transaction_id INTEGER, payment_method TEXT, amount REAL)');
        $db->table('transaction_payments')->insertBatch([
            ['transaction_id' => 10, 'payment_method' => 'cash', 'amount' => 60],
            ['transaction_id' => 10, 'payment_method' => 'debt', 'amount' => 40],
        ]);
        $service = new DebtInvestigationService();
        $this->assertCount(1, $service->data(501)['transactions']);
        $this->assertSame(40.0, (float) $service->data(501)['transactions'][0]['amount']);
        $opened = $service->open(['user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'incorrect_amount', 'summary' => 'Investigate the debt portion of a split receipt.'], 900);
        $this->assertSame('success', $opened['status']);
        $id = (int) $opened['investigation']['id'];
        $input = ['recommended_action' => 'partial_reversal', 'recommended_amount' => 41, 'findings' => 'Review debt tender against the employee receipt.', 'evidence_summary' => 'Synthetic receipt'];
        $this->assertSame(400, $service->recommend($id, $input, 900)['code']);
        $input['recommended_amount'] = 40;
        $this->assertSame('success', $service->recommend($id, $input, 900)['status']);
        $this->assertSame('success', $service->approveAndPost($id, 901)['status']);
        $this->assertSame(160.0, (float) $db->table('balances')->get()->getRow()->current_debt);
        $this->assertSame(100.0, (float) $db->table('transactions')->where('id', 10)->get()->getRow()->amount);
        $db->query('DROP TABLE ' . $db->prefixTable('transaction_payments'));
    }

    public function testOpeningAuditFailurePreservesTheInvestigationState(): void
    {
        $db = Database::connect();
        $before = $this->financialSnapshot();
        $db->query("CREATE TRIGGER fail_open_audit BEFORE INSERT ON " . $db->prefixTable('audit_logs') . " WHEN NEW.action = 'OPEN_DEBT_INVESTIGATION' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $result = (new DebtInvestigationService())->open(['user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'other', 'summary' => 'Review a synthetic financial discrepancy.'], 900);
        $this->assertSame(500, $result['code']);
        $this->assertSame($before, $this->financialSnapshot());
        $db->query('DROP TRIGGER fail_open_audit');
        $db->resetTransStatus();
        $this->assertSame('success', (new DebtInvestigationService())->open(['user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'other', 'summary' => 'Review a synthetic financial discrepancy.'], 900)['status']);
    }

    public function testRecommendationRejectsMalformedMoneyAndRollsBackAuditFailure(): void
    {
        $db = Database::connect();
        $service = new DebtInvestigationService();
        $opened = $service->open(['user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'other', 'summary' => 'Review a synthetic financial discrepancy.'], 900);
        $id = (int) $opened['investigation']['id'];
        $input = ['recommended_action' => 'partial_reversal', 'findings' => 'Verified source receipt discrepancy details.', 'evidence_summary' => 'Synthetic evidence'];
        $before = $this->financialSnapshot();
        foreach (['10junk', '1e1', '10.005', [], true, INF, -10] as $amount) {
            $this->assertSame(400, $service->recommend($id, $input + ['recommended_amount' => $amount], 900)['code']);
            $this->assertSame($before, $this->financialSnapshot());
        }
        $db->query("CREATE TRIGGER fail_recommend_audit BEFORE INSERT ON " . $db->prefixTable('audit_logs') . " WHEN NEW.action = 'RECOMMEND_DEBT_INVESTIGATION' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->assertSame(500, $service->recommend($id, $input + ['recommended_amount' => '10.00'], 900)['code']);
        $this->assertSame($before, $this->financialSnapshot());
        $db->query('DROP TRIGGER fail_recommend_audit');
        $db->resetTransStatus();
        $this->assertSame('success', $service->recommend($id, $input + ['recommended_amount' => '10.00'], 900)['status']);
        $this->assertSame(409, $service->recommend($id, $input + ['recommended_amount' => '10.00'], 900)['code']);
    }

    private function recommendAmount(DebtInvestigationService $service, float $amount): int
    {
        $opened = $service->open(['user_id' => 501, 'transaction_id' => 10, 'issue_type' => 'duplicate_charge', 'summary' => 'Review a reported duplicate purchase charge.'], 900);
        $id = (int) $opened['investigation']['id'];
        $result = $service->recommend($id, ['recommended_action' => 'partial_reversal', 'recommended_amount' => $amount, 'findings' => 'Verified financial discrepancy details.', 'evidence_summary' => 'Synthetic evidence'], 900);
        $this->assertSame('success', $result['status']);
        return $id;
    }

    private function financialSnapshot(): array
    {
        $db = Database::connect();
        return array_map(static fn ($table) => $db->table($table)->get()->getResultArray(), ['balances', 'debt_investigations', 'debt_cashbook_entries', 'audit_logs']);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $tn = static fn (string $name): string => $db->getPrefix() . $name;
        foreach (['transaction_payments', 'debt_investigations', 'debt_cashbook_entries', 'audit_logs', 'balances', 'transactions', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('users') . ' (id INTEGER PRIMARY KEY)');
        $db->query('CREATE TABLE ' . $tn('transactions') . ' (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL, payment_method TEXT, status TEXT)');
        $db->query('CREATE TABLE ' . $tn('balances') . ' (user_id INTEGER PRIMARY KEY, credit_limit REAL, current_debt REAL, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, payload_json TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, debt_before REAL, debt_after REAL, credit_limit_snapshot REAL, available_credit_snapshot REAL, reference_type TEXT, reference_id INTEGER, actor_id INTEGER, remarks TEXT, meta_json TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('debt_investigations') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, transaction_id INTEGER, status TEXT, issue_type TEXT, summary TEXT, evidence_summary TEXT, findings TEXT, recommended_action TEXT, recommended_amount REAL, rejection_reason TEXT, reversal_cashbook_entry_id INTEGER, opened_by INTEGER, investigator_id INTEGER, recommended_by INTEGER, approved_by INTEGER, posted_by INTEGER, recommended_at TEXT, approved_at TEXT, posted_at TEXT, closed_at TEXT, created_at TEXT, updated_at TEXT)');
        foreach ([501, 900, 901] as $id) {
            $db->table('users')->insert(['id' => $id]);
        }
        $db->table('transactions')->insert(['id' => 10, 'user_id' => 501, 'amount' => 100, 'payment_method' => 'debt', 'status' => 'completed']);
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 500, 'current_debt' => 200]);
        // Raw schema rebuilds must not retain table names from a previous fixture.
        $db->resetDataCache();
    }
}
