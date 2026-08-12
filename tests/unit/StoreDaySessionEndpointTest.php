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

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->assertSame('Opening cash and e-cash must be 0 or greater.', $body['message'] ?? null);
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
            'business_date' => date('Y-m-d'),
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

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        foreach (['audit_logs', 'store_cash_movements', 'transactions', 'store_day_sessions', 'stores', 'users'] as $table) {
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
