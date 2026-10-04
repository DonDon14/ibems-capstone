<?php

declare(strict_types=1);

use CodeIgniter\Boot;
use Config\Database;
use Config\Paths;

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$scenario = getenv('IBEMS_REPAYMENT_BROWSER_SCENARIO') ?: 'reload';
$qaFile = $scenario === 'tab-close' ? 'ibems-month-simulation-20261104.sqlite' : 'ibems-month-simulation-20261103.sqlite';
$target = $root . 'writable/simulation/' . $qaFile;
if (PHP_SAPI !== 'cli' || getenv('IBEMS_REPAYMENT_BROWSER_QA') !== '1'
    || !in_array($scenario, ['reload', 'tab-close'], true)
    || getenv('CI_ENVIRONMENT') !== 'development' || getenv('IBEMS_DATABASE_DRIVER') !== 'SQLite3'
    || !is_file($target) || realpath((string) getenv('IBEMS_DATABASE_NAME')) !== realpath($target)
    || getenv('IBEMS_SIMULATION_NOW') !== '2026-11-01 04:00:00') {
    fwrite(STDERR, "This fixture requires the explicit repayment browser QA clone and clock.\n");
    exit(2);
}
define('HOMEPATH', $root);
define('CONFIGPATH', $root . 'app/Config/');
define('PUBLICPATH', $root . 'public/');
define('FCPATH', PUBLICPATH);
require CONFIGPATH . 'Paths.php';
$paths = new Paths();
define('APPPATH', $root . 'app/');
define('ROOTPATH', $root);
define('SYSTEMPATH', rtrim($paths->systemDirectory, '\\/') . DIRECTORY_SEPARATOR);
define('WRITEPATH', $root . 'writable/');
define('TESTPATH', $root . 'tests/');
define('CIPATH', dirname(SYSTEMPATH) . DIRECTORY_SEPARATOR);
define('SUPPORTPATH', TESTPATH . '_support/');
define('COMPOSER_PATH', $root . 'vendor/autoload.php');
define('VENDORPATH', $root . 'vendor/');
$_SERVER['CI_ENVIRONMENT'] = 'development';
define('ENVIRONMENT', 'development');
define('CI_DEBUG', true);
require SYSTEMPATH . 'Boot.php';
Boot::bootTest($paths);
$db = Database::connect();
if ($db->DBDriver !== 'SQLite3' || realpath($db->database) !== realpath($target)) throw new RuntimeException('Unexpected QA database.');
$mode = $argv[1] ?? '';
$service = new App\Services\StoreDayOperationService();
if ($mode === 'prepare') {
    if ($db->table('store_repayment_receipts')->countAllResults() !== 0
        || (float) $db->table('balances')->where('user_id',501)->get()->getRow('current_debt') !== 700.0
        || $db->table('store_day_sessions')->where('business_date','2026-11-01')->countAllResults() !== 0) {
        throw new RuntimeException('This one-time fixture requires the unmodified synthetic October 9 run clone.');
    }
    $result = $service->open(['store_id'=>1,'opening_cash'=>0,'opening_ecash'=>0],7,'STORE_SYSTEM');
    if (($result['payload']['status'] ?? '') !== 'success') throw new RuntimeException(json_encode($result));
} elseif ($mode === 'close') {
    if ((float) $db->table('balances')->where('user_id',501)->get()->getRow('current_debt') !== 0.0
        || $db->table('store_repayment_receipts')->countAllResults() !== 1) throw new RuntimeException('Full repayment must have committed before closing.');
    $result = $service->close(['store_id'=>1,'counted_cash'=>700,'counted_ecash'=>0],7,'STORE_SYSTEM');
    if (($result['payload']['status'] ?? '') !== 'success') throw new RuntimeException(json_encode($result));
} elseif ($mode !== 'inspect') throw new RuntimeException('Use prepare, close or inspect.');
$snapshot=['scenario'=>$scenario, 'database'=>realpath($target)];
foreach (['balances','store_cash_movements','debt_cashbook_entries','audit_logs','store_repayment_receipts','store_day_sessions'] as $table) {
    $rows=$db->table($table)->orderBy($table==='balances'?'user_id':($table==='store_repayment_receipts'?'request_key':'id'))->get()->getResultArray();
    $snapshot[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
}
$snapshot['employee_debt']=(float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt');
$snapshot['day']=$db->table('store_day_sessions')->where(['store_id'=>1,'business_date'=>'2026-11-01'])->get()->getRowArray();
$snapshot['integrity']=$db->query('PRAGMA integrity_check')->getRowArray();
$snapshot['foreign_key_errors']=count($db->query('PRAGMA foreign_key_check')->getResultArray());
echo json_encode($snapshot,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
