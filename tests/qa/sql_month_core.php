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
$source=file_get_contents(APPPATH.'Commands/IbemsSimulateMonth.php');
$replacements=[
    'final class IbemsSimulateMonth'=>'final class SqlQaCoreMonth',
    "\$db->DBDriver === 'SQLite3' && realpath(\$db->database) === realpath(getenv('IBEMS_DATABASE_NAME'))"=>"\$db->DBDriver === 'MySQLi' && \$db->database === getenv('IBEMS_DATABASE_NAME')",
    'MonthSimulationEvidence::'=>'\\App\\Services\\SqlQaEvidence::',
    "\$db->query('PRAGMA integrity_check')->getRowArray()['integrity_check']==='ok'"=>'\\App\\Services\\SqlQaEvidence::integrity()',
    "\$db->query('PRAGMA foreign_key_check')->getResultArray()===[]"=>'\\App\\Services\\SqlQaEvidence::integrity()',
];
foreach($replacements as $before=>$after){$count=substr_count($source,$before);if($count!==($before==='MonthSimulationEvidence::'?2:1))throw new RuntimeException('Core source changed; review SQL adaptation');$source=str_replace($before,$after,$source);}
$folder=dirname(App\Services\SqlQaEvidence::path(getenv('IBEMS_DATABASE_NAME')));
file_put_contents($folder.'/adapted-core.php',$source);
require $folder.'/adapted-core.php';
(new App\Commands\SqlQaCoreMonth(service('logger'), service('commands')))->run([]);
