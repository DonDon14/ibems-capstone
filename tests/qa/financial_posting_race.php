<?php

// Opt-in only: independent connections to an explicitly guarded disposable fixture.
if (getenv('IBEMS_FINANCE_QA') !== '1' || !in_array($argv[1] ?? '', ['sqlite', 'mariadb', 'worker'], true)) {
    fwrite(STDERR, "Enable IBEMS_FINANCE_QA=1 and select sqlite or mariadb.\n");
    exit(2);
}
$worker = $argv[1] === 'worker';
$driver = $worker ? ($argv[3] ?? '') : $argv[1];
if (!in_array($driver, ['sqlite', 'mariadb'], true)) exit(2);
$fixture = $worker ? realpath($argv[2] ?? '') : sys_get_temp_dir() . '/ibems-finance-qa-' . bin2hex(random_bytes(8));
if (!$worker) { mkdir($fixture); mkdir($fixture . '/runtime'); }
if (!$fixture || realpath(dirname($fixture)) !== realpath(sys_get_temp_dir()) || !preg_match('/^ibems-finance-qa-[a-f0-9]{16}$/D', basename($fixture)) || !is_dir($fixture . '/runtime')) exit(2);
$schema = str_replace('-', '_', basename($fixture));
define('HOMEPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('WRITEPATH', $fixture . '/runtime/');
foreach (['DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => ''] as $key => $value) putenv('database.tests.' . $key . '=' . $value);
putenv('IBEMS_SESSION_DRIVER=files');
putenv('IBEMS_SIMULATION=');
require HOMEPATH . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

function financeQaConnection(string $schema): array
{
    if ($schema !== '' && !preg_match('/^ibems_finance_qa_[a-f0-9]{16}$/D', $schema)) throw new RuntimeException('Unsafe QA schema.');
    return ['DBDriver' => 'MySQLi', 'hostname' => '127.0.0.1', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => $schema, 'DBPrefix' => '', 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci'];
}
$qa = $driver === 'sqlite' ? ['DBDriver' => 'SQLite3', 'database' => $fixture . '/finance.sqlite', 'DBPrefix' => '', 'DBDebug' => true, 'busyTimeout' => 10000] : financeQaConnection($schema);
$configuration = config(\Config\Database::class);
$configuration->tests = $qa;
$configuration->default = $qa;
$configuration->defaultGroup = 'tests';

if ($worker) {
    $scenario = $argv[4] ?? '';
    $id = filter_var($argv[5] ?? '', FILTER_VALIDATE_INT);
    if (!in_array($scenario, ['source', 'same-case', 'same-key', 'distinct-keys'], true) || $id < 1 || $id > 8) exit(2);
    $db = \Config\Database::connect();
    $db->initialize();
    touch($fixture . '/' . $scenario . '-ready-' . $id);
    $deadline = microtime(true) + 20;
    while (!is_file($fixture . '/' . $scenario . '-start') && microtime(true) < $deadline) usleep(1000);
    if (!is_file($fixture . '/' . $scenario . '-start')) exit(3);
    $result = in_array($scenario, ['source', 'same-case'], true)
        ? (new \App\Services\DebtInvestigationService())->approveAndPost($scenario === 'same-case' ? 1 : $id, 901)
        : (new \App\Services\DepartmentDebtService($db))->recordSettlement(1, 10, 50, 'SYNTHETIC-REF', 'Synthetic payment', 900, 'synthetic-payment-key-' . ($scenario === 'same-key' ? 1 : $id));
    echo json_encode(['code' => $result['code'], 'status' => $result['status']]);
    exit;
}

$server = null;
$created = false;
$db = null;
$processes = [];
$exitCode = 1;
try {
    if ($driver === 'mariadb') {
        $server = \Config\Database::connect(financeQaConnection(''), false);
        $server->query('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $created = true;
    }
    $db = \Config\Database::connect();
    $definitions = [
        'users' => 'id INTEGER PRIMARY KEY',
        'transactions' => 'id INTEGER PRIMARY KEY, user_id INTEGER, amount DECIMAL(12,2), payment_method VARCHAR(20), status VARCHAR(20)',
        'balances' => 'user_id INTEGER PRIMARY KEY, credit_limit DECIMAL(12,2), current_debt DECIMAL(12,2), updated_at VARCHAR(30)',
        'audit_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action VARCHAR(100), entity VARCHAR(100), entity_id INTEGER, payload_json TEXT, created_at VARCHAR(30)',
        'debt_cashbook_entries' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, entry_type VARCHAR(60), direction VARCHAR(20), amount DECIMAL(12,2), debt_before DECIMAL(12,2), debt_after DECIMAL(12,2), credit_limit_snapshot DECIMAL(12,2), available_credit_snapshot DECIMAL(12,2), reference_type VARCHAR(60), reference_id INTEGER, actor_id INTEGER, remarks TEXT, meta_json TEXT, created_at VARCHAR(30), updated_at VARCHAR(30)',
        'debt_investigations' => 'id INTEGER PRIMARY KEY, user_id INTEGER, transaction_id INTEGER, status VARCHAR(30), recommended_action VARCHAR(40), recommended_amount DECIMAL(12,2), recommended_by INTEGER, approved_by INTEGER, posted_by INTEGER, reversal_cashbook_entry_id INTEGER, approved_at VARCHAR(30), posted_at VARCHAR(30), closed_at VARCHAR(30), updated_at VARCHAR(30)',
        'department_debt_periods' => 'id INTEGER PRIMARY KEY, department_id INTEGER, allocation_amount DECIMAL(12,2), used_amount DECIMAL(12,2), outstanding_amount DECIMAL(12,2), updated_at VARCHAR(30)',
        'department_debt_entries' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER, period_id INTEGER, entry_type VARCHAR(40), direction VARCHAR(20), amount DECIMAL(12,2), allocation_before DECIMAL(12,2), allocation_after DECIMAL(12,2), used_before DECIMAL(12,2), used_after DECIMAL(12,2), outstanding_before DECIMAL(12,2), outstanding_after DECIMAL(12,2), actor_id INTEGER, reference_no VARCHAR(100), remarks TEXT, created_at VARCHAR(30)',
        'department_settlement_receipts' => 'request_key VARCHAR(64) PRIMARY KEY, request_hash VARCHAR(64), settlement_entry_id INTEGER, response_json TEXT, created_at VARCHAR(30)',
    ];
    foreach ($definitions as $table => $definition) {
        if ($driver === 'mariadb') $definition = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT PRIMARY KEY AUTO_INCREMENT', $definition);
        $db->query('CREATE TABLE ' . $table . ' (' . $definition . ')' . ($driver === 'mariadb' ? ' ENGINE=InnoDB' : ''));
    }
    $reports = [];
    foreach (['source', 'same-case', 'same-key', 'distinct-keys'] as $scenario) {
        foreach (array_keys($definitions) as $table) $db->table($table)->emptyTable();
        $db->resetTransStatus();
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 2000, 'current_debt' => 1000]);
        $db->table('transactions')->insert(['id' => 10, 'user_id' => 501, 'amount' => 100, 'payment_method' => 'debt', 'status' => 'completed']);
        for ($id = 1; $id <= 8; $id++) $db->table('debt_investigations')->insert(['id' => $id, 'user_id' => 501, 'transaction_id' => 10, 'status' => 'recommended', 'recommended_action' => 'partial_reversal', 'recommended_amount' => 20, 'recommended_by' => 900]);
        $db->table('department_debt_periods')->insert(['id' => 10, 'department_id' => 1, 'allocation_amount' => 500, 'used_amount' => 200, 'outstanding_amount' => 200]);
        $processes = [];
        for ($id = 1; $id <= 8; $id++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $fixture, $driver, $scenario, (string) $id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
            if (!is_resource($process)) throw new RuntimeException('Worker creation failed.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 25;
        while (count(glob($fixture . '/' . $scenario . '-ready-*')) !== 8 && microtime(true) < $deadline) usleep(10000);
        if (count(glob($fixture . '/' . $scenario . '-ready-*')) !== 8) throw new RuntimeException('Worker barrier timed out.');
        touch($fixture . '/' . $scenario . '-start');
        $codes = [];
        foreach ($processes as [$process, $pipes]) {
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(10000);
            if (proc_get_status($process)['running']) throw new RuntimeException('Worker completion timed out.');
            $output = stream_get_contents($pipes[1]);
            if (stream_get_contents($pipes[2]) !== '') throw new RuntimeException('Worker emitted an error.');
            $codes[] = json_decode($output, true)['code'] ?? 0;
        }
        $expectedSuccess = ['source' => 5, 'same-case' => 1, 'same-key' => 8, 'distinct-keys' => 4][$scenario];
        if (count(array_filter($codes, static fn ($c) => $c === 200)) !== $expectedSuccess || count(array_filter($codes, static fn ($c) => $c === 409)) !== 8 - $expectedSuccess) throw new RuntimeException('Unexpected race outcomes: ' . $scenario . ' ' . json_encode($codes));
        $entries = in_array($scenario, ['source', 'same-case'], true) ? $db->table('debt_cashbook_entries')->orderBy('debt_before', 'DESC')->get()->getResultArray() : $db->table('department_debt_entries')->orderBy('outstanding_before', 'DESC')->get()->getResultArray();
        $expectedCount = $scenario === 'same-key' ? 1 : $expectedSuccess;
        if (count($entries) !== $expectedCount || $db->table('audit_logs')->countAllResults() !== $expectedCount) throw new RuntimeException('Duplicate or missing posting/audit.');
        $reversal = in_array($scenario, ['source', 'same-case'], true);
        $running = $reversal ? 1000.0 : 200.0;
        foreach ($entries as $entry) {
            if ((float) $entry[$reversal ? 'debt_before' : 'outstanding_before'] !== $running) throw new RuntimeException('Incorrect before snapshot.');
            $running -= (float) $entry['amount'];
            if ((float) $entry[$reversal ? 'debt_after' : 'outstanding_after'] !== $running) throw new RuntimeException('Incorrect after snapshot.');
        }
        $actual = $reversal ? $db->table('balances')->get()->getRow()->current_debt : $db->table('department_debt_periods')->get()->getRow()->outstanding_amount;
        if ((float) $actual !== $running) throw new RuntimeException('Balance diverged from ledger.');
        if (!$reversal && $db->table('department_settlement_receipts')->countAllResults() !== $expectedCount) throw new RuntimeException('Receipt count mismatch.');
        $reports[] = ['scenario' => $scenario, 'workers' => 8, 'success_responses' => $expectedSuccess, 'posting_rows' => $expectedCount, 'final_balance' => $running];
        foreach ($processes as [$process, $pipes]) { fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
        $processes = [];
    }
    echo json_encode(['status' => 'passed', 'driver' => $driver, 'scenarios' => $reports], JSON_PRETTY_PRINT), PHP_EOL;
    $exitCode = 0;
} catch (Throwable $error) {
    fwrite(STDERR, 'Disposable financial race verification failed: ' . $error->getMessage() . PHP_EOL);
} finally {
    foreach ($processes as [$process, $pipes]) { if (proc_get_status($process)['running']) proc_terminate($process); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
    if ($db) $db->close();
    if ($created && $server) { $server->query('DROP DATABASE `' . $schema . '`'); echo "Disposable MariaDB schema removed.\n"; }
    if ($server) $server->close();
}
exit($exitCode);
