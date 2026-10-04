<?php
declare(strict_types=1);
// Adapt only the SQLite orchestration guards/evidence/PRAGMAs. Business services and expectations are unchanged.
$root=dirname(__DIR__,2).DIRECTORY_SEPARATOR;
if(PHP_SAPI!=='cli'||getenv('IBEMS_SQL_MONTH_QA')!=='1')throw new RuntimeException('SQL QA opt-in required.');
define('HOMEPATH',$root);define('CONFIGPATH',$root.'app/Config/');define('PUBLICPATH',$root.'public/');define('FCPATH',PUBLICPATH);
require CONFIGPATH.'Paths.php';$paths=new Config\Paths();
define('APPPATH',$root.'app/');define('ROOTPATH',$root);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);define('WRITEPATH',$root.'writable/');define('TESTPATH',$root.'tests/');define('CIPATH',dirname(SYSTEMPATH).DIRECTORY_SEPARATOR);define('SUPPORTPATH',TESTPATH.'_support/');define('COMPOSER_PATH',$root.'vendor/autoload.php');define('VENDORPATH',$root.'vendor/');define('ENVIRONMENT','development');define('CI_DEBUG',true);$_SERVER['CI_ENVIRONMENT']='development';
require __DIR__.'/sql_month_support.php';require SYSTEMPATH.'Boot.php';CodeIgniter\Boot::bootConsole($paths);
App\Services\SqlQaClock::guard();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$db=Config\Database::connect();$scenario=$argv[1]??'';$action=$argv[2]??'';
$day=new App\Services\StoreDayOperationService();$tx=new App\Services\TransactionService();
$ok=static function(array $r):array{if(($r['status']??'')!=='success')throw new RuntimeException(json_encode($r));return $r;};
if($action==='seed'){
 if($db->table('users')->countAllResults()!==0)throw new RuntimeException('Empty synthetic schema required');
 $now=ibems_now()->format('Y-m-d H:i:s');
 foreach([7=>'STORE_SYSTEM',8=>'STORE_SYSTEM',501=>'USER'] as $id=>$role){$db->table('users')->insert(['id'=>$id,'employee_id'=>'SQL-CREDIT-'.$id,'qr_token'=>'SQL-CREDIT-'.$id,'name'=>'Synthetic credit '.$id,'email'=>'sql-credit-'.$id.'@simulation.ibems.local','password_hash'=>password_hash('Synthetic-only!',PASSWORD_BCRYPT),'debt_pin_hash'=>password_hash('246810',PASSWORD_BCRYPT),'role'=>$role,'user_type'=>$id===501?'faculty':'staff','base_salary'=>40000,'is_active'=>1,'created_at'=>$now]);$db->table('user_roles')->insert(['user_id'=>$id,'role'=>$role,'created_at'=>$now]);}
 $db->table('balances')->insert(['user_id'=>501,'credit_limit'=>190,'credit_rate'=>.25,'current_debt'=>0,'updated_at'=>$now]);
 foreach([1=>7,2=>8] as $store=>$actor){$db->table('stores')->insert(['id'=>$store,'store_name'=>'Synthetic credit store '.$store,'officer_id'=>$actor,'is_active'=>1,'created_at'=>$now]);(new App\Models\StoreCapabilityModel())->syncForStore($store,['retail']);$db->table('products')->insert(['id'=>100+$store,'store_id'=>$store,'sku'=>'SQL-CREDIT-'.$store,'name'=>'Synthetic credit item','price'=>55,'stock_qty'=>10,'item_type'=>'stock_item','stock_policy'=>'tracked','is_active'=>1,'updated_at'=>$now]);$ok($day->open(['store_id'=>$store,'opening_cash'=>0,'opening_ecash'=>0],$actor,'STORE_SYSTEM')['payload']);}
 // Supported legacy PIN mode, solely in this marked disposable SQL fixture.
 $db->query('DROP TABLE user_purchase_cards');
 $ok($tx->createTransaction(['request_key'=>'sql-credit-opening','store_id'=>1,'payment_method'=>'debt','customer_user_id'=>501,'debt_pin'=>'246810','items'=>[['product_id'=>101,'qty'=>2]]],7,'STORE_SYSTEM'));
 $db->table('products')->update(['stock_qty'=>6]);
 if(str_starts_with($scenario,'boundary'))$db->table('products')->where('id',101)->update(['price'=>$scenario==='boundary-exact'?'80.00':'80.01']);
 echo json_encode(['seeded'=>true,'debt'=>110,'credit_limit'=>190]);exit;
}
if($db->table('users')->where('id',501)->get()->getRow('employee_id')!=='SQL-CREDIT-501'||$db->table('users')->countAllResults()!==3)throw new RuntimeException('Unexpected credit fixture population');
if($action==='hold'){
 $lock='qa_credit_'.$db->database;
 $db->query("CREATE TRIGGER qa_credit_hold BEFORE UPDATE ON balances FOR EACH ROW BEGIN DO GET_LOCK('".$lock."',0); DO SLEEP(3.0); END");echo json_encode(['installed'=>true,'lock'=>$lock]);exit;
}
if($action==='state'){
 $state=['balance'=>$db->table('balances')->where('user_id',501)->get()->getRowArray(),'stock'=>$db->table('products')->select('id,stock_qty')->orderBy('id')->get()->getResultArray(),'counts'=>[]];
 foreach(['transactions','transaction_payments','debt_cashbook_entries','pos_request_receipts'] as $table)$state['counts'][$table]=$db->table($table)->countAllResults();
 echo json_encode($state);exit;
}
$store=$scenario==='cross-store'&&$action==='sale2'?2:1;$started=microtime(true);
$r=$tx->createTransaction(['request_key'=>'sql-credit-'.$scenario.'-'.$action,'store_id'=>$store,'payment_method'=>'debt','customer_user_id'=>501,'debt_pin'=>'246810','items'=>[['product_id'=>100+$store,'qty'=>1]]],$store===2?8:7,'STORE_SYSTEM');
echo json_encode(['started'=>$started,'finished'=>microtime(true),'response'=>$r],JSON_THROW_ON_ERROR),PHP_EOL;
