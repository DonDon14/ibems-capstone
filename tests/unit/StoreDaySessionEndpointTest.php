<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class StoreDaySessionEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $originalPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $this->originalPrefix = $db->getPrefix();
        $db->setPrefix('');
        $db->resetTransStatus();
        (new ReflectionProperty(\App\Models\StoreModel::class, 'accessibleStoresRequestCache'))->setValue(null, []);
        $this->resetSchema();
        $this->seedOfficerAndStore();
        $this->withRoutes([
            ['POST', 'store/day-session/open', 'StoreController::openDaySession'],
            ['POST', 'store/day-session/close', 'StoreController::closeDaySession'],
        ]);
    }

    public function testOfficerCanOpenAccessibleStoreDayAndAuditIt(): void
    {
        $result = $this->storePost('store/day-session/open', [
            'store_id' => 1,
            'opening_cash' => 1000,
            'opening_ecash' => 350,
            'note' => 'Opening count verified',
        ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertSame('open', $body['session']['status'] ?? null);
        $this->assertSame(1000.0, (float) ($body['session']['opening_cash'] ?? 0));
        $this->assertSame(350.0, (float) ($body['session']['opening_ecash'] ?? 0));

        $db = Database::connect();
        $audit = $db->table('audit_logs')->where('action', 'OPEN_STORE_DAY_SESSION')->get()->getRowArray();
        $this->assertNotNull($audit);
        $this->assertSame(7, (int) ($audit['actor_id'] ?? 0));
    }

    public function testNegativeOpeningAmountIsRejectedWithoutCreatingSession(): void
    {
        $result = $this->storePost('store/day-session/open', [
            'store_id' => 1,
            'opening_cash' => -1,
            'opening_ecash' => 0,
        ]);

        $result->assertStatus(400);
        $body = $this->jsonBody($result);
        $this->assertSame('Opening cash must be 0 or greater.', $body['message'] ?? null);
        $this->assertSame(0, Database::connect()->table('store_day_sessions')->countAllResults());
    }

    public function testOfficerCannotOpenAnotherOfficersStore(): void
    {
        Database::connect()->table('stores')->insert([
            'id' => 2,
            'store_name' => 'Other Store',
            'officer_id' => 99,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->storePost('store/day-session/open', [
            'store_id' => 2,
            'opening_cash' => 100,
            'opening_ecash' => 0,
        ]);

        $result->assertStatus(403);
        $body = $this->jsonBody($result);
        $this->assertSame('You cannot access this store.', $body['message'] ?? null);
        $this->assertSame(0, Database::connect()->table('store_day_sessions')->countAllResults());
    }

    public function testBalancedClosePersistsExpectedCountsAndAudit(): void
    {
        $this->seedOpenSession(1000, 350);

        $result = $this->storePost('store/day-session/close', [
            'store_id' => 1,
            'counted_cash' => 1000,
            'counted_ecash' => 350,
        ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('closed', $body['session']['status'] ?? null);
        $this->assertSame('balanced', $body['session']['variance_status'] ?? null);
        $this->assertSame('not_required', $body['session']['review_status'] ?? null);

        $db = Database::connect();
        $session = $db->table('store_day_sessions')->where('store_id', 1)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'CLOSE_STORE_DAY_SESSION')->get()->getRowArray();
        $this->assertSame(1000.0, (float) ($session['expected_cash'] ?? 0));
        $this->assertSame(350.0, (float) ($session['expected_ecash'] ?? 0));
        $this->assertNotNull($audit);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        foreach (['store_day_payment_account_balances', 'transaction_payments', 'payment_destination_accounts', 'store_payment_methods'] as $table) $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->setPrefix($this->originalPrefix);
        $db->resetDataCache();
        (new ReflectionProperty(\App\Models\StoreModel::class, 'accessibleStoresRequestCache'))->setValue(null, []);
        parent::tearDown();
    }

    public static function cashMovementKinds(): array
    {
        return ['cash in' => ['cash', 'cash_in'], 'cash out' => ['cash', 'cash_out'],
            'electronic in' => ['ecash', 'cash_in'], 'electronic out' => ['ecash', 'cash_out']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cashMovementKinds')]
    public function testMovementAuditFailureRollsBackAndCloseRejectsLaterMovement(string $channel, string $kind): void
    {
        $this->seedOpenSession(1000, 350);
        $db = Database::connect();
        $service = new \App\Services\StoreCashOperationService();
        $request = ['store_id' => 1, 'amount' => 50, 'channel' => $channel,
            'movement_type' => $kind, 'reason' => 'Synthetic audit fixture'];
        $before = $db->table('store_day_sessions')->orderBy('id')->get()->getResultArray();
        $db->query('CREATE TRIGGER qa_movement_audit_failure BEFORE INSERT ON ' . $db->getPrefix()
            . "audit_logs BEGIN SELECT RAISE(ABORT, 'Synthetic movement audit failure'); END");
        try {
            $this->assertSame('error', $service->createMovement($request, 7, 'STORE_SYSTEM')['payload']['status']);
            $this->assertSame(0, $db->table('store_cash_movements')->countAllResults());
            $this->assertSame(0, $db->table('audit_logs')->countAllResults());
            $this->assertSame($before, $db->table('store_day_sessions')->orderBy('id')->get()->getResultArray());
        } finally {
            $db->query('DROP TRIGGER qa_movement_audit_failure');
            $db->resetTransStatus();
        }
        $this->assertSame('success', $service->createMovement($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $delta = $kind === 'cash_in' ? 50 : -50;
        $close = (new \App\Services\StoreDayOperationService())->close(['store_id' => 1,
            'counted_cash' => 1000 + ($channel === 'cash' ? $delta : 0),
            'counted_ecash' => 350 + ($channel === 'ecash' ? $delta : 0)], 7, 'STORE_SYSTEM');
        $this->assertSame('success', $close['payload']['status']);
        $this->assertSame(0.0, (float) $close['payload']['session']['variance_cash']);
        $this->assertSame(0.0, (float) $close['payload']['session']['variance_ecash']);
        $committed = [$db->table('store_cash_movements')->get()->getResultArray(),
            $db->table('audit_logs')->get()->getResultArray()];
        $this->assertSame('error', $service->createMovement($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $this->assertSame($committed, [$db->table('store_cash_movements')->get()->getResultArray(),
            $db->table('audit_logs')->get()->getResultArray()]);
    }

    public function testClosingAuditFailureRestoresOpenSessionAndAllowsRetry(): void
    {
        $this->seedOpenSession(1000, 350);
        $db = Database::connect();
        $before = $db->table('store_day_sessions')->orderBy('id')->get()->getResultArray();
        $db->query('CREATE TRIGGER qa_close_audit_failure BEFORE INSERT ON ' . $db->getPrefix()
            . "audit_logs BEGIN SELECT RAISE(ABORT, 'Synthetic closing audit failure'); END");
        $service = new \App\Services\StoreDayOperationService();
        $request = ['store_id' => 1, 'counted_cash' => 1000, 'counted_ecash' => 350];
        try {
            $failed = $service->close($request, 7, 'STORE_SYSTEM');
            $this->assertSame('error', $failed['payload']['status']);
            $this->assertSame($before, $db->table('store_day_sessions')->orderBy('id')->get()->getResultArray());
            $this->assertSame(0, $db->table('audit_logs')->countAllResults());
        } finally {
            $db->query('DROP TRIGGER qa_close_audit_failure');
            $db->resetTransStatus();
        }
        $this->assertSame('success', $service->close($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $closed = $db->table('store_day_sessions')->orderBy('id')->get()->getResultArray();
        $this->assertSame('closed', $closed[0]['status']);
        $this->assertSame(1, $db->table('audit_logs')->countAllResults());
        $this->assertSame('error', $service->close($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $this->assertSame($closed, $db->table('store_day_sessions')->orderBy('id')->get()->getResultArray());
        $this->assertSame(1, $db->table('audit_logs')->countAllResults());
    }

    public function testVarianceRequiresClosingNoteAndLeavesSessionOpen(): void
    {
        $this->seedOpenSession(1000, 350);

        $result = $this->storePost('store/day-session/close', [
            'store_id' => 1,
            'counted_cash' => 900,
            'counted_ecash' => 350,
            'note' => '',
        ]);

        $result->assertStatus(400);
        $body = $this->jsonBody($result);
        $this->assertSame('Closing note is required when there is a cash or e-cash variance.', $body['message'] ?? null);

        $db = Database::connect();
        $session = $db->table('store_day_sessions')->where('store_id', 1)->get()->getRowArray();
        $this->assertSame('open', $session['status'] ?? null);
        $this->assertSame(0, $db->table('audit_logs')->countAllResults());
    }

    public function testAdminReopenRequiresReasonAndPreservesOriginalOpeningAndPreviousCloseAudit(): void
    {
        $this->seedOpenSession(1000, 350);
        $db = Database::connect();
        $db->table('store_day_sessions')->where('store_id', 1)->update([
            'status' => 'closed', 'expected_cash' => 1200, 'expected_ecash' => 400,
            'counted_cash' => 1190, 'counted_ecash' => 400, 'variance_cash' => -10,
            'variance_ecash' => 0, 'variance_status' => 'short', 'review_status' => 'pending',
            'closing_note' => 'Ten peso shortage', 'closed_by' => 7, 'closed_at' => date('Y-m-d H:i:s'),
        ]);

        $missingReason = $this->adminPost('store/day-session/open', [
            'store_id' => 1, 'opening_cash' => 9999, 'opening_ecash' => 9999, 'note' => '',
        ]);
        $missingReason->assertStatus(400);
        $this->assertSame('closed', $db->table('store_day_sessions')->where('store_id', 1)->get()->getRowArray()['status'] ?? null);

        $result = $this->adminPost('store/day-session/open', [
            'store_id' => 1, 'opening_cash' => 9999, 'opening_ecash' => 9999,
            'note' => 'Late approved transaction must be entered',
        ]);
        $result->assertOK();
        $session = $db->table('store_day_sessions')->where('store_id', 1)->get()->getRowArray();
        $this->assertSame('open', $session['status'] ?? null);
        $this->assertSame(1000.0, (float) ($session['opening_cash'] ?? 0));
        $this->assertSame(350.0, (float) ($session['opening_ecash'] ?? 0));
        $this->assertNull($session['counted_cash'] ?? null);

        $audit = $db->table('audit_logs')->where('action', 'REOPEN_STORE_DAY_SESSION')->get()->getRowArray();
        $payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
        $this->assertSame('Late approved transaction must be entered', $payload['reason'] ?? null);
        $this->assertSame(1190.0, (float) ($payload['previous_close']['counted_cash'] ?? 0));
        $this->assertSame(-10.0, (float) ($payload['previous_close']['variance_cash'] ?? 0));
        $this->assertTrue((bool) ($payload['original_opening_balances_preserved'] ?? false));
    }

    public function testOfficerCannotCloseAStaleStoreDay(): void
    {
        $this->seedOpenSession(1000, 350);
        Database::connect()->table('store_day_sessions')->where('store_id', 1)->update([
            'business_date' => date('Y-m-d', strtotime('-1 day')),
        ]);

        $result = $this->storePost('store/day-session/close', [
            'store_id' => 1,
            'counted_cash' => 1000,
            'counted_ecash' => 350,
        ]);

        $result->assertStatus(409);
        $body = $this->jsonBody($result);
        $this->assertStringContainsString('stale store day must be resolved', strtolower((string) ($body['message'] ?? '')));
        $this->assertSame('open', Database::connect()->table('store_day_sessions')->where('store_id', 1)->get()->getRowArray()['status'] ?? null);
    }

    private function storePost(string $path, array $payload)
    {
        $security = service('security');
        $payload[$security->getTokenName()] = $security->getHash();

        return $this->withSession([
            'logged_in' => true,
            'user_id' => 7,
            'role' => 'STORE_SYSTEM',
            'available_roles' => ['STORE_SYSTEM'],
        ])->withBodyFormat('json')->post($path, $payload);
    }

    private function adminPost(string $path, array $payload)
    {
        $security = service('security');
        $payload[$security->getTokenName()] = $security->getHash();
        return $this->withSession([
            'logged_in' => true, 'user_id' => 7, 'role' => 'ADMIN', 'available_roles' => ['ADMIN'],
        ])->withBodyFormat('json')->post($path, $payload);
    }

    private function jsonBody($result): array
    {
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function seedOfficerAndStore(): void
    {
        $now = date('Y-m-d H:i:s');
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 7,
            'name' => 'Store Officer',
            'email' => 'store@example.test',
            'role' => 'STORE_SYSTEM',
            'user_type' => 'staff',
            'is_active' => 1,
            'created_at' => $now,
        ]);
        $db->table('stores')->insert([
            'id' => 1,
            'store_name' => 'Test Store',
            'officer_id' => 7,
            'is_active' => 1,
            'created_at' => $now,
        ]);
    }

    private function seedOpenSession(float $openingCash, float $openingEcash): void
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table('store_day_sessions')->insert([
            'store_id' => 1,
            'business_date' => ibems_business_date(),
            'status' => 'open',
            'opening_cash' => $openingCash,
            'opening_ecash' => $openingEcash,
            'opening_note' => 'Test opening',
            'opened_by' => 7,
            'opened_at' => $now,
            'variance_status' => 'balanced',
            'review_status' => 'not_required',
            'accountability_amount' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function testStructuredClosePreservesAccountOpeningsAcrossReopenAndRetry(): void
    {
        $this->seedStructuredAccounts();
        $service = new \App\Services\StoreDayOperationService();
        // Omitted store ID uses the canonical accessible store under the lock.
        $request = ['counted_cash' => 0, 'payment_account_counts' => [['destination_account_id' => 2, 'counted_balance' => 200], ['destination_account_id' => 1, 'counted_balance' => 130]], 'unassigned_payment_counts' => []];
        $result = $service->close($request, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $result['payload']['status'], json_encode($result));
        $this->assertSame([], $result['payload']['session']['unassigned_payment_balances']);
        $db = Database::connect();
        $rows = $db->table('store_day_payment_account_balances')->orderBy('destination_account_id')->get()->getResultArray();
        $this->assertSame([100.0, 200.0], array_map('floatval', array_column($rows, 'opening_balance')));
        $this->assertSame([130.0, 200.0], array_map('floatval', array_column($rows, 'expected_balance')));
        $before = $this->structuredSnapshot();
        $this->assertSame('error', $service->close($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $this->assertSame($before, $this->structuredSnapshot());
        $reopened = $service->open(['store_id' => 1, 'opening_cash' => 999, 'opening_ecash' => 999, 'note' => 'Synthetic review requires reopening'], 7, 'ADMIN');
        $this->assertSame('success', $reopened['payload']['status']);
        $this->assertSame('success', $service->close($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $this->assertSame($rows, $db->table('store_day_payment_account_balances')->orderBy('destination_account_id')->get()->getResultArray());
    }

    public function testStructuredCloseRejectsDuplicateMissingMalformedAndUnknownCountsWithoutWrites(): void
    {
        $this->seedStructuredAccounts();
        $service = new \App\Services\StoreDayOperationService();
        $before = $this->structuredSnapshot();
        foreach ([
            [['destination_account_id' => 1, 'counted_balance' => 130], ['destination_account_id' => 1, 'counted_balance' => 200]],
            [['destination_account_id' => 1, 'counted_balance' => 130]],
            [['destination_account_id' => 1, 'counted_balance' => 130], ['destination_account_id' => 3, 'counted_balance' => 200]],
            null, 'invalid', [null], [['destination_account_id' => 1, 'counted_balance' => 'invalid']],
        ] as $counts) {
            $result = $service->close(['store_id' => 1, 'counted_cash' => 0, 'payment_account_counts' => $counts, 'unassigned_payment_counts' => [], 'note' => 'Synthetic count'], 7, 'STORE_SYSTEM');
            $this->assertSame(400, $result['code']);
            $this->assertSame($before, $this->structuredSnapshot());
        }
    }

    public function testStructuredAuditFailureRestoresAllAccountRowsThenRetrySucceeds(): void
    {
        $this->seedStructuredAccounts();
        $db = Database::connect();
        $before = $this->structuredSnapshot();
        $db->query('CREATE TRIGGER fail_structured_close BEFORE INSERT ON ' . $db->prefixTable('audit_logs') . " BEGIN SELECT RAISE(ABORT, 'synthetic close failure'); END");
        $service = new \App\Services\StoreDayOperationService();
        $request = ['store_id' => 1, 'counted_cash' => 0, 'payment_account_counts' => [['destination_account_id' => 1, 'counted_balance' => 130], ['destination_account_id' => 2, 'counted_balance' => 200]], 'unassigned_payment_counts' => []];
        $this->assertSame('error', $service->close($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $this->assertSame($before, $this->structuredSnapshot());
        $db->query('DROP TRIGGER fail_structured_close');
        $db->resetTransStatus();
        $this->assertSame('success', $service->close($request, 7, 'STORE_SYSTEM')['payload']['status']);
    }

    public function testLegacyBucketCountsRequireUniqueExactCoverage(): void
    {
        $this->seedStructuredAccounts();
        $db = Database::connect();
        $db->table('transactions')->insert(['id' => 2, 'store_id' => 1, 'amount' => 7, 'payment_method' => 'gcash', 'created_at' => ibems_now()->format('Y-m-d H:i:s')]);
        $db->table('transaction_payments')->insert(['id' => 2, 'transaction_id' => 2, 'payment_method' => 'gcash', 'amount' => 7]);
        $db->table('store_cash_movements')->insert(['store_id' => 1, 'business_date' => ibems_business_date(), 'channel' => 'ecash', 'movement_type' => 'cash_in', 'amount' => 3, 'reason' => 'Synthetic electronic adjustment']);
        $service = new \App\Services\StoreDayOperationService();
        $request = ['store_id' => 1, 'counted_cash' => 0, 'payment_account_counts' => [['destination_account_id' => 1, 'counted_balance' => 130], ['destination_account_id' => 2, 'counted_balance' => 200]]];
        $before = $this->structuredSnapshot();
        foreach ([
            [['key' => 'method:gcash', 'counted_balance' => 7], ['key' => 'method:gcash', 'counted_balance' => 3]],
            [['key' => 'method:gcash', 'counted_balance' => 7]],
            [['key' => 'unknown', 'counted_balance' => 7], ['key' => 'legacy:electronic', 'counted_balance' => 3]],
        ] as $counts) {
            $this->assertSame(400, $service->close($request + ['unassigned_payment_counts' => $counts], 7, 'STORE_SYSTEM')['code']);
            $this->assertSame($before, $this->structuredSnapshot());
        }
        $result = $service->close($request + ['unassigned_payment_counts' => [['key' => 'legacy:electronic', 'counted_balance' => 3], ['key' => 'method:gcash', 'counted_balance' => 7]]], 7, 'STORE_SYSTEM');
        $this->assertSame('success', $result['payload']['status']);
        $this->assertSame(340.0, (float) $result['payload']['session']['counted_ecash']);
    }

    private function seedStructuredAccounts(): void
    {
        $db = Database::connect();
        $tn = static fn ($table) => $db->prefixTable($table);
        $db->query('CREATE TABLE ' . $tn('store_payment_methods') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, code TEXT, label TEXT, sort_order INTEGER)');
        $db->query('CREATE TABLE ' . $tn('payment_destination_accounts') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, payment_method_id INTEGER, account_name TEXT, account_number TEXT, image_url TEXT, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('transaction_payments') . ' (id INTEGER PRIMARY KEY, transaction_id INTEGER, destination_account_id INTEGER, payment_method TEXT, amount REAL)');
        $db->query('CREATE TABLE ' . $tn('store_day_payment_account_balances') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, store_day_session_id INTEGER, destination_account_id INTEGER, account_name_snapshot TEXT, account_number_snapshot TEXT, opening_balance REAL, expected_balance REAL, counted_balance REAL, variance REAL, created_at TEXT, updated_at TEXT)');
        $db->resetDataCache();
        $this->seedOpenSession(0, 300);
        $db->table('store_payment_methods')->insert(['id' => 1, 'store_id' => 1, 'code' => 'gcash', 'label' => 'Synthetic electronic', 'sort_order' => 1]);
        foreach ([1 => 100, 2 => 200] as $id => $opening) {
            $db->table('payment_destination_accounts')->insert(['id' => $id, 'store_id' => 1, 'payment_method_id' => 1, 'account_name' => 'Synthetic account ' . $id, 'account_number' => 'SYNTHETIC-' . $id, 'is_active' => 1]);
            $db->table('store_day_payment_account_balances')->insert(['store_day_session_id' => 1, 'destination_account_id' => $id, 'opening_balance' => $opening]);
        }
        $db->table('transactions')->insert(['id' => 1, 'store_id' => 1, 'amount' => 25, 'payment_method' => 'gcash', 'created_at' => ibems_now()->format('Y-m-d H:i:s')]);
        $db->table('transaction_payments')->insert(['id' => 1, 'transaction_id' => 1, 'destination_account_id' => 1, 'payment_method' => 'gcash', 'amount' => 25]);
        $db->table('store_cash_movements')->insert(['store_id' => 1, 'business_date' => ibems_business_date(), 'channel' => 'ecash', 'movement_type' => 'cash_in', 'amount' => 5, 'reason' => 'Debt repayment | Method: gcash | Account: 1']);
    }

    private function structuredSnapshot(): array
    {
        $db = Database::connect();
        return array_map(static fn ($table) => $db->table($table)->get()->getResultArray(), ['store_day_sessions', 'store_day_payment_account_balances', 'audit_logs']);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        foreach (['store_day_payment_account_balances', 'transaction_payments', 'payment_destination_accounts', 'store_payment_methods', 'audit_logs', 'store_cash_movements', 'transactions', 'store_day_sessions', 'stores', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }

        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('stores') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_name TEXT NOT NULL,
            officer_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('store_day_sessions') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            status TEXT NOT NULL,
            opening_cash REAL NOT NULL DEFAULT 0,
            opening_ecash REAL NOT NULL DEFAULT 0,
            opening_note TEXT,
            opened_by INTEGER,
            opened_at TEXT,
            expected_cash REAL,
            expected_ecash REAL,
            counted_cash REAL,
            counted_ecash REAL,
            variance_cash REAL,
            variance_ecash REAL,
            variance_status TEXT,
            review_status TEXT,
            reviewed_by INTEGER,
            reviewed_at TEXT,
            review_note TEXT,
            accountability_user_id INTEGER,
            accountability_amount REAL,
            closing_note TEXT,
            closed_by INTEGER,
            closed_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('transactions') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            payment_method TEXT NOT NULL,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('store_cash_movements') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            channel TEXT NOT NULL,
            movement_type TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            reason TEXT,
            created_by INTEGER,
            updated_at TEXT,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            payload_json TEXT,
            created_at TEXT
        )');
    }
}
