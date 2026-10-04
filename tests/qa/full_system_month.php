<?php
declare(strict_types=1);

// A complementary month in a separately migrated, empty synthetic database.
// This exercises every synthetic person in every retail-capable business;
// dated food, rentals, department accounts and reversals use the core month and named regressions.
$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$sqlQa = getenv('IBEMS_DATABASE_DRIVER') === 'MySQLi' && getenv('IBEMS_SQL_MONTH_QA') === '1';
$target = $sqlQa ? (string) getenv('IBEMS_DATABASE_NAME') : realpath((string) getenv('IBEMS_DATABASE_NAME'));
if (PHP_SAPI !== 'cli' || getenv('IBEMS_SIMULATION') !== '1' || (!$sqlQa && (getenv('IBEMS_DATABASE_DRIVER') !== 'SQLite3'
    || !$target || dirname($target) !== realpath($root . 'writable/simulation')
    || preg_match('/^ibems-month-simulation-\d{8}\.sqlite$/D', basename($target)) !== 1))) throw new RuntimeException('Explicit disposable breadth target required.');
define('HOMEPATH', $root); define('CONFIGPATH', $root . 'app/Config/');
define('PUBLICPATH', $root . 'public/'); define('FCPATH', PUBLICPATH);
require CONFIGPATH . 'Paths.php'; $paths = new Config\Paths();
define('APPPATH', $root . 'app/'); define('ROOTPATH', $root);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', $root . 'writable/'); define('TESTPATH', $root . 'tests/');
define('CIPATH', dirname(SYSTEMPATH) . DIRECTORY_SEPARATOR); define('SUPPORTPATH', TESTPATH . '_support/');
define('COMPOSER_PATH', $root . 'vendor/autoload.php'); define('VENDORPATH', $root . 'vendor/');
define('ENVIRONMENT', 'development'); define('CI_DEBUG', true); $_SERVER['CI_ENVIRONMENT']='development';
if ($sqlQa) require __DIR__ . '/sql_month_support.php';
require SYSTEMPATH . 'Boot.php'; CodeIgniter\Boot::bootConsole($paths);
$db = Config\Database::connect();
if ($sqlQa) App\Services\SqlQaClock::guard();
if (($sqlQa ? $db->database : realpath($db->database))!==$target || $db->table('users')->countAllResults()!==0) throw new RuntimeException('Empty migrated synthetic target required.');
if (!$sqlQa && (int) $db->query('PRAGMA foreign_keys')->getRowArray()['foreign_keys'] !== 1) throw new RuntimeException('SQLite foreign-key enforcement must be enabled.');
putenv('IBEMS_SIMULATION_NOW=2026-10-01 04:00:00'); ibems_now();
$checks=0;
function verify(bool $condition,string $message):void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function ok(array $result,string $label):array {verify(($result['status']??'')==='success',$label.': '.json_encode($result));return $result;}
$password=password_hash('Disposable-QA-only!', PASSWORD_BCRYPT);
$now='2026-10-01 04:00:00';
$actors=[1=>'ADMIN',2=>'HR',3=>'ACCOUNTING_HEAD',4=>'CASHIER_HEAD',5=>'BE_DIRECTOR',6=>'PAYROLL_CLERK',7=>'STORE_SYSTEM',8=>'STORE_SYSTEM',9=>'STORE_SYSTEM',10=>'STORE_SYSTEM',11=>'STORE_SYSTEM',12=>'STORE_SYSTEM',13=>'ACCOUNTING_HEAD',20=>'STORE_SUPERVISOR',40=>'COLLECTING_OFFICER',904=>'ACCOUNTING_OFFICE',501=>'USER',502=>'USER',503=>'USER'];
foreach($actors as $id=>$role){
    $type=$id===502?'student':($id===501?'faculty':'staff');
    $db->table('users')->insert(['id'=>$id,'employee_id'=>'BREADTH-'.$id,'name'=>'Synthetic person '.$id,'email'=>'breadth'.$id.'@simulation.ibems.local','password_hash'=>$password,'role'=>$role,'user_type'=>$type,'base_salary'=>$type==='student'?0:40000,'qr_token'=>'BREADTH-'.$id,'is_active'=>1,'created_at'=>$now]);
    foreach(array_unique([$role,'USER']) as $assigned)$db->table('user_roles')->insert(['user_id'=>$id,'role'=>$assigned,'created_at'=>$now]);
    if($type!=='student')$db->table('balances')->insert(['user_id'=>$id,'credit_limit'=>10000,'credit_rate'=>.25,'current_debt'=>0,'updated_at'=>$now]);
}
$hr=new App\Services\HrStaffService();
$schedule=$db->table('salary_schedules')->where('code','PH-NG-2026-T3')->get()->getRowArray();
foreach(['plantilla','cos','part_time'] as $employment)foreach(['faculty','staff'] as $type){
    $input=['employee_id'=>'BREADTH-'.$employment.'-'.$type,'name'=>'Synthetic '.$employment.' '.$type,'email'=>'breadth-'.$employment.'-'.$type.'@simulation.ibems.local','password'=>'Disposable-QA-only!','user_type'=>$type,'employment_type'=>$employment,'salary_schedule_id'=>$schedule['id'],'salary_grade'=>'1','salary_step'=>1];
    ok($hr->create($input,2)['payload'],'HR onboarding '.$employment.' '.$type);
    verify($hr->create($input,2)['code']===409,'Duplicate onboarding rejected');
}
$people=$db->table('users')->select('id,user_type,role,employee_id')->orderBy('id')->get()->getResultArray();
$sourceMode=in_array('--source-config',$argv,true);
$stores=[];$products=[];$foodStores=[];
if($sourceMode){
    $inventory=json_decode(file_get_contents($root.'build/logs/source-inventory.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($inventory['stores'] as $store){$id=(int)$store['id'];$caps=array_column(array_values(array_filter($inventory['capabilities'],static fn($c)=>(int)$c['store_id']===$id)),'capability');$stores[$id]=['name'=>$store['store_name'],'capabilities'=>$caps];}
}else foreach(['retail','production','refill_service','food_service','rental','rental'] as $idx=>$capability)$stores[$idx+1]=['name'=>'Synthetic breadth business '.($idx+1),'capabilities'=>array_values(array_unique(['retail',$capability]))];
foreach($stores as $store=>$configuration){
    $db->table('stores')->insert(['id'=>$store,'store_name'=>'Synthetic clone of '.$configuration['name'],'officer_id'=>$store+6,'is_active'=>1,'created_at'=>$now]);
    $db->table('store_supervisors')->insert(['store_id'=>$store,'user_id'=>20,'created_at'=>$now]);
    (new App\Models\StoreCapabilityModel())->syncForStore($store,$configuration['capabilities']);
    if(!in_array('retail',$configuration['capabilities'],true)&&in_array('food_service',$configuration['capabilities'],true)){
        $template=ok((new App\Services\StoreOfferingService())->mutate('template',['name'=>'Synthetic configured meal','price'=>'1'],$store,$store+6,'STORE_SYSTEM'),'Food-only template');$products[$store]=(int)$template['product_id'];$foodStores[]=$store;
    }else{
        $products[$store]=100+$store;
        $db->table('products')->insert(['id'=>$products[$store],'store_id'=>$store,'sku'=>'BREADTH-P'.$store,'name'=>'Synthetic packaged retail item '.$store,'price'=>1,'stock_qty'=>5000,'item_type'=>'stock_item','stock_policy'=>'tracked','unit_code'=>'piece','is_active'=>1,'updated_at'=>$now]);
    }
}
$tx=new App\Services\TransactionService();$dayService=new App\Services\StoreDayOperationService();$reports=new App\Services\DailyStoreReportService($db);$cards=new App\Services\UserPurchaseCardService($db);$deductions=new App\Services\DeductionBatchService();
$expected=['sales_cents'=>0,'cash_cents'=>0,'debt_cents'=>0,'confirmed_cents'=>0,'transactions'=>0,'reports'=>0,'people'=>[]];
$daily=[];
foreach(range(1,31) as $day){
    $date=sprintf('2026-10-%02d',$day);putenv('IBEMS_SIMULATION_NOW='.$date.' 04:00:00');
    foreach(array_keys($stores) as $store){
        $operator=$store+6;$cash=0;
        ok($dayService->open(['store_id'=>$store,'opening_cash'=>0,'opening_ecash'=>0],$operator,'STORE_SYSTEM')['payload'],'Open business');
        if(in_array($store,$foodStores,true))ok((new App\Services\StoreOfferingService())->mutate('food',['product_id'=>$products[$store],'enabled'=>true,'capacity'=>count($people),'price'=>'1'],$store,$operator,'STORE_SYSTEM'),'Publish configured food-only menu');
        foreach($people as $index=>$person){
            $id=(int)$person['id'];$mode=$person['user_type']==='student'?0:($day+$store+$index)%3;
            $debt=$mode===1?100:($mode===2?50:0);$cashCents=100-$debt;
            $request=['store_id'=>$store,'request_key'=>'breadth-'.$date.'-'.$store.'-'.$id,'customer_user_id'=>$id,'items'=>[['product_id'=>$products[$store],'qty'=>1]]];
            if($debt>0)ok($cards->unlock($id),'Employee unlock');
            if($mode===0)$request+=['payment_method'=>'cash','cash_received'=>1];
            elseif($mode===1)$request+=['payment_method'=>'debt'];
            else $request+=['payments'=>[['payment_method'=>'cash','amount'=>.5,'cash_received'=>.5],['payment_method'=>'debt','amount'=>.5]]];
            $sale=ok($tx->createTransaction($request,$operator,'STORE_SYSTEM'),'Person purchase');
            if($day===1)verify($tx->createTransaction($request,$operator,'STORE_SYSTEM')['transaction_id']===$sale['transaction_id'],'Original receipt retry');
            $expected['sales_cents']+=100;$expected['cash_cents']+=$cashCents;$expected['debt_cents']+=$debt;$expected['transactions']++;
            $expected['people'][$id]??=['employee_id'=>$person['employee_id'],'role'=>$person['role'],'type'=>$person['user_type'],'purchases'=>0,'charge_cents'=>0,'confirmed_cents'=>0];
            $expected['people'][$id]['purchases']++;$expected['people'][$id]['charge_cents']+=$debt;$cash+=$cashCents/100;
        }
        if(in_array($store,$foodStores,true)){
            $offering=$db->table('daily_food_offerings')->where(['store_id'=>$store,'business_date'=>$date])->get()->getRowArray();verify((int)$offering['sold']===count($people),'Prepared servings reconcile to person purchases');
            ok((new App\Services\StoreOfferingService())->mutate('food-close',['offering_id'=>$offering['id'],'leftover'=>0,'disposition'=>'Sold out'],$store,$operator,'STORE_SYSTEM'),'Close sold-out menu');
        }
        ok($dayService->close(['store_id'=>$store,'counted_cash'=>$cash,'counted_ecash'=>0],$operator,'STORE_SYSTEM')['payload'],'Balanced close');
        $session=$db->table('store_day_sessions')->where(['store_id'=>$store,'business_date'=>$date])->get()->getRowArray();
        verify((float)$session['variance_cash']===0.0,'Independent daily cash total');
        $report=ok($reports->submit((int)$session['id'],20,'STORE_SUPERVISOR','Synthetic breadth source reconciliation'),'Supervisor submit');
        $reportId=(int)$report['report']['id'];ok($reports->review($reportId,5,true,'Independent synthetic source totals','BE_DIRECTOR'),'BE approve');ok($reports->receive($reportId,4,'CASHIER_HEAD'),'Cashier receive');$expected['reports']++;
    }
    if(in_array($day,[15,31],true)){
        $period=ok((new App\Services\DeductionPeriodService())->create(['frequency'=>'custom','date_start'=>$day===15?'2026-10-01':'2026-10-16','date_end'=>$date],40),'Cutoff');
        $requests=[];foreach($people as $person)if($person['user_type']!=='student'){
            $id=(int)$person['id'];$owed=$expected['people'][$id]['charge_cents']-$expected['people'][$id]['confirmed_cents'];
            verify((int)round((float)$db->table('balances')->where('user_id',$id)->get()->getRow('current_debt')*100)===$owed,'Pre-payroll person balance');
            $requests[]=['user_id'=>$id,'deduction_choice'=>'full','requested_amount'=>$owed/100];
        }
        $batch=ok($deductions->prepare((int)$period['period']['id'],$requests,40),'Prepare all employees');$batchId=(int)$batch['batch']['id'];ok($deductions->submit($batchId,40),'Submit batch');
        foreach($db->table('deduction_batch_items')->where('batch_id',$batchId)->get()->getResultArray() as $item){
            $id=(int)$item['user_id'];$owed=$expected['people'][$id]['charge_cents']-$expected['people'][$id]['confirmed_cents'];$actual=$day===15?intdiv($owed,2):$owed;
            $payload=['confirmed_amount'=>$actual/100,'result_reference'=>'SYNTHETIC-BREADTH-'.$day.'-'.$id,'reason_code'=>$day===15?'insufficient_salary':''];
            ok($deductions->confirmResult((int)$item['id'],$payload,40),'Record actual payroll');verify(($deductions->confirmResult((int)$item['id'],$payload,40)['idempotent']??false)===true,'Exact payroll retry');
            $expected['confirmed_cents']+=$actual;$expected['people'][$id]['confirmed_cents']+=$actual;
        }
        ok($deductions->reconcile($batchId,40),'Reconcile batch');ok($deductions->finalize($batchId,40),'Finalize batch');
    }
    $daily[]=['date'=>$date,'sales_cents'=>$expected['sales_cents'],'charge_cents'=>$expected['debt_cents'],'confirmed_cents'=>$expected['confirmed_cents']];
    echo 'Breadth completed '.$date.PHP_EOL;
}
$sales=(new App\Services\FinanceSalesReportService($db))->build('2026-10-01','2026-10-31');
$payroll=(new App\Services\FinanceDeductionReportService($db))->build('2026-10-01','2026-10-31');
foreach(['sales_cents','cash_cents','debt_cents'] as $field)verify($sales['totals'][$field]===$expected[$field],'Finance '.$field);
verify($payroll['total_cents']===$expected['confirmed_cents'],'Deduction report');
foreach($expected['people'] as $id=>$person){
    verify($person['purchases']===count($stores)*31,'Every person purchases in every business on every date');
    if($person['type']==='student')continue;
    verify($person['charge_cents']===$person['confirmed_cents'],'Expected final debt zero');
    verify((float)$db->table('balances')->where('user_id',$id)->get()->getRow('current_debt')===0.0,'Persisted final balance');
    $ledger=0;foreach($db->table('debt_cashbook_entries')->where('user_id',$id)->orderBy('id')->get()->getResultArray() as $entry){
        verify((int)round((float)$entry['debt_before']*100)===$ledger,'Debt chain before');$ledger+=($entry['direction']==='debit'?1:-1)*(int)round((float)$entry['amount']*100);verify((int)round((float)$entry['debt_after']*100)===$ledger,'Debt chain after');
    }verify($ledger===0,'Ledger reconciles to balance');
}
foreach(array_keys($stores) as $store)if(!in_array($store,$foodStores,true))verify((int)$db->table('products')->where('id',$products[$store])->get()->getRow('stock_qty')===5000-count($people)*31,'Stock independent reconciliation');
if ($sqlQa) verify(App\Services\SqlQaEvidence::integrity(),'SQL constraint integrity');
else {verify($db->query('PRAGMA integrity_check')->getRow('integrity_check')==='ok','Integrity');verify($db->query('PRAGMA foreign_key_check')->getResultArray()===[],'Foreign keys');}
$result=['status'=>'passed','scope'=>$sourceMode?'All synthetic people, exact four current development-store capability combinations, 31 dates, including a food-only store':'All synthetic people, six retail-capable business configurations, 31 dates; specialized food/rental/department paths are complementary core-month and regression evidence','store_configurations'=>$stores,'people'=>count($people),'checks'=>$checks,'expected'=>$expected,'actual_sales'=>$sales['totals'],'actual_deductions'=>$payroll['total_cents'],'daily'=>$daily];
$result['driver']=$db->DBDriver;
$resultPath=$sqlQa ? dirname(App\Services\SqlQaEvidence::path($target)).'/breadth.json' : $target.'.breadth.json';
if(file_exists($resultPath))throw new RuntimeException('Refusing to replace month evidence');
file_put_contents($resultPath,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'Breadth month reconciled: '.$checks.' checks'.PHP_EOL;
