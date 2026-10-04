<?php
declare(strict_types=1);

// A separate PHP process per action, restricted to disposable synthetic fixture copies.
$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$target = realpath((string) getenv('IBEMS_DATABASE_NAME'));
$qaRoot = realpath($root . 'writable/sqlite-concurrency');
if (PHP_SAPI !== 'cli' || getenv('IBEMS_SQLITE_RACE_QA') !== '1'
    || getenv('IBEMS_DATABASE_DRIVER') !== 'SQLite3' || !$target || !$qaRoot
    || dirname(dirname($target)) !== $qaRoot || basename($target) !== 'fixture.sqlite'
    || !preg_match('/^run-\d{8}-[a-f0-9]{12}$/D', basename(dirname($target)))) {
    throw new RuntimeException('Requires an explicitly named synthetic SQLite race fixture.');
}
define('HOMEPATH', $root); define('CONFIGPATH', $root . 'app/Config/');
define('PUBLICPATH', $root . 'public/'); define('FCPATH', PUBLICPATH);
require CONFIGPATH . 'Paths.php'; $paths = new Config\Paths();
define('APPPATH', $root . 'app/'); define('ROOTPATH', $root);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', $root . 'writable/'); define('TESTPATH', $root . 'tests/');
define('CIPATH', dirname(SYSTEMPATH) . DIRECTORY_SEPARATOR); define('SUPPORTPATH', TESTPATH . '_support/');
define('COMPOSER_PATH', $root . 'vendor/autoload.php'); define('VENDORPATH', $root . 'vendor/');
define('ENVIRONMENT', 'production'); define('CI_DEBUG', false); $_SERVER['CI_ENVIRONMENT'] = 'production';
require SYSTEMPATH . 'Boot.php'; CodeIgniter\Boot::bootConsole($paths);
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
$db = Config\Database::connect();
if ($db->DBDriver !== 'SQLite3' || realpath($db->database) !== $target
    || $db->table('instructor_demo_metadata')->get()->getRow('marker') !== 'synthetic-instructor-demo-v1'
    || $db->table('users')->countAllResults() !== 24) {
    throw new RuntimeException('The resolved database is not the expected synthetic fixture.');
}
$ok = static function (array $result): array {
    if (($result['status'] ?? '') !== 'success') throw new RuntimeException(json_encode($result));
    return $result;
};
$deductions = new App\Services\DeductionBatchService();
$tx = new App\Services\TransactionService();
$day = new App\Services\StoreDayOperationService();
$action = $argv[1] ?? '';
$scenario = $argv[2] ?? '';
$fixturePath = dirname($target) . '/ids.json';
if ($action === 'seed') {
    if ($db->table('transactions')->countAllResults() !== 0 || $db->table('deduction_batches')->countAllResults() !== 0) {
        throw new RuntimeException('The fixture is already populated.');
    }
    $ok($day->open(['store_id' => 1, 'opening_cash' => 0, 'opening_ecash' => 0], 7, 'STORE_SYSTEM')['payload']);
    $db->table('users')->where('id', 501)->update(['debt_pin_hash' => password_hash('246810', PASSWORD_BCRYPT)]);
    $ok((new App\Services\UserPurchaseCardService($db))->unlock(501));
    $product = $db->table('products')->where('store_id', 1)->where('sku', 'DEMO-1-1')->get()->getRowArray();
    if (!$product || (float) $product['price'] !== 55.0) throw new RuntimeException('Expected synthetic notebook is missing.');
    $productId = (int) $product['id'];
    $ok($tx->createTransaction(['request_key' => 'sqlite-race-opening-charge', 'store_id' => 1,
        'payment_method' => 'debt', 'customer_user_id' => 501, 'debt_pin' => '246810',
        'items' => [['product_id' => $productId, 'qty' => 2]]], 7, 'STORE_SYSTEM'));
    if (str_starts_with($scenario, 'credit-')) {
        $db->table('balances')->where('user_id', 501)->update(['credit_limit' => 190]);
        $ok((new App\Services\UserPurchaseCardService($db))->unlock(501));
        if (str_ends_with($scenario, 'cross-store')) {
            $ok($day->open(['store_id' => 2, 'opening_cash' => 0, 'opening_ecash' => 0], 8, 'STORE_SYSTEM')['payload']);
            $secondProduct = (int) $db->table('products')->where('store_id', 2)->get()->getRow('id');
            $db->table('products')->where('id', $secondProduct)->update(['price' => 55]);
        }
    }
    if (str_starts_with($scenario, 'credit-pin-')) {
        // Supported legacy PIN fallback isolates credit enforcement from single-use cards.
        // This table removal is restricted to the guarded disposable synthetic fixture.
        $db->query('DROP TABLE user_purchase_cards');
    }
    if (str_starts_with($scenario, 'credit-pin-boundary-')) {
        $amount = $scenario === 'credit-pin-boundary-exact' ? '80.00' : '80.01';
        $db->table('products')->where('id', $productId)->update(['price' => $amount]);
    }
    $db->table('products')->where('id', $productId)->update(['stock_qty' => 6]);
    $date = ibems_business_date();
    $period = $ok((new App\Services\DeductionPeriodService())->create([
        'frequency' => 'custom', 'date_start' => $date, 'date_end' => $date], 3));
    $periodId = (int) $period['period']['id'];
    $batch = $ok($deductions->prepare($periodId, [['user_id' => 501, 'requested_amount' => '110.00', 'deduction_choice' => 'full']], 3));
    $batchId = (int) $batch['batch']['id'];
    $itemId = (int) $db->table('deduction_batch_items')->where('batch_id', $batchId)->get()->getRow('id');
    if (!in_array($scenario, ['edit-submit', 'submit-edit'], true)) $ok($deductions->submit($batchId, 3));
    file_put_contents($fixturePath, json_encode(compact('productId', 'periodId', 'batchId', 'itemId'), JSON_THROW_ON_ERROR));
    echo json_encode(['seeded' => true, 'date' => $date, 'driver' => $db->DBDriver, 'php' => PHP_VERSION,
        'sqlite' => $db->query('SELECT sqlite_version() AS version')->getRowArray(),
        'journal' => $db->query('PRAGMA journal_mode')->getRowArray(),
        'busy_timeout' => $db->query('PRAGMA busy_timeout')->getRowArray()]), PHP_EOL;
    exit;
}
$ids = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$holdTable = $argv[3] ?? '';
if ($holdTable !== '') {
    $allowed = ['products', 'store_day_sessions', 'balances', 'deduction_batch_items', 'deduction_periods'];
    if (!in_array($holdTable, $allowed, true)) throw new RuntimeException('Invalid hold target.');
    $paused = false;
    $db->initialize();
    $db->connID->createFunction('qa_hold_writer', static function () use (&$paused, $target): int {
        if (!$paused) {
            $paused = true;
            file_put_contents(dirname($target) . '/writer-held.json', json_encode(['held_at' => microtime(true)]));
            usleep(1200000);
        }
        return 1;
    }, 0);
    $db->query('CREATE TEMP TRIGGER qa_hold_writer BEFORE UPDATE ON ' . $holdTable . ' BEGIN SELECT qa_hold_writer(); END');
}
$started = microtime(true);
if (str_starts_with($scenario, 'credit-')) {
    // Competing distinct purchase intents share the employee's single-use unlock.
    // Whichever loses must not create a second charge or exceed global credit.
    $store = str_ends_with($scenario, 'cross-store') && $action === 'sale2' ? 2 : 1;
    $actor = $store === 2 ? 8 : 7;
    $product = $store === 2 ? (int) $db->table('products')->where('store_id', 2)->get()->getRow('id') : $ids['productId'];
    $price = (float) $db->table('products')->where('id', $product)->get()->getRow('price');
    $result = $tx->createTransaction(['request_key' => 'sqlite-race-' . $scenario . '-' . $action,
        'store_id' => $store, 'payment_method' => 'debt', 'customer_user_id' => 501, 'debt_pin' => '246810',
        'items' => [['product_id' => $product, 'qty' => 1]]], $actor, 'STORE_SYSTEM');
    echo json_encode(['action' => $action, 'started' => $started, 'finished' => microtime(true), 'response' => $result], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
$quantity = $scenario === 'oversell' ? 4 : 1;
$sharedRepayment = $scenario === 'repayment-retry';
$result = match ($action) {
    'sale1', 'sale2' => $tx->createTransaction(['request_key' => $scenario === 'sale-retry' ? 'sqlite-race-shared-sale-intent' : 'sqlite-race-' . $scenario . '-' . $action,
        'store_id' => 1, 'payment_method' => 'cash', 'cash_received' => 55 * $quantity,
        'items' => [['product_id' => $ids['productId'], 'qty' => $quantity]]], 7, 'STORE_SYSTEM'),
    'close' => $day->close(['store_id' => 1, 'counted_cash' => $scenario === 'sale-close' ? 55 : 0, 'counted_ecash' => 0], 7, 'STORE_SYSTEM'),
    'repay1', 'repay2' => (new App\Services\StoreCashOperationService())->createDebtRepayment([
        'request_key' => $sharedRepayment ? 'sqlite-race-shared-repayment-intent' : 'sqlite-race-' . $scenario . '-' . $action,
        'store_id' => 1, 'user_id' => 501, 'amount' => 70, 'channel' => 'cash',
        'reference_no' => $sharedRepayment ? 'Synthetic shared repayment' : 'Synthetic ' . $action], 7, 'STORE_SYSTEM'),
    'confirm' => $deductions->confirmResult($ids['itemId'], ['confirmed_amount' => '70.00',
        'reason_code' => 'insufficient_salary', 'result_reference' => 'SQLITE-QA-ACTUAL-RESULT'], 3),
    'edit' => $deductions->prepare($ids['periodId'], [['user_id' => 501, 'requested_amount' => '80.00', 'deduction_choice' => 'partial']], 3),
    'submit' => $deductions->submit($ids['batchId'], 3),
    default => throw new RuntimeException('Unknown race action.'),
};
echo json_encode(['action' => $action, 'started' => $started, 'finished' => microtime(true), 'response' => $result], JSON_THROW_ON_ERROR), PHP_EOL;
