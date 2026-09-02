<?php

namespace App\Commands;

use App\Models\StoreDaySessionModel;
use App\Services\StoreDayExpectedService;
use App\Services\TransactionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

final class VerifyLiveOneDayOperations extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:verify-live-one-day-operations';
    protected $description = 'Run and clean up an isolated one-day POS transaction matrix against PostgreSQL staging or an explicitly approved local development database.';
    protected $options = [
        '--allow-local' => 'Explicitly allow the isolated, self-cleaning trial on a non-production local database.',
    ];

    private $db;
    private string $marker;
    private array $ids = ['actors' => [], 'customers' => [], 'stores' => [], 'products' => [], 'transactions' => []];

    public function run(array $params)
    {
        $this->db = Database::connect();
        $isPostgreSql = stripos((string) ($this->db->DBDriver ?? ''), 'Postgre') !== false;
        $allowLocal = array_key_exists('allow-local', $params) || CLI::getOption('allow-local') !== null;
        if (!$isPostgreSql && (!$allowLocal || ENVIRONMENT === 'production')) {
            CLI::error('Non-PostgreSQL execution requires --allow-local and a non-production environment.');
            return EXIT_ERROR;
        }
        if (!$isPostgreSql) {
            CLI::write('[LOCAL] Running an isolated development trial; synthetic records will be removed.', 'yellow');
        }

        $this->marker = 'QA-DAY-' . gmdate('YmdHis');
        $passed = false;
        try {
            $fixture = $this->createFixture();
            $service = new TransactionService();
            $base = ['store_id' => $fixture['store'], 'customer_type' => 'walk_in'];

            $this->expectError($service, $base + ['payment_method' => 'cash', 'cash_received' => 10, 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'Store day is not open', 'closed-day sale');
            $this->openDay($fixture);

            $this->success($service, $base + ['payment_method' => 'cash', 'cash_received' => 50, 'items' => [['product_id' => $fixture['product10'], 'qty' => 2]]], $fixture, 'cash walk-in with change');
            $this->success($service, $base + ['payment_method' => 'gcash', 'payments' => [['payment_method' => 'gcash', 'amount' => 10, 'destination_account_id' => $fixture['account']]], 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'GCash destination sale');
            $this->success($service, $base + ['payment_method' => 'split', 'payments' => [
                ['payment_method' => 'cash', 'amount' => 8, 'cash_received' => 10],
                ['payment_method' => 'gcash', 'amount' => 12, 'destination_account_id' => $fixture['account']],
            ], 'items' => [['product_id' => $fixture['product10'], 'qty' => 2]]], $fixture, 'cash and GCash split');
            $employee = $base + ['customer_user_id' => $fixture['customer'], 'customer_type' => 'staff', 'debt_pin' => '2468'];
            $this->expectError($service, array_replace($employee, ['debt_pin' => '0000', 'payment_method' => 'debt', 'items' => [['product_id' => $fixture['product5'], 'qty' => 1]]]), $fixture, 'Invalid debt PIN', 'invalid debt PIN');
            $this->success($service, $employee + ['payment_method' => 'split', 'payments' => [
                ['payment_method' => 'cash', 'amount' => 5, 'cash_received' => 5], ['payment_method' => 'debt', 'amount' => 15],
            ], 'items' => [['product_id' => $fixture['product10'], 'qty' => 2]]], $fixture, 'cash and Debt split');
            $this->success($service, $employee + ['payment_method' => 'debt', 'items' => [['product_id' => $fixture['product5'], 'qty' => 1]]], $fixture, 'exact credit limit debt sale');

            $this->expectError($service, $employee + ['payment_method' => 'debt', 'items' => [['product_id' => $fixture['product5'], 'qty' => 1]]], $fixture, 'Insufficient credit', 'over-credit sale');
            $this->expectError($service, $base + ['payment_method' => 'debt', 'debt_pin' => '2468', 'items' => [['product_id' => $fixture['product5'], 'qty' => 1]]], $fixture, 'select a faculty/staff', 'walk-in debt');
            $this->expectError($service, $base + ['payment_method' => 'gcash', 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'Select an active receiving account', 'missing destination');
            $this->expectError($service, $base + ['payment_method' => 'disabled_pay', 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'Invalid or disabled payment method', 'disabled tender');
            $this->expectError($service, $base + ['payment_method' => 'cash', 'cash_received' => 5, 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'Cash received must cover', 'insufficient cash');
            $this->expectError($service, $base + ['payment_method' => 'split', 'payments' => [['payment_method' => 'cash', 'amount' => 4, 'cash_received' => 4], ['payment_method' => 'gcash', 'amount' => 5, 'destination_account_id' => $fixture['account']]], 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'must equal', 'under-allocated split');
            $this->expectError($service, $base + ['payment_method' => 'cash', 'cash_received' => 10000, 'items' => [['product_id' => $fixture['product10'], 'qty' => 999]]], $fixture, 'Insufficient stock', 'insufficient stock');
            $this->expectError($service, $base + ['payment_method' => 'cash', 'cash_received' => 10, 'items' => [['product_id' => $fixture['foreignProduct'], 'qty' => 1]]], $fixture, 'does not belong', 'cross-store product');

            $this->recordElectronicDebtCollection($fixture, 20.0);

            $expected = (new StoreDayExpectedService())->calculate($fixture['store'], $this->db->table('store_day_sessions')->where('store_id', $fixture['store'])->where('business_date', ibems_business_date())->get()->getRowArray());
            $this->assertMoney($expected, 'cash_sales', 33, 'cash sales including split line');
            $this->assertMoney($expected, 'ecash_sales', 22, 'e-cash sales including split line');
            $this->assertMoney($expected, 'debt_sales', 20, 'debt sales including split line');
            $this->assertMoney($expected, 'expected_cash_on_hand', 133, 'closing cash expectation');
            $this->assertMoney($expected, 'expected_ecash_on_hand', 242, 'closing e-cash expectation');
            $account = $expected['payment_account_balances'][0] ?? [];
            $this->assertMoney($account, 'sales', 22, 'destination account sales');
            $this->assertMoney($account, 'collections', 20, 'destination account debt collections');
            $this->assertMoney($account, 'expected_balance', 92, 'destination account closing balance');
            $collection = $expected['payment_method_collections'][0] ?? [];
            if (($collection['payment_method'] ?? '') !== 'gcash') throw new \RuntimeException('Debt collection was not attributed to GCash.');
            $this->assertMoney($collection, 'amount', 20, 'GCash debt collection');

            $balance = $this->db->table('balances')->where('user_id', $fixture['customer'])->get()->getRowArray();
            if ((float) ($balance['current_debt'] ?? -1) !== 80.0) throw new \RuntimeException('Employee debt was not reduced from PHP 100.00 to PHP 80.00 by the GCash collection.');
            $this->closeDayBalanced($fixture, $expected);
            $this->reopenDayPreservingOriginal($fixture);
            $this->success($service, $base + ['payment_method' => 'cash', 'cash_received' => 10, 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'approved sale after admin reopen');
            $reopenedExpected = (new StoreDayExpectedService())->calculate($fixture['store'], $this->db->table('store_day_sessions')->where('store_id', $fixture['store'])->where('business_date', ibems_business_date())->get()->getRowArray());
            $this->assertMoney($reopenedExpected, 'expected_cash_on_hand', 143, 'reopened closing cash expectation');
            $this->closeDayBalanced($fixture, $reopenedExpected);
            $this->expectError($service, $base + ['payment_method' => 'cash', 'cash_received' => 10, 'items' => [['product_id' => $fixture['product10'], 'qty' => 1]]], $fixture, 'Store day is not open', 'sale after day close');
            if (count($this->ids['transactions']) !== 6) throw new \RuntimeException('Expected exactly six successful transactions including the approved post-reopen sale.');
            CLI::write('[OK] Six successful sales and eleven rejection paths behaved correctly.', 'green');
            CLI::write('[OK] Split tenders reconciled as Cash PHP 33.00, E-Cash PHP 22.00, Debt PHP 20.00.', 'green');
            CLI::write('[OK] GCash debt collection reduced employee debt to PHP 80.00 and routed PHP 20.00 to the selected account.', 'green');
            CLI::write('[OK] Closing expectations are Cash PHP 133.00, E-Cash PHP 242.00, destination PHP 92.00.', 'green');
            CLI::write('[OK] Admin reopen preserved original openings, accepted one approved sale, and recalculated cash to PHP 143.00.', 'green');
            CLI::write('[OK] Second balanced close persisted and a sale after close was rejected.', 'green');
            $passed = true;
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        } finally {
            $this->cleanup();
            CLI::write('[OK] Synthetic one-day records cleaned up.', 'green');
        }
        return $passed ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function createFixture(): array
    {
        $now = date('Y-m-d H:i:s');
        $user = function (string $suffix, string $type = 'staff', ?string $pin = null) use ($now): int {
            $row = ['employee_id' => $this->marker . '-' . $suffix, 'name' => $this->marker . ' ' . $suffix,
                'email' => strtolower($this->marker . '-' . $suffix) . '@example.test', 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'role' => $suffix === 'OFFICER' ? 'STORE_SYSTEM' : 'USER', 'user_type' => $type, 'qr_token' => $this->marker . '-' . $suffix . '-QR',
                'base_salary' => 0, 'is_active' => true, 'created_at' => $now];
            if ($pin !== null) $row['debt_pin_hash'] = password_hash($pin, PASSWORD_BCRYPT);
            $this->db->table('users')->insert($row); $id = (int) $this->db->insertID(); $this->ids[$suffix === 'OFFICER' ? 'actors' : 'customers'][] = $id; return $id;
        };
        $actor = $user('OFFICER'); $customer = $user('EMPLOYEE', 'staff', '2468');
        $this->db->table('balances')->insert(['user_id' => $customer, 'credit_limit' => 100, 'current_debt' => 80, 'updated_at' => $now]);
        $store = $this->insertStore($this->marker . ' Store', $actor, $now); $otherStore = $this->insertStore($this->marker . ' Other Store', $actor, $now);
        $product10 = $this->insertProduct($store, 'TEN', 10, 30, $now); $product5 = $this->insertProduct($store, 'FIVE', 5, 3, $now); $foreign = $this->insertProduct($otherStore, 'FOREIGN', 10, 3, $now);
        foreach ([['cash', 'Cash', true], ['debt', 'Debt', true], ['gcash', 'GCash', true], ['disabled_pay', 'Disabled Pay', false]] as $index => $method) {
            $this->db->table('store_payment_methods')->insert(['store_id' => $store, 'code' => $method[0], 'label' => $method[1], 'sort_order' => ($index + 1) * 10, 'is_active' => $method[2], 'is_system_reserved' => $method[0] === 'debt', 'created_at' => $now, 'updated_at' => $now]);
            if ($method[0] === 'gcash') $gcashId = (int) $this->db->insertID();
        }
        $this->db->table('payment_destination_accounts')->insert(['store_id' => $store, 'payment_method_id' => $gcashId, 'account_name' => 'QA Receiver', 'account_number' => '09000000000', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        return ['actor' => $actor, 'customer' => $customer, 'store' => $store, 'otherStore' => $otherStore, 'product10' => $product10, 'product5' => $product5, 'foreignProduct' => $foreign, 'account' => (int) $this->db->insertID()];
    }

    private function insertStore(string $name, int $actor, string $now): int { $this->db->table('stores')->insert(['store_name' => $name, 'officer_id' => $actor, 'is_active' => true, 'created_at' => $now]); $id = (int) $this->db->insertID(); $this->ids['stores'][] = $id; return $id; }
    private function insertProduct(int $store, string $sku, float $price, int $stock, string $now): int { $this->db->table('products')->insert(['store_id' => $store, 'sku' => $this->marker . '-' . $sku, 'name' => $this->marker . ' ' . $sku, 'category' => 'QA', 'price' => $price, 'stock_qty' => $stock, 'low_stock_threshold' => 0, 'location_bin' => 'QA', 'is_active' => true, 'updated_at' => $now]); $id = (int) $this->db->insertID(); $this->ids['products'][] = $id; return $id; }
    private function openDay(array $f): void { $now = date('Y-m-d H:i:s'); $this->db->table('store_day_sessions')->insert(['store_id' => $f['store'], 'business_date' => ibems_business_date(), 'status' => 'open', 'opening_cash' => 100, 'opening_ecash' => 200, 'opening_note' => $this->marker, 'opened_by' => $f['actor'], 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now]); $session = (int) $this->db->insertID(); $this->db->table('store_day_payment_account_balances')->insert(['store_day_session_id' => $session, 'destination_account_id' => $f['account'], 'account_name_snapshot' => 'QA Receiver', 'account_number_snapshot' => '09000000000', 'opening_balance' => 50, 'created_at' => $now, 'updated_at' => $now]); }
    private function recordElectronicDebtCollection(array $f, float $amount): void
    {
        $now = date('Y-m-d H:i:s'); $before = 100.0; $after = $before - $amount;
        $this->db->transStart();
        $this->db->table('balances')->where('user_id', $f['customer'])->update(['current_debt' => $after, 'updated_at' => $now]);
        $this->db->table('store_cash_movements')->insert(['store_id' => $f['store'], 'business_date' => ibems_business_date(), 'channel' => 'ecash', 'movement_type' => 'cash_in', 'amount' => $amount, 'reason' => 'Debt repayment | Method: gcash | Account: ' . $f['account'] . ' | ' . $this->marker, 'created_by' => $f['actor'], 'created_at' => $now, 'updated_at' => $now]);
        $movementId = (int) $this->db->insertID();
        $this->db->table('debt_cashbook_entries')->insert(['user_id' => $f['customer'], 'entry_type' => 'store_repayment', 'direction' => 'credit', 'amount' => $amount, 'debt_before' => $before, 'debt_after' => $after, 'credit_limit_snapshot' => 100, 'available_credit_snapshot' => 20, 'reference_type' => 'store_cash_movement', 'reference_id' => $movementId, 'actor_id' => $f['actor'], 'remarks' => $this->marker, 'meta_json' => json_encode(['payment_method' => 'gcash', 'destination_account_id' => $f['account']]), 'created_at' => $now, 'updated_at' => $now]);
        $this->db->table('audit_logs')->insert(['actor_id' => $f['actor'], 'action' => 'STORE_DEBT_REPAYMENT', 'entity' => 'balances', 'entity_id' => $f['customer'], 'payload_json' => json_encode(['cash_movement_id' => $movementId, 'payment_method' => 'gcash']), 'created_at' => $now]);
        $this->db->transComplete();
        if (!$this->db->transStatus()) throw new \RuntimeException('GCash debt collection did not commit.');
        CLI::write('[OK] GCash debt collection routed to the selected receiving account', 'green');
    }
    private function closeDayBalanced(array $f, array $expected): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('store_day_sessions')->where('store_id', $f['store'])->where('business_date', ibems_business_date())->update(['status' => 'closed', 'expected_cash' => $expected['expected_cash_on_hand'], 'expected_ecash' => $expected['expected_ecash_on_hand'], 'counted_cash' => $expected['expected_cash_on_hand'], 'counted_ecash' => $expected['expected_ecash_on_hand'], 'variance_cash' => 0, 'variance_ecash' => 0, 'variance_status' => 'balanced', 'review_status' => 'not_required', 'closing_note' => $this->marker . ' verified close', 'closed_by' => $f['actor'], 'closed_at' => $now, 'updated_at' => $now]);
        $session = $this->db->table('store_day_sessions')->where('store_id', $f['store'])->where('business_date', ibems_business_date())->get()->getRowArray();
        $this->db->table('store_day_payment_account_balances')->where('store_day_session_id', (int) ($session['id'] ?? 0))->where('destination_account_id', $f['account'])->update(['expected_balance' => 92, 'counted_balance' => 92, 'variance' => 0, 'updated_at' => $now]);
        if (($session['status'] ?? '') !== 'closed') throw new \RuntimeException('Balanced store-day close did not persist.');
    }
    private function reopenDayPreservingOriginal(array $f): void
    {
        $session = $this->db->table('store_day_sessions')->where('store_id', $f['store'])->where('business_date', ibems_business_date())->get()->getRowArray();
        $cash = (float) ($session['opening_cash'] ?? -1); $ecash = (float) ($session['opening_ecash'] ?? -1);
        $reopened = (new StoreDaySessionModel())->reopenDay((int) $session['id'], $f['actor']);
        if (($reopened['status'] ?? '') !== 'open' || (float) ($reopened['opening_cash'] ?? -2) !== $cash || (float) ($reopened['opening_ecash'] ?? -2) !== $ecash || ($reopened['counted_cash'] ?? null) !== null) throw new \RuntimeException('Admin reopen did not preserve original openings or clear the prior close state.');
        CLI::write('[OK] admin reopen preserved original opening balances and cleared only the active close state', 'green');
    }
    private function success(TransactionService $s, array $request, array $f, string $label): void { $result = $s->createTransaction($request, $f['actor'], 'STORE_SYSTEM'); if (($result['status'] ?? '') !== 'success') throw new \RuntimeException("$label failed: " . ($result['message'] ?? 'unknown')); $this->ids['transactions'][] = (int) $result['transaction_id']; CLI::write('[OK] ' . $label, 'green'); }
    private function expectError(TransactionService $s, array $request, array $f, string $message, string $label): void { $before = $this->state($f); $result = $s->createTransaction($request, $f['actor'], 'STORE_SYSTEM'); if (($result['status'] ?? '') !== 'error' || stripos((string) ($result['message'] ?? ''), $message) === false) throw new \RuntimeException("$label did not reject as expected: " . ($result['message'] ?? 'no error')); $after = $this->state($f); if ($before !== $after) throw new \RuntimeException("$label rejection changed financial, transaction, inventory, or audit state."); CLI::write('[OK] rejected ' . $label, 'green'); }
    private function state(array $f): array { $balance = $this->db->table('balances')->where('user_id', $f['customer'])->get()->getRowArray(); return ['tx' => $this->db->table('transactions')->where('store_id', $f['store'])->countAllResults(), 'items' => $this->db->table('transaction_items ti')->join('transactions t', 't.id = ti.transaction_id')->where('t.store_id', $f['store'])->countAllResults(), 'payments' => $this->db->table('transaction_payments tp')->join('transactions t', 't.id = tp.transaction_id')->where('t.store_id', $f['store'])->countAllResults(), 'moves' => $this->db->table('inventory_movements')->where('store_id', $f['store'])->countAllResults(), 'cashbook' => $this->db->table('debt_cashbook_entries')->where('user_id', $f['customer'])->countAllResults(), 'audit' => $this->db->table('audit_logs')->where('actor_id', $f['actor'])->where('action', 'CREATE_TRANSACTION')->countAllResults(), 'debt' => (float) ($balance['current_debt'] ?? 0), 'stock10' => (int) ($this->db->table('products')->where('id', $f['product10'])->get()->getRowArray()['stock_qty'] ?? 0), 'stock5' => (int) ($this->db->table('products')->where('id', $f['product5'])->get()->getRowArray()['stock_qty'] ?? 0)]; }
    private function assertMoney(array $row, string $key, float $expected, string $label): void { if (abs((float) ($row[$key] ?? -999999) - $expected) > 0.001) throw new \RuntimeException("$label expected PHP " . number_format($expected, 2) . ', got PHP ' . number_format((float) ($row[$key] ?? 0), 2)); }
    private function cleanup(): void
    {
        $tx = $this->ids['transactions'];
        if ($tx !== []) { $this->db->table('audit_logs')->where('entity', 'transactions')->whereIn('entity_id', $tx)->delete(); $this->db->table('debt_cashbook_entries')->where('reference_type', 'transaction')->whereIn('reference_id', $tx)->delete(); $this->db->table('transaction_payments')->whereIn('transaction_id', $tx)->delete(); $this->db->table('inventory_movements')->whereIn('txn_id', $tx)->delete(); $this->db->table('transaction_items')->whereIn('transaction_id', $tx)->delete(); $this->db->table('transactions')->whereIn('id', $tx)->delete(); }
        foreach ($this->ids['customers'] as $id) { $this->db->table('debt_pin_security')->where('user_id', $id)->delete(); $this->db->table('debt_cashbook_entries')->where('user_id', $id)->delete(); $this->db->table('balances')->where('user_id', $id)->delete(); }
        foreach ($this->ids['actors'] as $id) $this->db->table('audit_logs')->where('actor_id', $id)->delete();
        foreach ($this->ids['stores'] as $id) { $this->db->table('store_cash_movements')->where('store_id', $id)->delete(); $sessions = array_column($this->db->table('store_day_sessions')->select('id')->where('store_id', $id)->get()->getResultArray(), 'id'); if ($sessions !== []) $this->db->table('store_day_payment_account_balances')->whereIn('store_day_session_id', $sessions)->delete(); $this->db->table('store_day_sessions')->where('store_id', $id)->delete(); $this->db->table('payment_destination_accounts')->where('store_id', $id)->delete(); $this->db->table('store_payment_methods')->where('store_id', $id)->delete(); }
        if ($this->ids['products'] !== []) $this->db->table('products')->whereIn('id', $this->ids['products'])->delete();
        if ($this->ids['stores'] !== []) $this->db->table('stores')->whereIn('id', $this->ids['stores'])->delete();
        $users = array_merge($this->ids['customers'], $this->ids['actors']); if ($users !== []) $this->db->table('users')->whereIn('id', $users)->delete();
    }
}
