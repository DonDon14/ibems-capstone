<?php
declare(strict_types=1);
$qaRoot=dirname(__DIR__,2).DIRECTORY_SEPARATOR;
$qaTarget=realpath((string)getenv('IBEMS_DATABASE_NAME'));
if(PHP_SAPI!=='cli'||getenv('IBEMS_DATABASE_DRIVER')!=='SQLite3'||!$qaTarget||dirname($qaTarget)!==realpath($qaRoot.'writable/simulation')||basename($qaTarget)!=='ibems-month-simulation-20261024.sqlite')throw new RuntimeException('Exact disposable runtime target required.');
$before=hash_file('sha256',$qaTarget);
define('HOMEPATH',$qaRoot);
define('CONFIGPATH',$qaRoot.'app/Config/');define('PUBLICPATH',$qaRoot.'public/');define('FCPATH',PUBLICPATH);
require CONFIGPATH.'Paths.php';$paths=new Config\Paths();
define('APPPATH',$qaRoot.'app/');define('ROOTPATH',$qaRoot);define('SYSTEMPATH',realpath($paths->systemDirectory).DIRECTORY_SEPARATOR);
define('WRITEPATH',$qaRoot.'writable/');define('TESTPATH',$qaRoot.'tests/');define('CIPATH',dirname(SYSTEMPATH).DIRECTORY_SEPARATOR);
define('SUPPORTPATH',TESTPATH.'_support/');define('COMPOSER_PATH',$qaRoot.'vendor/autoload.php');define('VENDORPATH',$qaRoot.'vendor/');
define('ENVIRONMENT','development');define('CI_DEBUG',true);$_SERVER['CI_ENVIRONMENT']='development';
require SYSTEMPATH.'Boot.php';CodeIgniter\Boot::bootConsole($paths);
$db=Config\Database::connect('default',false);
if(realpath($db->database)!==$qaTarget)throw new RuntimeException('Unexpected runtime target.');
$keys=(int)$db->query('PRAGMA foreign_keys')->getRowArray()['foreign_keys'];
$timeout=(int)$db->query('PRAGMA busy_timeout')->getRowArray()['timeout'];
if($keys!==1||$timeout!==5000)throw new RuntimeException('Runtime safety defaults missing.');
// The invalid row exists only in an attached in-memory schema.
$db->query("ATTACH DATABASE ':memory:' AS qa_probe");
$db->query('CREATE TABLE qa_probe.parents(id INTEGER PRIMARY KEY)');
$db->query('CREATE TABLE qa_probe.children(parent_id INTEGER REFERENCES parents(id))');
$rejected=false;
try{$db->query('INSERT INTO qa_probe.children VALUES(987654)');}catch(Throwable){$rejected=true;}
if(!$rejected||(int)$db->query('SELECT COUNT(*) AS n FROM qa_probe.children')->getRowArray()['n']!==0)throw new RuntimeException('Orphan insert was accepted.');
$db->query('DETACH DATABASE qa_probe');$db->close();
if(hash_file('sha256',$qaTarget)!==$before)throw new RuntimeException('Read-only source snapshot changed.');
echo json_encode(['status'=>'passed','foreign_keys'=>$keys,'busy_timeout_ms'=>$timeout,'orphan_insert_rejected'=>$rejected,'database_sha256_unchanged'=>true],JSON_PRETTY_PRINT).PHP_EOL;
