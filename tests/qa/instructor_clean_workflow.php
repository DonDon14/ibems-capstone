<?php
declare(strict_types=1);

use App\Services\DailyStoreReportService;
use App\Services\DeductionBatchService;
use App\Services\DeductionPeriodService;
use App\Services\StoreDayOperationService;
use App\Services\StoreOfferingService;
use App\Services\TransactionService;
use App\Services\UserPurchaseCardService;

$root = dirname(__DIR__,2) . DIRECTORY_SEPARATOR;
$production = in_array('--production', $argv, true);
$isolatedPin = in_array('--isolated-pin', $argv, true);
$release = realpath($argv[1] ?? '');
if (PHP_SAPI!=='cli' || !$release || dirname($release)!==realpath($root.'writable')
    || !preg_match('/^instructor-release-\d{8}-\d{6}-clean$/',basename($release))) {
    throw new RuntimeException('Requires a generated clean release directly under writable.');
}
$seed = $release . '/deploy/instructor-seed.sqlite';
$seedHash = hash_file('sha256',$seed);
$qaPath = $root.'writable/instructor-qa-'.bin2hex(random_bytes(6)).'.sqlite';
copy($seed,$qaPath);
putenv('IBEMS_DATABASE_DRIVER=SQLite3'); putenv('IBEMS_DATABASE_NAME='.$qaPath);
putenv('IBEMS_DATABASE_DEBUG=1'); putenv('IBEMS_SIMULATION_NOW'); putenv('IBEMS_INSTRUCTOR_DEMO');
define('HOMEPATH',$root); define('CONFIGPATH',$root.'app/Config/'); define('PUBLICPATH',$root.'public/'); define('FCPATH',PUBLICPATH);
require CONFIGPATH.'Paths.php'; $paths=new Config\Paths();
define('APPPATH',$root.'app/'); define('ROOTPATH',$root); define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);
define('WRITEPATH',$root.'writable/'); define('TESTPATH',$root.'tests/'); define('CIPATH',dirname(SYSTEMPATH).DIRECTORY_SEPARATOR);
define('SUPPORTPATH',TESTPATH.'_support/'); define('COMPOSER_PATH',$root.'vendor/autoload.php'); define('VENDORPATH',$root.'vendor/');
define('ENVIRONMENT',$production ? 'production' : 'development'); define('CI_DEBUG',!$production); $_SERVER['CI_ENVIRONMENT']=ENVIRONMENT;
require SYSTEMPATH.'Boot.php';
if ($production) { CodeIgniter\Boot::bootConsole($paths); } else { CodeIgniter\Boot::bootTest($paths); }
set_exception_handler(function(Throwable $error):void { fwrite(STDERR,$error->getMessage().PHP_EOL); exit(1); });
$db=Config\Database::connect();
if ($db->DBDriver!=='SQLite3' || realpath($db->database)!==realpath($qaPath) || $db->table('transactions')->countAllResults()!==0
    || $db->table('users')->countAllResults()!==24 || !$db->tableExists('instructor_demo_metadata')
    || $db->table('instructor_demo_metadata')->get()->getRow('marker')!=='synthetic-instructor-demo-v1') {
    throw new RuntimeException('Resolved QA target is unsafe or not empty.');
}
$assertions=0;
$check=function(bool $condition,string $label) use (&$assertions):void { if(!$condition) throw new RuntimeException($label); $assertions++; };
$ok=function(array $result,string $label) use ($check):array { $check(($result['status']??'')==='success',$label.': '.json_encode($result)); return $result; };
if ($isolatedPin) {
    // Set a fresh PIN only in the disposable QA copy. No deployed credential is needed in CI.
    $pin = ['', (string) random_int(100000, 999999)];
    $db->table('users')->where('id',501)->update(['debt_pin_hash'=>password_hash($pin[1],PASSWORD_BCRYPT)]);
} else {
    $guide=file_get_contents($release.'-ACCOUNTS.md'); preg_match('/Employee purchase PIN: \*\*(\d+)\*\*/',$guide,$pin);
}
if (empty($pin[1])) { throw new RuntimeException('Missing isolated workflow PIN.'); }
$day=new StoreDayOperationService(); $tx=new TransactionService(); $offer=new StoreOfferingService(); $reports=new DailyStoreReportService($db);
$date=ibems_business_date();
foreach ([[1,7],[2,8],[3,9],[4,10],[5,11],[6,12]] as [$store,$actor]) {
    $check($db->table('products')->where('store_id',$store)->countAllResults()===6,'Six initial products for store '.$store);
    $ok($day->open(['store_id'=>$store,'opening_cash'=>0,'opening_ecash'=>0],$actor,'STORE_SYSTEM')['payload'],'Open store '.$store);
    $cash=0;
    if ($store<=4) {
        $product=$db->table('products')->where('store_id',$store)->orderBy('id')->get()->getRowArray();
        if ($store===4) { $offer->mutate('food',['product_id'=>$product['id'],'enabled'=>true,'capacity'=>5,'price'=>(string)$product['price']],$store,$actor,'STORE_SYSTEM'); }
        $request=['request_key'=>'clean-demo-cash-sale-'.$store,'store_id'=>$store,'payment_method'=>'cash','cash_received'=>$product['price'],'items'=>[['product_id'=>$product['id'],'qty'=>1]]];
        if ($store===3) { $request['customer_user_id']=502; }
        $ok($tx->createTransaction($request,$actor,'STORE_SYSTEM'),'Sample sale '.$store); $cash=(float)$product['price'];
        if ($store===1) {
            $ok((new UserPurchaseCardService($db))->unlock(501),'Unlock faculty card');
            $ok($tx->createTransaction(['request_key'=>'clean-demo-faculty-debt-01','store_id'=>1,'payment_method'=>'debt','customer_user_id'=>501,'debt_pin'=>$pin[1],
                'items'=>[['product_id'=>$product['id'],'qty'=>1]]],$actor,'STORE_SYSTEM'),'Faculty debt sale');
            $check((float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt')===55.0,'Faculty debt PHP55');
            $check((int)$db->table('products')->where('id',$product['id'])->get()->getRow('stock_qty')===98,'Notebook stock98');
            $rejected=$tx->createTransaction(['request_key'=>'clean-demo-student-debt-01','store_id'=>1,'payment_method'=>'debt','customer_user_id'=>502,'debt_pin'=>$pin[1],
                'items'=>[['product_id'=>$product['id'],'qty'=>1]]],$actor,'STORE_SYSTEM');
            $check(($rejected['status']??'')!=='success','Student debt must fail');
        }
    } else {
        $package=$db->table('rental_packages')->where('store_id',$store)->orderBy('id')->get()->getRowArray();
        $result=$offer->mutate('book',['package_id'=>$package['id'],'quantity'=>1,'borrower'=>'Fictional Test Borrower','contact'=>'DEMO-ONLY','starts_on'=>$date,'due_on'=>$date,'request_key'=>'clean-demo-rental-'.$store.'-01'],$store,$actor,'STORE_SYSTEM');
        $booking=$db->table('rental_bookings')->where('id',$result['booking_id'])->get()->getRowArray();
        $cash=(int)$booking['fee_cents']/100;
        $ok($tx->createTransaction(['request_key'=>'clean-demo-rental-payment-'.$store,'store_id'=>$store,'payment_method'=>'cash','cash_received'=>$cash,'rental_booking_id'=>$booking['id'],
            'items'=>[['product_id'=>$booking['product_id'],'qty'=>1]]],$actor,'STORE_SYSTEM'),'Rental fee '.$store);
        $offer->mutate('issue',['booking_id'=>$booking['id'],'version'=>1],$store,$actor,'STORE_SYSTEM');
        $offer->mutate('return',['booking_id'=>$booking['id'],'version'=>2,'components'=>json_decode($booking['components_json'],true)],$store,$actor,'STORE_SYSTEM');
        $offer->mutate('refund',['booking_id'=>$booking['id'],'version'=>3],$store,$actor,'STORE_SYSTEM');
        $returned=$db->table('rental_bookings')->where('id',$booking['id'])->get()->getRowArray();
        $check($returned['status']==='returned' && $returned['collected_cents']===$returned['refunded_cents'],'Rental returned/refunded '.$store);
    }
    $ok($day->close(['store_id'=>$store,'counted_cash'=>$cash,'counted_ecash'=>0],$actor,'STORE_SYSTEM')['payload'],'Close store '.$store);
    $session=$db->table('store_day_sessions')->where(['store_id'=>$store,'business_date'=>$date])->get()->getRowArray();
    $check((float)$session['variance_cash']===0.0,'Zero cash variance '.$store);
    $report=$ok($reports->submit((int)$session['id'],20,'STORE_SUPERVISOR','Clean demo acceptance'),'Submit report');
    $reportId=(int)$report['report']['id'];
    $ok($reports->review($reportId,5,true,'Reconciled synthetic test','BE_DIRECTOR'),'BE approval');
    $ok($reports->receive($reportId,4,'CASHIER_HEAD'),'Cashier receipt');
}
$period=$ok((new DeductionPeriodService())->create(['frequency'=>'custom','date_start'=>$date,'date_end'=>$date],3),'Create period');
$deductions=new DeductionBatchService();
$batch=$ok($deductions->prepare((int)$period['period']['id'],[['user_id'=>501,'deduction_choice'=>'full','requested_amount'=>55]],3),'Prepare payroll');
$batchId=(int)$batch['batch']['id']; $ok($deductions->submit($batchId,3),'Submit payroll');
$check((float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt')===55.0,'Submission must not deduct');
$item=$db->table('deduction_batch_items')->where('batch_id',$batchId)->get()->getRowArray();
$ok($deductions->confirmResult((int)$item['id'],['confirmed_amount'=>55,'result_reference'=>'CLEAN-DEMO-RAFAEL-01','reason_code'=>''],3),'Confirm payroll');
$ok($deductions->reconcile($batchId,3),'Reconcile'); $ok($deductions->finalize($batchId,3),'Finalize');
$check((float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt')===0.0,'Final settled debt');
$check(hash_file('sha256',$seed)===$seedHash,'Deployable seed preserved');
$check($db->query('PRAGMA foreign_key_check')->getResultArray()===[],'QA foreign keys');
echo json_encode(['status'=>'passed','checks'=>$assertions,'environment'=>ENVIRONMENT,'php_version'=>PHP_VERSION,'database_driver'=>$db->DBDriver,'stores'=>6,'catalog_products'=>36,'reports_received'=>6,'payroll_confirmed'=>55,'qa_database'=>$qaPath,'seed_sha256'=>$seedHash],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
