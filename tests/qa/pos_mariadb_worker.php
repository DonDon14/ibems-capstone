<?php

declare(strict_types=1);

use App\Services\TransactionService;
use CodeIgniter\Boot;
use Config\Database;
use Config\Paths;

if (PHP_SAPI !== 'cli'
    || getenv('IBEMS_MARIADB_POS_QA') !== '1'
    || getenv('IBEMS_DATABASE_DRIVER') !== 'MySQLi'
    || !in_array(getenv('IBEMS_DATABASE_HOSTNAME'), ['localhost', '127.0.0.1'], true)
    || !preg_match('/^ibems_ifr_migqa_\d{8}_[a-z0-9]{6}$/D', (string) getenv('IBEMS_DATABASE_NAME'))) {
    fwrite(STDERR, "This probe requires an explicitly named local MariaDB QA schema.\n");
    exit(2);
}

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
define('HOMEPATH', $root);
define('CONFIGPATH', $root . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $root . 'public' . DIRECTORY_SEPARATOR);
define('FCPATH', PUBLICPATH);
require CONFIGPATH . 'Paths.php';
$paths = new Paths();
define('APPPATH', $root . 'app' . DIRECTORY_SEPARATOR);
define('ROOTPATH', $root);
define('SYSTEMPATH', rtrim($paths->systemDirectory, '\\/') . DIRECTORY_SEPARATOR);
define('WRITEPATH', $root . 'writable' . DIRECTORY_SEPARATOR);
define('TESTPATH', $root . 'tests' . DIRECTORY_SEPARATOR);
define('CIPATH', dirname(SYSTEMPATH) . DIRECTORY_SEPARATOR);
define('SUPPORTPATH', TESTPATH . '_support' . DIRECTORY_SEPARATOR);
define('COMPOSER_PATH', $root . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
define('VENDORPATH', $root . 'vendor' . DIRECTORY_SEPARATOR);
$_SERVER['CI_ENVIRONMENT'] = 'development';
define('ENVIRONMENT', 'development');
define('CI_DEBUG', true);
require SYSTEMPATH . 'Boot.php';
Boot::bootTest($paths);

$db = Database::connect();
if ($db->DBDriver !== 'MySQLi'
    || $db->database !== getenv('IBEMS_DATABASE_NAME')
    || !in_array($db->hostname, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "The active database is outside the guarded QA schema.\n");
    exit(2);
}

$mode = $argv[1] ?? '';
if ($mode === 'probe') {
    echo json_encode(['database' => $db->database, 'driver' => $db->DBDriver,
        'users' => $db->tableExists('users') ? $db->table('users')->countAllResults() : null]), PHP_EOL;
    exit;
}
if ($mode === 'seed') {
    foreach (['users', 'stores', 'products', 'transactions', 'pos_request_receipts'] as $table) {
        if ($db->table($table)->countAllResults() !== 0) {
            throw new RuntimeException('POS probe requires an empty migrated QA schema.');
        }
    }
    $now = ibems_now()->format('Y-m-d H:i:s');
    $db->table('users')->insert(['id' => 7, 'employee_id' => 'QA-POS-7', 'name' => 'Synthetic POS operator',
        'email' => 'qa-pos@simulation.ibems.local', 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
        'role' => 'STORE_SYSTEM', 'user_type' => 'staff', 'qr_token' => 'qa-pos-7', 'is_active' => 1, 'created_at' => $now]);
    $db->table('stores')->insert(['id' => 1, 'store_name' => 'Synthetic POS concurrency store', 'officer_id' => 7,
        'is_active' => 1, 'created_at' => $now]);
    (new App\Models\StoreCapabilityModel())->syncForStore(1, ['retail']);
    $db->table('products')->insert(['id' => 1, 'store_id' => 1, 'sku' => 'QA-POS-1', 'name' => 'Synthetic notebook',
        'price' => 25, 'stock_qty' => 10, 'item_type' => 'stock_item', 'stock_policy' => 'tracked',
        'unit_code' => 'piece', 'is_active' => 1, 'updated_at' => $now]);
    $opened = (new App\Services\StoreDayOperationService())->open(['store_id' => 1, 'opening_cash' => 0], 7, 'STORE_SYSTEM');
    if (($opened['payload']['status'] ?? '') !== 'success') {
        throw new RuntimeException('Synthetic store opening failed: ' . json_encode($opened));
    }
    echo json_encode(['status' => 'seeded']), PHP_EOL;
    exit;
}
if ($db->table('users')->countAllResults() !== 1 || !in_array($db->table('stores')->countAllResults(), [1,2], true)
    || $db->table('users')->where('id', 7)->get()->getRow('email') !== 'qa-pos@simulation.ibems.local') {
    throw new RuntimeException('The expected isolated synthetic POS fixture is missing.');
}
$request = ['store_id' => 1, 'items' => [['product_id' => 1, 'qty' => 2]],
    'payment_method' => 'cash', 'cash_received' => 50, 'request_key' => 'qa-pos-simultaneous-20260930'];
if ($mode === 'seed-repayment') {
    if ($db->table('balances')->countAllResults() !== 0) throw new RuntimeException('Repayment fixture already exists.');
    (new App\Models\StorePaymentMethodModel())->ensureDefaults(1);
    $db->table('balances')->insert(['user_id' => 7, 'credit_limit' => 1000, 'credit_rate' => .25,
        'current_debt' => 100, 'updated_at' => ibems_now()->format('Y-m-d H:i:s')]);
    $db->query('CREATE TRIGGER qa_repayment_delay BEFORE UPDATE ON balances FOR EACH ROW DO SLEEP(2)');
    if (($argv[2] ?? '') === 'cross-store') {
        $db->table('stores')->insert(['id' => 2, 'store_name' => 'Synthetic second collection store', 'officer_id' => 7,
            'is_active' => 1, 'created_at' => ibems_now()->format('Y-m-d H:i:s')]);
        $opened = (new App\Services\StoreDayOperationService())->open(['store_id' => 2, 'opening_cash' => 0], 7, 'STORE_SYSTEM');
        if ($opened['payload']['status'] !== 'success') throw new RuntimeException('Second synthetic store opening failed.');
        (new App\Models\StorePaymentMethodModel())->ensureDefaults(2);
    }
    echo json_encode(['status' => 'seeded']), PHP_EOL;
    exit;
}
if ($mode === 'repayment') {
    $target = (int) ($argv[2] ?? 0);
    if ($target > microtime(true) * 1000 + 10000) throw new RuntimeException('Invalid start time.');
    while (microtime(true) * 1000 < $target) usleep(1000);
    $repayment = [
        'store_id' => (int) ($argv[4] ?? 1), 'user_id' => 7, 'amount' => 60, 'channel' => 'cash',
        'reference_no' => 'Synthetic repayment ' . ($argv[3] ?? '1'),
        'request_key' => 'qa-repayment-store-' . ($argv[4] ?? '1') . '-worker-' . ($argv[3] ?? '1') . '-intent',
    ];
    if (in_array($argv[5] ?? '', ['retry', 'retry-change'], true)) {
        $repayment['request_key'] = 'qa-repayment-shared-intent-0001';
        $repayment['reference_no'] = 'Synthetic shared retry';
        if ($argv[5] === 'retry-change') $repayment['amount'] = 61;
    }
    $result = (new App\Services\StoreCashOperationService())->createDebtRepayment($repayment, 7, 'STORE_SYSTEM');
    echo json_encode($result['payload'] + ['code' => $result['code']]), PHP_EOL;
    exit;
}
if ($mode === 'verify-repayment') {
    $count = ($argv[2] ?? '') === 'closed-first' ? 0 : 1;
    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $assert((float) $db->table('balances')->where('user_id', 7)->get()->getRow('current_debt') === (float) (100 - 60 * $count), 'Remaining debt must match accepted collections.');
    $movements = $db->table('store_cash_movements')->get()->getResultArray();
    $assert(count($movements) === $count, 'Unexpected collection count.');
    if ($count) $assert((float) $movements[0]['amount'] === 60.0, 'Only a PHP 60 collection may persist.');
    $entries = $db->table('debt_cashbook_entries')->get()->getResultArray();
    $assert(count($entries) === $count, 'Unexpected cashbook count.');
    if ($count) $assert($entries[0]['direction'] === 'credit' && (float) $entries[0]['amount'] === 60.0
        && (float) $entries[0]['debt_before'] === 100.0 && (float) $entries[0]['debt_after'] === 40.0
        && (int) $entries[0]['reference_id'] === (int) $movements[0]['id'], 'The cashbook must match the one collection and balance change.');
    $assert($db->table('audit_logs')->where('action', 'STORE_DEBT_REPAYMENT')->countAllResults() === $count, 'Repayment audit count differs.');
    $receipts = $db->table('store_repayment_receipts')->get()->getResultArray();
    $assert(count($receipts) === $count, 'Every committed collection requires one original retry receipt.');
    if ($count) {
        $assert((int) $receipts[0]['cash_movement_id'] === (int) $movements[0]['id'], 'The retry receipt must link to its collection.');
        $foreignKeys = $db->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'store_repayment_receipts' AND COLUMN_NAME = 'cash_movement_id'", [$db->database])->getResultArray();
        $assert(count($foreignKeys) === 1 && $foreignKeys[0]['REFERENCED_TABLE_NAME'] === 'store_cash_movements', 'Repayment receipt foreign key is required.');
    }
    foreach ($db->table('store_day_sessions')->get()->getResultArray() as $day) {
        $collected = $count && (int) $movements[0]['store_id'] === (int) $day['store_id'] ? 60 : 0;
        $assert($day['status'] === 'closed' && (float) $day['expected_cash'] === (float) $collected
            && (float) $day['variance_cash'] === 0.0, 'Each store close must reconcile only its accepted collection.');
    }
    foreach (['transactions','transaction_items','transaction_payments','pos_request_receipts','inventory_movements'] as $table)
        $assert($db->table($table)->countAllResults() === 0, 'Repayment must not create ' . $table);
    echo json_encode(['status' => 'verified', 'debt' => 100 - 60 * $count, 'collected_cash' => 60 * $count, 'cashbook_entries' => $count]), PHP_EOL;
    exit;
}
if ($mode === 'delay-sale') {
    $db->query('CREATE TRIGGER qa_pos_delay BEFORE INSERT ON transactions FOR EACH ROW DO SLEEP(2)');
    echo json_encode(['status' => 'ready']), PHP_EOL;
    exit;
}
if ($mode === 'delay-movement') {
    $db->query('CREATE TRIGGER qa_cash_delay BEFORE INSERT ON store_cash_movements FOR EACH ROW DO SLEEP(2)');
    echo json_encode(['status' => 'ready']), PHP_EOL;
    exit;
}
if ($mode === 'movement') {
    $result = (new App\Services\StoreCashOperationService())->createMovement([
        'store_id' => 1, 'amount' => 50, 'channel' => 'cash', 'movement_type' => 'cash_in',
        'reason' => 'Synthetic overlap cash movement',
    ], 7, 'STORE_SYSTEM');
    echo json_encode($result['payload']), PHP_EOL;
    exit;
}
if ($mode === 'verify-cash') {
    $count = ($argv[2] ?? '') === 'cash-close' ? 1 : 0;
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
    };
    foreach (['transactions', 'transaction_items', 'transaction_payments', 'pos_request_receipts', 'inventory_movements'] as $table) {
        $assert($db->table($table)->countAllResults() === 0, 'Cash movement must not create ' . $table);
    }
    $movements = $db->table('store_cash_movements')->get()->getResultArray();
    $assert(count($movements) === $count, 'Unexpected cash movement count.');
    if ($count) $assert((float) $movements[0]['amount'] === 50.0, 'Cash movement amount must be PHP 50.');
    $assert($db->table('audit_logs')->where('action', 'CREATE_CASH_MOVEMENT')->countAllResults() === $count, 'Cash movement audit count differs.');
    $assert($db->table('audit_logs')->where('action', 'CLOSE_STORE_DAY_SESSION')->countAllResults() === 1, 'One closing audit is required.');
    $day = $db->table('store_day_sessions')->get()->getRowArray();
    $assert($day['status'] === 'closed' && (float) $day['expected_cash'] === (float) ($count * 50)
        && (float) $day['variance_cash'] === 0.0, 'Closing must match committed cash movements.');
    $assert((int) $db->table('products')->get()->getRow('stock_qty') === 10, 'Product stock must be unchanged.');
    echo json_encode(['status' => 'verified', 'cash_movements' => $count, 'cash' => $count * 50, 'stock' => 10]), PHP_EOL;
    exit;
}
if ($mode === 'delay-close') {
    $db->query("CREATE TRIGGER qa_pos_close_delay BEFORE UPDATE ON store_day_sessions FOR EACH ROW BEGIN IF NEW.status = 'closed' THEN DO SLEEP(2); END IF; END");
    echo json_encode(['status' => 'ready']), PHP_EOL;
    exit;
}
if ($mode === 'await-sale') {
    $deadline = microtime(true) + 5;
    do {
        $active = $db->query("SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE DB = ? AND STATE = 'User sleep'", [$db->database])->getRow('n');
        if ((int) $active > 0) { echo json_encode(['status' => 'sale_pending']), PHP_EOL; exit; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('No live sale reached the QA delay trigger.');
}
if (in_array($mode, ['act', 'replay', 'conflict'], true)) {
    $scenario = $argv[4] ?? 'retry';
    if (!in_array($scenario, ['retry', 'distinct', 'stock', 'sale-close', 'close-sale'], true)) throw new RuntimeException('Unknown POS scenario.');
    if (in_array($scenario, ['distinct', 'stock'], true)) {
        $request['request_key'] .= '-' . (int) ($argv[3] ?? 1);
    }
    if ($scenario === 'stock') {
        $request['items'][0]['qty'] = 6;
        $request['cash_received'] = 150;
    }
    $target = (int) ($argv[2] ?? 0);
    if ($target > (int) (microtime(true) * 1000) + 10000) {
        throw new RuntimeException('QA start time may be at most ten seconds ahead.');
    }
    while (microtime(true) * 1000 < $target) {
        usleep(1000);
    }
    if ($mode === 'conflict') {
        $request['items'][0]['qty'] = 1;
    }
    echo json_encode((new TransactionService())->createTransaction($request, 7, 'STORE_SYSTEM')), PHP_EOL;
    exit;
}
if ($mode === 'close') {
    $counted = (int) ($argv[2] ?? 50);
    if (!in_array($counted, [0, 50, 60, 100, 150], true)) throw new RuntimeException('Invalid synthetic closing amount.');
    echo json_encode((new App\Services\StoreDayOperationService())->close([
        'store_id' => (int) ($argv[3] ?? 1), 'counted_cash' => $counted, 'counted_ecash' => 0, 'note' => 'Synthetic QA closing',
    ], 7, 'STORE_SYSTEM')), PHP_EOL;
    exit;
}
if ($mode === 'verify') {
    $scenario = $argv[2] ?? 'retry';
    if (!in_array($scenario, ['retry', 'distinct', 'stock', 'sale-close', 'close-sale'], true)) throw new RuntimeException('Unknown POS scenario.');
    $count = $scenario === 'close-sale' ? 0 : ($scenario === 'distinct' ? 2 : 1);
    $qty = $scenario === 'stock' ? 6 : 2;
    $salesTotal = $count * $qty * 25;
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    foreach (['transactions', 'transaction_items', 'transaction_payments', 'pos_request_receipts'] as $table) {
        $assert($db->table($table)->countAllResults() === $count, 'Unexpected ' . $table . ' row count.');
    }
    $stock = (int) $db->table('products')->where('id', 1)->get()->getRow('stock_qty');
    $assert($stock === 10 - $count * $qty, 'Stock must match only committed sales.');
    $sales = $db->table('transactions')->orderBy('id')->get()->getResultArray();
    foreach ($sales as $sale) {
        $assert((float) $sale['amount'] === (float) ($qty * 25), 'Unexpected sale amount.');
        $payments = $db->table('transaction_payments')->where('transaction_id', $sale['id'])->get()->getResultArray();
        $assert(count($payments) === 1 && (float) $payments[0]['amount'] === (float) ($qty * 25), 'Payment must match each committed sale.');
        $items = $db->table('transaction_items')->where('transaction_id', $sale['id'])->get()->getResultArray();
        $assert(count($items) === 1 && (int) $items[0]['qty'] === $qty, 'Items must match each committed sale.');
        $receipts = $db->table('pos_request_receipts')->where('transaction_id', $sale['id'])->get()->getResultArray();
        $assert(count($receipts) === 1, 'Each sale requires one retry receipt.');
        $response = json_decode($receipts[0]['response_json'], true, 512, JSON_THROW_ON_ERROR);
        $assert((int) $response['transaction_id'] === (int) $sale['id'], 'Retry receipt must retain each sale ID.');
    }
    $foreignKeys = $db->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'pos_request_receipts' AND COLUMN_NAME = 'transaction_id' AND REFERENCED_TABLE_NAME IS NOT NULL", [$db->database])->getResultArray();
    $assert(count($foreignKeys) === 1 && $foreignKeys[0]['REFERENCED_TABLE_NAME'] === 'transactions', 'Retry receipt requires the migrated transaction foreign key.');
    $movements = $db->table('inventory_movements')->where('product_id', 1)->where('type', 'sale')->get()->getResultArray();
    $assert(count($movements) === $count, 'Only committed sales may create inventory movements.');
    foreach ($movements as $movement) $assert((int) $movement['qty'] === $qty, 'Unexpected stock movement quantity.');
    $day = $db->table('store_day_sessions')->where('store_id', 1)->get()->getRowArray();
    $assert($day['status'] === 'closed' && (float) $day['expected_cash'] === (float) $salesTotal
        && (float) $day['variance_cash'] === 0.0, 'Closed-day cash must reconcile with only committed sales.');
    echo json_encode(['status' => 'verified', 'scenario' => $scenario, 'transaction_count' => $count, 'sales' => $salesTotal,
        'stock' => $stock, 'retry_receipts' => $count, 'receipt_foreign_key' => 'transactions',
        'migration_count' => $db->table('migrations')->countAllResults()]), PHP_EOL;
    exit;
}
throw new RuntimeException('Unsupported POS QA mode.');


