<?php
declare(strict_types=1);
// Read only census; no application bootstrap, logs, migrations, seeds, or employee rows.
$source='D:/xampp/htdocs/ibems-tailwind-test';
$settings=[];
foreach(file($source.'/.env',FILE_IGNORE_NEW_LINES) as $line){
    if(preg_match('/^\s*(CI_ENVIRONMENT|database\.default\.(hostname|database|DBDriver|username|password|port))\s*=\s*(.*?)\s*$/D',$line,$m))$settings[$m[1]]=trim($m[3]," \t\"'");
}
if(($settings['CI_ENVIRONMENT']??'')!=='development'||($settings['database.default.DBDriver']??'')!=='MySQLi'||!in_array($settings['database.default.hostname']??'', ['localhost','127.0.0.1'],true)||($settings['database.default.database']??'')!=='ibems_tailwind_test')throw new RuntimeException('Only the confirmed local development database may be inventoried.');
try {
    $pdo=new PDO('mysql:host=127.0.0.1;port='.($settings['database.default.port']??3306).';dbname=ibems_tailwind_test;charset=utf8mb4',$settings['database.default.username']??'',$settings['database.default.password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $result=['source'=>'confirmed local development database','mode'=>'READ ONLY transaction','stores'=>$pdo->query('SELECT id,store_name,is_active FROM stores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
    if(in_array('store_capabilities',$tables,true))$result['capabilities']=$pdo->query('SELECT store_id,capability FROM store_capabilities ORDER BY store_id,capability')->fetchAll(PDO::FETCH_ASSOC);
    if(in_array('departments',$tables,true))$result['departments']=$pdo->query('SELECT id,code,name,is_active FROM departments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if(in_array('user_roles',$tables,true))$result['role_counts']=$pdo->query('SELECT role,COUNT(*) AS assigned FROM user_roles GROUP BY role ORDER BY role')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->rollBack();
    file_put_contents(dirname(__DIR__,2).'/build/logs/source-inventory.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['status'=>'read_only_inventory_saved','stores'=>count($result['stores']),'departments'=>count($result['departments']??[])],JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'Local read-only inventory unavailable; connection details suppressed.'.PHP_EOL);exit(1);}
