<?php

use App\Services\DailyStoreReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/** @internal */
final class DailyStoreReportServiceTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $tn = static fn (string $table): string => $db->getPrefix() . $table;
        foreach (['daily_store_report_events', 'daily_store_reports', 'transaction_payments', 'transactions', 'store_day_sessions', 'store_supervisors', 'stores'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('stores') . ' (id INTEGER PRIMARY KEY, store_name TEXT, officer_id INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('store_supervisors') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, user_id INTEGER)');
        $db->query('CREATE TABLE ' . $tn('store_day_sessions') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, business_date TEXT, status TEXT, closed_at TEXT, closed_by INTEGER, expected_cash REAL, expected_ecash REAL, counted_cash REAL, counted_ecash REAL, variance_cash REAL, variance_ecash REAL, review_status TEXT)');
        $db->query('CREATE TABLE ' . $tn('transactions') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, amount REAL, payment_method TEXT, status TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('transaction_payments') . ' (id INTEGER PRIMARY KEY, transaction_id INTEGER, payment_method TEXT, amount REAL)');
        $db->query('CREATE TABLE ' . $tn('daily_store_reports') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, store_day_session_id INTEGER, store_id INTEGER, business_date TEXT, revision INTEGER, status TEXT, transaction_count INTEGER, sales_total REAL, cash_total REAL, debt_total REAL, other_total REAL, source_json TEXT, submission_note TEXT, submitted_by INTEGER, submitted_at TEXT, be_reviewed_by INTEGER, be_reviewed_at TEXT, be_review_note TEXT, cashier_received_by INTEGER, cashier_received_at TEXT, accounting_furnished_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(store_id,business_date,revision))');
        $db->query('CREATE TABLE ' . $tn('daily_store_report_events') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, report_id INTEGER, event_type TEXT, actor_id INTEGER, note TEXT, created_at TEXT)');
        $db->table('stores')->insert(['id' => 1, 'store_name' => 'Main Store', 'officer_id' => 12, 'is_active' => 1]);
        $db->table('store_supervisors')->insert(['id' => 1, 'store_id' => 1, 'user_id' => 20]);
        $db->table('store_day_sessions')->insert(['id' => 5, 'store_id' => 1, 'business_date' => '2026-09-23', 'status' => 'closed', 'closed_at' => '2026-09-23 10:00:00', 'closed_by' => 12, 'expected_cash' => 160, 'counted_cash' => 160, 'review_status' => 'not_required']);
        $db->table('transactions')->insertBatch([
            ['id' => 1, 'store_id' => 1, 'amount' => 100, 'payment_method' => 'cash', 'status' => 'completed', 'created_at' => '2026-09-23 01:00:00'],
            ['id' => 2, 'store_id' => 1, 'amount' => 100, 'payment_method' => 'cash', 'status' => 'completed', 'created_at' => '2026-09-23 02:00:00'],
            ['id' => 3, 'store_id' => 1, 'amount' => 50, 'payment_method' => 'debt', 'status' => 'pending', 'created_at' => '2026-09-23 03:00:00'],
        ]);
        $db->table('transaction_payments')->insertBatch([
            ['id' => 1, 'transaction_id' => 2, 'payment_method' => 'cash', 'amount' => 60],
            ['id' => 2, 'transaction_id' => 2, 'payment_method' => 'debt', 'amount' => 40],
        ]);
    }

    public static function paginatedOfficeAudiences(): array
    {
        return [
            'BE' => ['beInbox', 'BE_DIRECTOR', 112],
            'Cashier' => ['cashierInbox', 'CASHIER_HEAD', 102],
            'Accounting' => ['accountingInbox', 'ACCOUNTING_HEAD', 102],
        ];
    }

    public static function reportAuditFailureStages(): array
    {
        return ['submission' => ['submit'], 'BE approval' => ['approve'],
            'BE return' => ['return'], 'Cashier receipt' => ['receive']];
    }

    public static function alteredTenderSnapshots(): array
    {
        $cases = [];
        foreach (['approve', 'receive'] as $stage) {
            foreach (['cash_total', 'debt_total', 'other_total'] as $field) {
                $cases[$stage . ' ' . $field] = [$stage, $field];
            }
        }
        return $cases;
    }

    public function testLegacyEvidenceStillVerifiesMixedTendersWithoutNewBucketKeys(): void
    {
        $db = Database::connect();
        $db->table('transaction_payments')->where('id', 1)->update(['amount' => 35]);
        $db->table('transaction_payments')->insert([
            'id' => 3, 'transaction_id' => 2, 'payment_method' => 'GCash', 'amount' => 25,
        ]);
        $service = new DailyStoreReportService($db);
        $created = $service->submit(5, 20, 'STORE_SUPERVISOR', 'Legacy mixed tenders');
        $this->assertSame('success', $created['status']);
        $report = $created['report'];
        $this->assertEquals(135, $report['cash_total']);
        $this->assertEquals(40, $report['debt_total']);
        $this->assertEquals(25, $report['other_total']);
        $source = json_decode($report['source_json'], true);
        unset($source['sales_evidence']['cash_cents'], $source['sales_evidence']['debt_cents'], $source['sales_evidence']['other_cents']);
        $report['source_json'] = json_encode($source);
        $this->assertSame('verified', $service->sourceStatus($report));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('alteredTenderSnapshots')]
    public function testHandoffRejectsAlteredTenderSnapshot(string $stage, string $field): void
    {
        $db = Database::connect();
        $service = new DailyStoreReportService($db);
        $created = $service->submit(5, 20, 'STORE_SUPERVISOR', 'Tender fixture');
        $this->assertSame('success', $created['status']);
        $id = (int) $created['report']['id'];
        if ($stage === 'receive') {
            $this->assertSame('success', $service->review($id, 30, true, 'Checked', 'BE_DIRECTOR')['status']);
        }
        $original = $service->find($id);
        // Preserve the grand total and source payments while reallocating tender buckets.
        $otherField = $field === 'cash_total' ? 'debt_total' : 'cash_total';
        $db->table('daily_store_reports')->where('id', $id)->update([
            $field => (float) $original[$field] + 1,
            $otherField => (float) $original[$otherField] - 1,
        ]);
        $altered = $service->find($id);
        $events = $db->table('daily_store_report_events')->orderBy('id')->get()->getResultArray();
        $this->assertSame('changed', $service->sourceStatus($altered));
        $result = $stage === 'approve'
            ? $service->review($id, 30, true, 'Checked', 'BE_DIRECTOR')
            : $service->receive($id, 40, 'CASHIER_HEAD');
        $this->assertSame(409, $result['code']);
        $this->assertSame($altered, $service->find($id));
        $this->assertSame($events, $db->table('daily_store_report_events')->orderBy('id')->get()->getResultArray());
        $db->table('daily_store_reports')->where('id', $id)->update([
            $field => $original[$field], $otherField => $original[$otherField],
        ]);
        $this->assertSame('verified', $service->sourceStatus($service->find($id)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportAuditFailureStages')]
    public function testReportAuditFailureRollsBackTransitionAndAllowsCleanRetry(string $stage): void
    {
        $db = Database::connect();
        $service = new DailyStoreReportService($db);
        $id = 0;
        if ($stage !== 'submit') {
            $created = $service->submit(5, 20, 'STORE_SUPERVISOR', 'Audit failure fixture');
            $this->assertSame('success', $created['status']);
            $id = (int) $created['report']['id'];
        }
        if ($stage === 'receive') {
            $this->assertSame('success', $service->review($id, 30, true, 'Checked', 'BE_DIRECTOR')['status']);
        }
        $state = static fn (): array => [
            $db->table('daily_store_reports')->orderBy('id')->get()->getResultArray(),
            $db->table('daily_store_report_events')->orderBy('id')->get()->getResultArray(),
            $db->table('transactions')->orderBy('id')->get()->getResultArray(),
            $db->table('transaction_payments')->orderBy('id')->get()->getResultArray(),
        ];
        $before = $state();
        $act = static fn (): array => match ($stage) {
            'submit' => $service->submit(5, 20, 'STORE_SUPERVISOR', 'Audit failure fixture'),
            'approve' => $service->review($id, 30, true, 'Checked', 'BE_DIRECTOR'),
            'return' => $service->review($id, 30, false, 'Needs correction', 'BE_DIRECTOR'),
            'receive' => $service->receive($id, 40, 'CASHIER_HEAD'),
        };
        $db->query('CREATE TRIGGER report_audit_failure BEFORE INSERT ON ' . $db->getPrefix()
            . "daily_store_report_events BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        try {
            $failed = $act();
            $this->assertSame('error', $failed['status']);
            $this->assertSame($before, $state(), 'A missing audit event must roll back the complete handoff transition.');
        } finally {
            $db->query('DROP TRIGGER report_audit_failure');
            // Model a fresh request after the deliberately failed strict transaction.
            $db->resetTransStatus();
        }
        $retry = $act();
        $this->assertSame('success', $retry['status'], json_encode($retry));
        $committed = $state();
        $this->assertCount(count($before[1]) + 1, $committed[1]);
        $this->assertSame($before[2], $committed[2]);
        $this->assertSame($before[3], $committed[3]);
        $this->assertSame(409, $act()['code']);
        $this->assertSame($committed, $state(), 'A repeated successful action must not alter timestamps or append another audit.');
    }

    public function testSupervisorHistoryUsesLatestRevisionPerDayAndPagesOnlyAssignedStoreDays(): void
    {
        $db = Database::connect();
        $lastDate = new DateTimeImmutable('2026-09-23');
        for ($index = 1; $index <= 60; $index++) {
            $date = $lastDate->modify('-' . $index . ' days')->format('Y-m-d');
            $db->table('store_day_sessions')->insert(['id' => 100 + $index, 'store_id' => 1,
                'business_date' => $date, 'status' => 'closed', 'closed_at' => $date . ' 04:00:00']);
        }
        $db->table('store_day_sessions')->insert(['id' => 100, 'store_id' => 1,
            'business_date' => '2026-09-23', 'status' => 'closed', 'closed_at' => '2026-09-23 04:00:00']);
        for ($revision = 1; $revision <= 101; $revision++) {
            $db->table('daily_store_reports')->insert(['store_day_session_id' => 100, 'store_id' => 1,
                'business_date' => '2026-09-23', 'revision' => $revision, 'status' => 'cashier_received',
                'transaction_count' => 2, 'sales_total' => 200, 'cash_total' => 160, 'debt_total' => 40]);
        }
        $db->table('daily_store_reports')->insert(['store_day_session_id' => 101, 'store_id' => 1,
            'business_date' => '2026-09-22', 'revision' => 1, 'status' => 'cashier_received',
            'transaction_count' => 1, 'sales_total' => 777, 'cash_total' => 777, 'debt_total' => 0]);
        $db->table('stores')->insert(['id' => 2, 'store_name' => 'Other store', 'officer_id' => 99, 'is_active' => 1]);
        $db->table('store_day_sessions')->insert(['id' => 999, 'store_id' => 2,
            'business_date' => '2026-09-23', 'status' => 'closed', 'closed_at' => '2026-09-23 04:00:00']);
        $db->table('daily_store_reports')->insert(['store_day_session_id' => 999, 'store_id' => 2,
            'business_date' => '2026-09-22', 'revision' => 999, 'status' => 'cashier_received',
            'transaction_count' => 1, 'sales_total' => 9999, 'cash_total' => 9999, 'debt_total' => 0]);
        $before = $db->table('daily_store_reports')->orderBy('id')->get()->getResultArray();
        $this->withRoutes([['GET', 'test/supervisor-history', 'DailyStoreReportController::storeInbox']]);
        $session = ['logged_in' => true, 'user_id' => 20, 'role' => 'STORE_SUPERVISOR',
            'available_roles' => ['STORE_SUPERVISOR']];
        $first = $this->withSession($session)->get('test/supervisor-history?store_id=1');
        $first->assertOK();
        $html = html_entity_decode((string) $first->getBody(), ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('Showing closed days 1–60 of 61', $html);
        $this->assertStringContainsString('store_id=1&page=2', $html);
        $this->assertStringContainsString('Revision 101', $html);
        $this->assertStringContainsString('Session #100', $html);
        $this->assertStringContainsString('Sales ₱777.00', $html, 'An older submitted day must not fall out behind newer revisions.');
        $this->assertStringNotContainsString('Revision 999', $html);
        $this->assertStringNotContainsString('Session #999', $html);
        $this->assertStringNotContainsString('Session #5', $html, 'Repeated sessions on one business date count as one day.');
        $second = $this->withSession($session)->get('test/supervisor-history?store_id=1&page=2');
        $second->assertOK();
        $this->assertStringContainsString($lastDate->modify('-60 days')->format('Y-m-d'), (string) $second->getBody());
        $this->assertStringContainsString('Showing closed days 61', (string) $second->getBody());
        $this->withSession($session)->get('test/supervisor-history?store_id=2&page=2')->assertRedirect();
        $this->withSession($session)->get('test/supervisor-history?store_id=1&page=bad')->assertStatus(400);
        $this->assertSame($before, $db->table('daily_store_reports')->orderBy('id')->get()->getResultArray());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paginatedOfficeAudiences')]
    public function testReportHistoryPagesRetainAudienceVisibilityAndReachEveryRecord(string $method, string $role, int $total): void
    {
        $db = Database::connect();
        for ($id = 1; $id <= 112; $id++) {
            $db->table('daily_store_reports')->insert([
                'id' => $id, 'store_day_session_id' => 5, 'store_id' => 1,
                'business_date' => '2026-09-23', 'revision' => $id,
                'status' => $id <= 102 ? 'cashier_received' : 'submitted',
                'transaction_count' => 2, 'sales_total' => 200, 'cash_total' => 160,
                'debt_total' => 40, 'other_total' => 0, 'source_json' => '{}',
                'submission_note' => 'PAGER-' . $id, 'submitted_at' => '2026-09-23 04:00:00',
                'accounting_furnished_at' => $id <= 102 ? '2026-09-23 04:00:00' : null,
                'cashier_received_at' => $id <= 102 ? '2026-09-23 04:00:00' : null,
            ]);
        }
        $before = $db->table('daily_store_reports')->orderBy('id')->get()->getResultArray();
        $this->withRoutes([['GET', 'test/report-pages', 'DailyStoreReportController::' . $method]]);
        $seen = [];
        for ($page = 1; $page <= 2; $page++) {
            $response = $this->withSession(['logged_in' => true, 'user_id' => 30,
                'role' => $role, 'available_roles' => [$role]])->get('test/report-pages?page=' . $page);
            $response->assertOK();
            $html = html_entity_decode((string) $response->getBody(), ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString('Page ' . $page . ' of 2', $html);
            $this->assertStringContainsString(' of ' . $total . ' · Page', $html);
            preg_match_all('/PAGER-(\d+)/', $html, $matches);
            $this->assertCount($page === 1 ? 100 : $total - 100, $matches[1]);
            array_push($seen, ...array_map('intval', $matches[1]));
        }
        $this->assertSame(range($total, 1), $seen, 'Every visible report appears exactly once in deterministic order.');
        foreach (['0', '-1', 'bad', '1.5'] as $invalid) {
            $this->get('test/report-pages?page=' . $invalid)->assertStatus(400);
        }
        $last = $this->get('test/report-pages?page=999999');
        $last->assertOK();
        $this->assertStringContainsString('Page 2 of 2', (string) $last->getBody());
        $this->assertSame($before, $db->table('daily_store_reports')->orderBy('id')->get()->getResultArray());
        $this->assertSame(0, $db->table('daily_store_report_events')->countAllResults());
    }

    public function testClosedDaySnapshotMovesThroughBeCashierAndAccountingCopy(): void
    {
        $service = new DailyStoreReportService();
        $submitted = $service->submit(5, 20, 'STORE_SUPERVISOR', 'Closing report');
        $this->assertSame('success', $submitted['status']);
        $report = $submitted['report'];
        $this->assertSame('submitted', $report['status']);
        $this->assertSame(2, (int) $report['transaction_count']);
        $this->assertSame(200.0, (float) $report['sales_total']);
        $this->assertSame(160.0, (float) $report['cash_total']);
        $this->assertSame(40.0, (float) $report['debt_total']);
        $evidence = json_decode((string) $report['source_json'], true, 512, JSON_THROW_ON_ERROR)['sales_evidence'];
        $this->assertSame(2, $evidence['transaction_count']);
        $this->assertSame(20000, $evidence['sales_cents']);
        $this->assertSame(20000, $evidence['payment_cents']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence['sha256']);

        $this->assertSame(409, $service->receive((int) $report['id'], 40, 'CASHIER_HEAD')['code']);
        $this->assertSame(403, $service->review((int) $report['id'], 20, true, '', 'BE_DIRECTOR')['code']);
        $approved = $service->review((int) $report['id'], 30, true, 'Checked against store close', 'BE_DIRECTOR');
        $this->assertSame('be_approved', $approved['report']['status']);
        $this->assertNotEmpty($approved['report']['accounting_furnished_at']);
        $received = $service->receive((int) $report['id'], 40, 'CASHIER_HEAD');
        $this->assertSame('cashier_received', $received['report']['status']);
        $this->assertSame(3, Database::connect()->table('daily_store_report_events')->countAllResults());
        $this->assertSame(409, $service->submit(5, 20, 'STORE_SUPERVISOR')['code']);
    }

    public function testClosedDayWithoutSalesCanStillBeReportedAndReviewed(): void
    {
        $db = Database::connect();
        $db->table('transaction_payments')->where('id >', 0)->delete();
        $db->table('transactions')->where('id >', 0)->delete();
        $db->table('store_day_sessions')->where('id', 5)->update(['expected_cash' => 0, 'counted_cash' => 0]);

        $service = new DailyStoreReportService();
        $submitted = $service->submit(5, 20, 'STORE_SUPERVISOR', 'No sales recorded');
        $this->assertSame('success', $submitted['status']);
        $report = $submitted['report'];
        $this->assertSame(0, (int) $report['transaction_count']);
        $this->assertSame(0.0, (float) $report['sales_total']);
        $this->assertSame('verified', $service->sourceStatus($report));
        $this->assertSame('be_approved', $service->review((int) $report['id'], 30, true, '', 'BE_DIRECTOR')['report']['status']);
        $this->assertSame('cashier_received', $service->receive((int) $report['id'], 40, 'CASHIER_HEAD')['report']['status']);
    }

    public function testPreviewUsesSubmissionReconciliationWithoutWritingAReport(): void
    {
        $service = new DailyStoreReportService();
        $preview = $service->preview(5, 20, 'STORE_SUPERVISOR');
        $this->assertSame('success', $preview['status']);
        $this->assertSame(2, $preview['totals']['transactions']);
        $this->assertSame(20000, $preview['totals']['sales_cents']);
        $this->assertSame(16000, $preview['totals']['cash_cents']);
        $this->assertSame(4000, $preview['totals']['debt_cents']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['evidence']['sha256']);
        $this->assertSame(0, Database::connect()->table('daily_store_reports')->countAllResults());
        $this->assertSame(403, $service->preview(5, 99, 'STORE_SUPERVISOR')['code']);
        $this->assertSame(403, $service->preview(5, 12, 'STORE_SYSTEM')['code']);

        Database::connect()->table('transaction_payments')->where('id', 1)->update(['amount' => 59]);
        $this->assertSame(409, $service->preview(5, 20, 'STORE_SUPERVISOR')['code']);
        $this->assertSame(0, Database::connect()->table('daily_store_reports')->countAllResults());
    }

    public function testReturnedReportRequiresNewImmutableRevision(): void
    {
        $service = new DailyStoreReportService();
        $first = $service->submit(5, 20, 'STORE_SUPERVISOR');
        $this->assertSame(400, $service->review((int) $first['report']['id'], 30, false, '', 'BE_DIRECTOR')['code']);
        $returned = $service->review((int) $first['report']['id'], 30, false, 'Please explain variance', 'BE_DIRECTOR');
        $this->assertSame('be_returned', $returned['report']['status']);
        $second = $service->submit(5, 20, 'STORE_SUPERVISOR', 'Revised explanation');
        $this->assertSame('success', $second['status']);
        $this->assertSame(2, (int) $second['report']['revision']);
        $this->assertSame('be_returned', $service->find((int) $first['report']['id'])['status']);
    }

    public function testMismatchedSplitPaymentsBlockSubmissionEvenWhenTheirGrandTotalBalances(): void
    {
        $db = Database::connect();
        $db->table('transaction_payments')->where('id', 1)->update(['amount' => 50]);
        $db->table('transaction_payments')->where('id', 2)->update(['amount' => 40]);
        $this->assertSame(409, (new DailyStoreReportService())->submit(5, 20, 'STORE_SUPERVISOR')['code']);
        $this->assertSame(0, $db->table('daily_store_reports')->countAllResults());

        $db->table('transactions')->insert(['id' => 4, 'store_id' => 1, 'amount' => 10, 'payment_method' => 'cash', 'status' => 'completed', 'created_at' => '2026-09-23 04:00:00']);
        $db->table('transaction_payments')->insert(['id' => 3, 'transaction_id' => 4, 'payment_method' => 'cash', 'amount' => 20]);
        // The two errors cancel in the day total, but each source sale is still invalid.
        $this->assertSame(409, (new DailyStoreReportService())->submit(5, 20, 'STORE_SUPERVISOR')['code']);
        $this->assertSame(0, $db->table('daily_store_reports')->countAllResults());
    }

    public function testBeCannotApproveAfterSaleSourceChangesButCanReturnForRevision(): void
    {
        $service = new DailyStoreReportService();
        $submitted = $service->submit(5, 20, 'STORE_SUPERVISOR');
        $this->assertSame('success', $submitted['status']);
        $id = (int) $submitted['report']['id'];
        $this->assertSame('verified', $service->sourceStatus($submitted['report']));
        $db = Database::connect();
        $db->table('transaction_payments')->where('id', 1)->update(['amount' => 59]);
        $db->table('transaction_payments')->where('id', 2)->update(['amount' => 41]);
        // Sale and day totals are unchanged, but the frozen tender evidence is not.
        $this->assertSame('changed', $service->sourceStatus($service->find($id)));
        $this->assertSame(409, $service->review($id, 30, true, '', 'BE_DIRECTOR')['code']);
        $this->assertSame('submitted', $service->find($id)['status']);
        $this->withRoutes([['GET', 'test/be-report-inbox', 'DailyStoreReportController::beInbox']]);
        $beInbox = $this->withSession([
            'logged_in' => true, 'user_id' => 30, 'role' => 'BE_DIRECTOR', 'available_roles' => ['BE_DIRECTOR'],
        ])->get('test/be-report-inbox');
        $beInbox->assertOK();
        $this->assertStringContainsString('Source records have changed since this report was submitted', (string) $beInbox->getBody());
        $this->assertSame('be_returned', $service->review($id, 30, false, 'Tender source changed', 'BE_DIRECTOR')['report']['status']);
        $revised = $service->submit(5, 20, 'STORE_SUPERVISOR');
        $this->assertSame('success', $revised['status']);
        $this->assertSame('verified', $service->sourceStatus($revised['report']));
    }

    public function testCashierAndAccountingSeeSourceDriftAfterBeApproval(): void
    {
        $service = new DailyStoreReportService();
        $submitted = $service->submit(5, 20, 'STORE_SUPERVISOR');
        $id = (int) $submitted['report']['id'];
        $this->assertSame('be_approved', $service->review($id, 30, true, '', 'BE_DIRECTOR')['report']['status']);
        $this->assertSame(409, $service->review($id, 30, false, 'No source issue', 'BE_DIRECTOR')['code']);
        Database::connect()->table('transaction_payments')->where('id', 1)->update(['amount' => 59]);
        Database::connect()->table('transaction_payments')->where('id', 2)->update(['amount' => 41]);
        $this->withRoutes([
            ['GET', 'test/cashier-report-inbox', 'DailyStoreReportController::cashierInbox'],
            ['GET', 'test/accounting-report-inbox', 'DailyStoreReportController::accountingInbox'],
        ]);
        foreach ([
            ['CASHIER_HEAD', 40, 'test/cashier-report-inbox'],
            ['ACCOUNTING_HEAD', 50, 'test/accounting-report-inbox'],
        ] as [$role, $userId, $url]) {
            $result = $this->withSession([
                'logged_in' => true, 'user_id' => $userId, 'role' => $role, 'available_roles' => [$role],
            ])->get($url);
            $result->assertOK();
            $this->assertStringContainsString('Source records have changed since this report was submitted', (string) $result->getBody());
        }
        $this->assertSame(409, $service->receive($id, 40, 'CASHIER_HEAD')['code']);
        $this->assertSame(400, $service->review($id, 30, false, '', 'BE_DIRECTOR')['code']);
        $this->assertSame('be_returned', $service->review($id, 30, false, 'Tender evidence changed', 'BE_DIRECTOR')['report']['status']);
        $revised = $service->submit(5, 20, 'STORE_SUPERVISOR', 'Corrected tender snapshot');
        $this->assertSame(2, (int) $revised['report']['revision']);
        $this->assertSame('be_approved', $service->review((int) $revised['report']['id'], 30, true, '', 'BE_DIRECTOR')['report']['status']);
        $this->assertSame('cashier_received', $service->receive((int) $revised['report']['id'], 40, 'CASHIER_HEAD')['report']['status']);
    }

    public function testCorrectionAfterCashierReceiptPreservesOriginalHandoff(): void
    {
        $service = new DailyStoreReportService();
        $first = $service->submit(5, 20, 'STORE_SUPERVISOR')['report'];
        $id = (int) $first['id'];
        $service->review($id, 30, true, 'Originally reconciled', 'BE_DIRECTOR');
        $received = $service->receive($id, 40, 'CASHIER_HEAD')['report'];
        $db = Database::connect();
        $db->table('transaction_payments')->where('id', 1)->update(['amount' => 59]);
        $db->table('transaction_payments')->where('id', 2)->update(['amount' => 41]);
        $this->assertSame(409, $service->review($id, 30, true, '', 'BE_DIRECTOR')['code']);
        $this->withRoutes([
            ['POST', 'test/be-report-review/(:num)', 'DailyStoreReportController::review/$1'],
            ['GET', 'test/be-report-inbox', 'DailyStoreReportController::beInbox'],
            ['GET', 'test/store-report-inbox', 'DailyStoreReportController::storeInbox'],
            ['GET', 'test/cashier-report-inbox', 'DailyStoreReportController::cashierInbox'],
            ['GET', 'test/accounting-report-inbox', 'DailyStoreReportController::accountingInbox'],
        ]);
        $beSession = ['logged_in' => true, 'user_id' => 30, 'role' => 'BE_DIRECTOR', 'available_roles' => ['BE_DIRECTOR']];
        $bePage = $this->withSession($beSession)->get('test/be-report-inbox');
        $bePage->assertOK();
        $this->assertStringContainsString('Return approved report for correction', (string) $bePage->getBody());
        $this->withSession($beSession)->post('test/be-report-review/' . $id,
            [csrf_token() => csrf_hash(), 'decision' => 'return', 'note' => 'Post-receipt source correction'])->assertRedirect();
        $this->assertSame('be_returned', $service->find($id)['status']);
        $old = $service->find($id);
        $this->assertSame($received['cashier_received_at'], $old['cashier_received_at']);
        $this->assertSame($received['accounting_furnished_at'], $old['accounting_furnished_at']);
        $this->assertSame('Originally reconciled', $old['be_review_note']);

        $store = $this->withSession([
            'logged_in' => true, 'user_id' => 20, 'role' => 'STORE_SUPERVISOR', 'available_roles' => ['STORE_SUPERVISOR'],
        ])->get('test/store-report-inbox?store_id=1');
        $store->assertOK();
        $this->assertStringContainsString('Post-receipt source correction', (string) $store->getBody());
        $this->assertStringContainsString('Submit revised snapshot', (string) $store->getBody());
        foreach ([
            ['CASHIER_HEAD', 40, 'test/cashier-report-inbox'],
            ['ACCOUNTING_HEAD', 50, 'test/accounting-report-inbox'],
        ] as [$role, $userId, $url]) {
            $copy = $this->withSession([
                'logged_in' => true, 'user_id' => $userId, 'role' => $role, 'available_roles' => [$role],
            ])->get($url);
            $copy->assertOK();
            $this->assertStringContainsString('Post-receipt source correction', (string) $copy->getBody());
        }
        $revised = $service->submit(5, 20, 'STORE_SUPERVISOR')['report'];
        $this->assertSame(2, (int) $revised['revision']);
        $this->assertSame('be_returned', $service->find($id)['status']);
    }

    public function testBeCanReturnApprovedLegacyReportWithoutFingerprint(): void
    {
        $service = new DailyStoreReportService();
        $id = (int) $service->submit(5, 20, 'STORE_SUPERVISOR')['report']['id'];
        $service->review($id, 30, true, 'Original approval', 'BE_DIRECTOR');
        Database::connect()->table('daily_store_reports')->where('id', $id)->update(['source_json' => '{"session_id":5}']);
        $this->assertSame('unverifiable', $service->sourceStatus($service->find($id)));
        $this->assertSame(409, $service->receive($id, 40, 'CASHIER_HEAD')['code']);
        $this->assertSame('be_returned', $service->review($id, 30, false, 'Legacy source cannot be verified', 'BE_DIRECTOR')['report']['status']);
    }

    public function testOpenDayAndUnassignedActorCannotSubmit(): void
    {
        $db = Database::connect();
        $db->table('store_day_sessions')->where('id', 5)->update(['status' => 'open']);
        $service = new DailyStoreReportService();
        $this->assertSame(409, $service->submit(5, 20, 'STORE_SUPERVISOR')['code']);
        $db->table('store_day_sessions')->where('id', 5)->update(['status' => 'closed']);
        $this->assertSame(403, $service->submit(5, 12, 'STORE_SYSTEM')['code']);
        $this->assertSame(403, $service->submit(5, 99, 'STORE_SUPERVISOR')['code']);
        $this->assertSame(403, $service->submit(5, 1, 'ADMIN')['code']);
        $this->assertSame(403, $service->review(1, 40, true, '', 'CASHIER_HEAD')['code']);
        $this->assertSame(403, $service->receive(1, 30, 'BE_DIRECTOR')['code']);
    }

    public function testAssignedSupervisorPageShowsClosedDaySubmission(): void
    {
        $this->withRoutes([
            ['GET', 'test/store-daily-reports', 'DailyStoreReportController::storeInbox'],
        ]);
        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 20,
            'name' => 'Supervisor Demo',
            'role' => 'STORE_SUPERVISOR',
            'available_roles' => ['STORE_SUPERVISOR'],
        ])->get('test/store-daily-reports?store_id=1');
        $result->assertOK();
        $body = $result->getJSON() ?: (string) $result->getBody();
        $this->assertIsString($body);
        $this->assertStringContainsString('Submit to BE Director', $body);
        $this->assertStringContainsString('Preview source figures', $body);
        $this->assertStringContainsString('store-admin/daily-reports/submit', $body);

        $this->withSession([
            'logged_in' => true,
            'user_id' => 99,
            'name' => 'Unassigned Supervisor',
            'role' => 'STORE_SUPERVISOR',
            'available_roles' => ['STORE_SUPERVISOR'],
        ])->get('test/store-daily-reports?store_id=1')->assertRedirect();
    }

    public function testPreviewPageReturnsOnlyAssignedSupervisorFigures(): void
    {
        $this->withRoutes([['GET', 'test/store-report-preview/(:num)', 'DailyStoreReportController::preview/$1']]);
        $page = $this->withSession([
            'logged_in' => true, 'user_id' => 20, 'role' => 'STORE_SUPERVISOR', 'available_roles' => ['STORE_SUPERVISOR'],
        ])->get('test/store-report-preview/5');
        $page->assertOK();
        $this->assertStringContainsString('Closed-day source preview', (string) $page->getBody());
        $this->assertStringContainsString('200.00', (string) $page->getBody());
        $this->assertStringContainsString('Store-day close figures', (string) $page->getBody());
        $this->assertStringContainsString('Expected cash', (string) $page->getBody());
        $this->assertSame(0, Database::connect()->table('daily_store_reports')->countAllResults());

        $denied = $this->withSession([
            'logged_in' => true, 'user_id' => 99, 'role' => 'STORE_SUPERVISOR', 'available_roles' => ['STORE_SUPERVISOR'],
        ])->get('test/store-report-preview/5');
        $denied->assertStatus(403);
    }

    public function testPortalEndpointsPreserveSupervisorBeCashierAccountingOrder(): void
    {
        $this->withRoutes([
            ['POST', 'test/store-report-submit', 'DailyStoreReportController::submit'],
            ['GET', 'test/be-report-inbox', 'DailyStoreReportController::beInbox'],
            ['POST', 'test/be-report-review/(:num)', 'DailyStoreReportController::review/$1'],
            ['GET', 'test/cashier-report-inbox', 'DailyStoreReportController::cashierInbox'],
            ['POST', 'test/cashier-report-receive/(:num)', 'DailyStoreReportController::receive/$1'],
            ['GET', 'test/accounting-report-copy', 'DailyStoreReportController::accountingInbox'],
        ]);
        $csrf = [csrf_token() => csrf_hash()];
        $this->withSession([
            'logged_in' => true, 'user_id' => 20, 'role' => 'STORE_SUPERVISOR', 'available_roles' => ['STORE_SUPERVISOR'],
        ])->post('test/store-report-submit', $csrf + ['session_id' => 5, 'store_id' => 1, 'note' => 'Closed day checked'])->assertRedirect();
        $db = Database::connect();
        $report = $db->table('daily_store_reports')->get()->getRowArray();
        $this->assertNotNull($report);
        $this->assertSame('submitted', $report['status']);
        $id = (int) $report['id'];

        $beInbox = $this->withSession([
            'logged_in' => true, 'user_id' => 30, 'role' => 'BE_DIRECTOR', 'available_roles' => ['BE_DIRECTOR'],
        ])->get('test/be-report-inbox');
        $beInbox->assertOK();
        $this->assertStringContainsString('Closed-day source and reconciliation evidence', (string) $beInbox->getBody());
        $this->assertStringContainsString('Source fingerprint:', (string) $beInbox->getBody());

        $cashierBeforeApproval = $this->withSession([
            'logged_in' => true, 'user_id' => 40, 'role' => 'CASHIER_HEAD', 'available_roles' => ['CASHIER_HEAD'],
        ])->get('test/cashier-report-inbox');
        $cashierBeforeApproval->assertOK();
        $this->assertStringContainsString('No BE-approved store reports are waiting for Cashier receipt.', (string) $cashierBeforeApproval->getBody());
        $this->assertStringNotContainsString('Closed day checked', (string) $cashierBeforeApproval->getBody());

        $accountingBeforeApproval = $this->withSession([
            'logged_in' => true, 'user_id' => 50, 'role' => 'ACCOUNTING_HEAD', 'available_roles' => ['ACCOUNTING_HEAD'],
        ])->get('test/accounting-report-copy');
        $accountingBeforeApproval->assertOK();
        $this->assertStringContainsString('No BE-approved store reports have been furnished to Accounting yet.', (string) $accountingBeforeApproval->getBody());
        $this->assertStringNotContainsString('Closed day checked', (string) $accountingBeforeApproval->getBody());

        $this->withSession([
            'logged_in' => true, 'user_id' => 30, 'role' => 'BE_DIRECTOR', 'available_roles' => ['BE_DIRECTOR'],
        ])->post('test/be-report-review/' . $id, $csrf + ['decision' => 'approve', 'note' => 'Reconciled'])->assertRedirect();
        $report = $db->table('daily_store_reports')->where('id', $id)->get()->getRowArray();
        $this->assertSame('be_approved', $report['status']);
        $this->assertNotEmpty($report['accounting_furnished_at']);

        $this->withSession([
            'logged_in' => true, 'user_id' => 40, 'role' => 'CASHIER_HEAD', 'available_roles' => ['CASHIER_HEAD'],
        ])->post('test/cashier-report-receive/' . $id, $csrf)->assertRedirect();
        $this->assertSame('cashier_received', $db->table('daily_store_reports')->where('id', $id)->get()->getRow()->status);

        $copy = $this->withSession([
            'logged_in' => true, 'user_id' => 50, 'role' => 'ACCOUNTING_HEAD', 'available_roles' => ['ACCOUNTING_HEAD'],
        ])->get('test/accounting-report-copy');
        $copy->assertOK();
        $this->assertStringContainsString('Main Store', (string) $copy->getBody());
    }
}
