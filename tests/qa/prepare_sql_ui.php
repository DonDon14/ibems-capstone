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
$db=Config\Database::connect();
if($db->table('users')->where('id',501)->get()->getRow('employee_id')!=='SIM-501'||$db->table('transactions')->countAllResults()!==251||$db->table('users')->where('id',904)->countAllResults()!==0)throw new RuntimeException('Untouched completed synthetic core month required.');
$db->query("INSERT INTO users (id,employee_id,name,email,password_hash,role,user_type,qr_token,base_salary,is_active,created_at) SELECT 904,'SIM-904','Simulation Legacy Accounting','legacy-accounting@simulation.ibems.local',password_hash,'ACCOUNTING_OFFICE','staff','SIM-904',40000,1,created_at FROM users WHERE id=3");
$db->table('user_roles')->insert(['user_id'=>904,'role'=>'ACCOUNTING_OFFICE','created_at'=>'2026-11-01 04:00:00']);
$db->table('balances')->where('user_id',501)->update(['credit_limit'=>150]);
echo "Prepared synthetic MariaDB browser fixture.\n";
