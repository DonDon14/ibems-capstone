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

    private function resetSchema(): void
    {
        $db = Database::connect();
        $tn = static fn (string $name): string => $db->getPrefix() . $name;
        foreach (['debt_investigations', 'debt_cashbook_entries', 'audit_logs', 'balances', 'transactions', 'users'] as $table) {
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
    }
}
