<?php

// Independent connection for the disposable department-PIN concurrency fixture.
if (getenv('IBEMS_DEPARTMENT_PIN_QA') !== '1' || $argc !== 3) {
    fwrite(STDERR, "This worker requires an explicitly enabled disposable PIN fixture.\n");
    exit(2);
}
$fixture = realpath($argv[1]);
$workerId = filter_var($argv[2], FILTER_VALIDATE_INT);
if ($fixture === false || dirname($fixture) !== realpath(sys_get_temp_dir())
    || !preg_match('/^ibems-pin-qa-[a-f0-9]{16}$/', basename($fixture)) || $workerId < 1 || $workerId > 8
    || !is_file($fixture . '/pin.sqlite') || !is_dir($fixture . '/runtime')) {
    fwrite(STDERR, "Unsafe or missing disposable PIN fixture.\n");
    exit(2);
}

// Bootstrap in testing mode with an isolated runtime and an in-memory default DB.
// Only the explicitly injected connection can access this worker's fixture file.
define('HOMEPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('WRITEPATH', $fixture . '/runtime/');
foreach (['DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'db_'] as $key => $value) {
    putenv('database.tests.' . $key . '=' . $value);
}
putenv('IBEMS_SESSION_DRIVER=files');
require HOMEPATH . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
$db = \Config\Database::connect(['DBDriver' => 'SQLite3', 'database' => $fixture . '/pin.sqlite', 'DBPrefix' => 'pin_', 'DBDebug' => true, 'busyTimeout' => 10000], false);
$db->initialize();
touch($fixture . '/ready-' . $workerId);
$deadline = microtime(true) + 15;
while (!is_file($fixture . '/start') && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($fixture . '/start')) {
    fwrite(STDERR, "PIN concurrency start barrier timed out.\n");
    exit(3);
}
$result = (new \App\Services\DepartmentAuthorizationService($db))->authorize(10, 600, '0000', 700 + $workerId, 91, 50);
echo json_encode(['code' => $result['code'], 'status' => $result['status']]);
$db->close();
