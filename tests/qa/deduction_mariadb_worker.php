<?php

declare(strict_types=1);

use App\Services\DeductionBatchService;
use CodeIgniter\Boot;
use Config\Database;
use Config\Paths;

if (PHP_SAPI !== 'cli'
    || getenv('IBEMS_MARIADB_RACE_QA') !== '1'
    || getenv('IBEMS_DATABASE_DRIVER') !== 'MySQLi'
    || !in_array(getenv('IBEMS_DATABASE_HOSTNAME'), ['localhost', '127.0.0.1'], true)
    || !preg_match('/^ibems_ifr_migqa_\d{8}_[a-z0-9]{6}$/D', (string) getenv('IBEMS_DATABASE_NAME'))) {
    fwrite(STDERR, "This probe requires an explicitly named local MariaDB QA schema.\n");
    exit(2);
}

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
define('HOMEPATH', $root);
define('CONFIGPATH', $root . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $root . 'public' . DIRECTORY_SEPARATOR);
define('FCPATH', PUBLICPATH);
require CONFIGPATH . 'Paths.php';
$paths = new Paths();
define('APPPATH', $root . 'app' . DIRECTORY_SEPARATOR);
define('ROOTPATH', $root);
define('SYSTEMPATH', rtrim($paths->systemDirectory, '\\/') . DIRECTORY_SEPARATOR);
define('WRITEPATH', $root . 'writable' . DIRECTORY_SEPARATOR);
define('TESTPATH', $root . 'tests' . DIRECTORY_SEPARATOR);
define('CIPATH', dirname(SYSTEMPATH) . DIRECTORY_SEPARATOR);
define('SUPPORTPATH', TESTPATH . '_support' . DIRECTORY_SEPARATOR);
define('COMPOSER_PATH', $root . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
define('VENDORPATH', $root . 'vendor' . DIRECTORY_SEPARATOR);
$_SERVER['CI_ENVIRONMENT'] = 'development';
define('ENVIRONMENT', 'development');
define('CI_DEBUG', true);
require SYSTEMPATH . 'Boot.php';
Boot::bootTest($paths);

$db = Database::connect();
if ($db->DBDriver !== 'MySQLi'
    || $db->database !== getenv('IBEMS_DATABASE_NAME')
    || !in_array($db->hostname, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "The active database is outside the guarded QA schema.\n");
    exit(2);
}

$mode = $argv[1] ?? '';
if ($mode === 'probe') {
    echo json_encode([
        'database' => $db->database,
        'driver' => $db->DBDriver,
        'users' => $db->tableExists('users') ? $db->table('users')->countAllResults() : null,
    ]), PHP_EOL;
    exit;
}

if ($mode === 'verify') {
    $expect = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException('QA verification failed: ' . $message);
        }
    };
    $cents = static fn ($value): int => (int) round((float) $value * 100);
    $expect($db->table('users')->countAllResults() === 2, 'expected only the two synthetic users');
    $actor = $db->table('users')->where('email', 'qa-race-accounting@ibems.local')->get()->getRowArray();
    $employee = $db->table('users')->where('email', 'qa-race-employee@ibems.local')->get()->getRowArray();
    $expect($actor !== null && $employee !== null, 'synthetic actor or employee is missing');
    $actorId = (int) $actor['id'];
    $employeeId = (int) $employee['id'];
    $balance = $db->table('balances')->where('user_id', $employeeId)->get()->getRowArray();
    $expect($balance !== null && $cents($balance['current_debt']) === 4000, 'remaining employee debt must be PHP 40');

    $expected = [
        'QA-RACE-202609-A' => ['status' => 'finalized', 'requested' => 10000, 'confirmed' => 6000, 'carryover' => 4000, 'choice' => 'full'],
        'QA-RACE-202609-B' => ['status' => 'submitted', 'requested' => 4000, 'confirmed' => 0, 'carryover' => 0, 'choice' => 'full'],
        'QA-RACE-202610-A' => ['status' => 'submitted', 'requested' => 2000, 'confirmed' => 0, 'carryover' => 0, 'choice' => 'partial'],
    ];
    $expect($db->table('deduction_periods')->countAllResults() === 3, 'expected exactly three synthetic periods');
    $expect($db->table('deduction_batches')->countAllResults() === 3, 'expected exactly three synthetic batches');
    $summary = [];
    foreach ($expected as $code => $values) {
        $period = $db->table('deduction_periods')->where('period_code', $code)->get()->getRowArray();
        $expect($period !== null && $period['status'] === $values['status'], $code . ' period status');
        $batch = $db->table('deduction_batches')->where('period_id', (int) $period['id'])->get()->getRowArray();
        $expect($batch !== null && $batch['status'] === $values['status'] && (int) $batch['created_by'] === $actorId, $code . ' batch status or actor');
        $batchId = (int) $batch['id'];
        $item = $db->table('deduction_batch_items')->where('batch_id', $batchId)->get()->getResultArray();
        $expect(count($item) === 1 && (int) $item[0]['user_id'] === $employeeId, $code . ' batch item count or owner');
        $item = $item[0];
        $expect($cents($batch['total_requested']) === $values['requested']
            && $cents($batch['total_confirmed']) === $values['confirmed']
            && $cents($batch['total_carryover']) === $values['carryover']
            && $cents($item['requested_amount']) === $values['requested']
            && $cents($item['confirmed_amount']) === $values['confirmed']
            && $cents($item['carryover_amount']) === $values['carryover']
            && $item['deduction_choice'] === $values['choice'], $code . ' batch and item amounts or choice');
        $audits = $db->table('audit_logs')->where('entity', 'deduction_batches')->where('entity_id', $batchId)->get()->getResultArray();
        $countAction = static fn (string $action): int => count(array_filter($audits, static fn (array $row): bool => $row['action'] === $action));
        $expect($countAction('ACCOUNTING_PREPARE_DEDUCTION_BATCH') === 1
            && $countAction('ACCOUNTING_SUBMIT_DEDUCTION_BATCH') === 1, $code . ' prepare/submit audit count');
        $expectedEditCount = $code === 'QA-RACE-202610-A' ? 1 : 0;
        $expect($countAction('ACCOUNTING_EDIT_DEDUCTION_BATCH') === $expectedEditCount
            && $countAction('ACCOUNTING_EDIT_DEDUCTION_ITEM') === $expectedEditCount, $code . ' edit audit count');
        $submitAudit = array_values(array_filter($audits, static fn (array $row): bool => $row['action'] === 'ACCOUNTING_SUBMIT_DEDUCTION_BATCH'))[0];
        $payload = json_decode((string) $submitAudit['payload_json'], true);
        $expect(is_array($payload) && $cents($payload['total_requested'] ?? null) === $values['requested'], $code . ' submitted audit amount');
        $summary[$code] = ['status' => $batch['status'], 'requested' => $batch['total_requested'], 'confirmed' => $batch['total_confirmed'], 'carryover' => $batch['total_carryover']];
        if ($code === 'QA-RACE-202609-A') {
            $expect($countAction('ACCOUNTING_RECONCILE_DEDUCTION_BATCH') === 1
                && $countAction('ACCOUNTING_FINALIZE_DEDUCTION_BATCH') === 1, 'first batch reconcile/finalize audit count');
            $confirmAudits = $db->table('audit_logs')->where('action', 'ACCOUNTING_CONFIRM_DEDUCTION_RESULT')
                ->where('entity', 'deduction_batch_items')->where('entity_id', (int) $item['id'])->countAllResults();
            $expect($confirmAudits === 1 && $item['result_status'] === 'partially_deducted', 'first item confirmation');
            $cashbook = $db->table('debt_cashbook_entries')->where('reference_type', 'deduction_batch_item')
                ->where('reference_id', (int) $item['id'])->get()->getResultArray();
            $expect(count($cashbook) === 1 && $cashbook[0]['direction'] === 'credit'
                && $cents($cashbook[0]['amount']) === 6000, 'first item cashbook credit');
        } else {
            $expect($item['result_status'] === 'pending', $code . ' item must remain pending');
        }
    }
    $delayTriggers = $db->query('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?',
        [$db->database, 'qa_delay_deduction_item'])->getRowArray();
    $expect((int) ($delayTriggers['n'] ?? -1) === 0, 'temporary QA delay trigger still exists');
    echo json_encode(['verified' => true, 'database' => $db->database, 'balance' => $balance['current_debt'], 'batches' => $summary]), PHP_EOL;
    exit;
}

if ($mode === 'seed') {
    if ($db->table('users')->countAllResults() !== 0
        || $db->table('deduction_periods')->countAllResults() !== 0
        || $db->table('deduction_batches')->countAllResults() !== 0
        || $db->table('debt_cashbook_entries')->countAllResults() !== 0) {
        throw new RuntimeException('The QA schema is not empty; the fixture will not overwrite it.');
    }
    $now = date('Y-m-d H:i:s');
    $db->transBegin();
    try {
        $insert = static function (string $table, array $data) use ($db): void {
            if (!$db->table($table)->insert($data)) {
                throw new RuntimeException('QA insert failed for ' . $table . ': ' . (string) ($db->error()['message'] ?? 'unknown error'));
            }
        };
        $userBase = [
            'is_active' => 1,
            'created_at' => $now,
        ];
        $insert('users', $userBase + [
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            'qr_token' => bin2hex(random_bytes(16)),
            'employee_id' => 'QA-RACE-ACTOR', 'name' => 'QA Race Accounting',
            'email' => 'qa-race-accounting@ibems.local', 'role' => 'ACCOUNTING_HEAD',
            'user_type' => 'staff', 'base_salary' => 0,
        ]);
        $actorId = (int) $db->insertID();
        $insert('user_roles', [
            'user_id' => $actorId, 'role' => 'ACCOUNTING_HEAD',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $insert('users', $userBase + [
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            'qr_token' => bin2hex(random_bytes(16)),
            'employee_id' => 'QA-RACE-EMPLOYEE', 'name' => 'QA Race Employee',
            'email' => 'qa-race-employee@ibems.local', 'role' => 'USER',
            'user_type' => 'faculty', 'base_salary' => 1000,
        ]);
        $employeeId = (int) $db->insertID();
        $insert('balances', [
            'user_id' => $employeeId, 'credit_limit' => 250,
            'current_debt' => 100, 'credit_rate' => 0.25, 'updated_at' => $now,
        ]);
        $insert('debt_cashbook_entries', [
            'user_id' => $employeeId, 'entry_type' => 'qa_opening_debt',
            'direction' => 'debit', 'amount' => 100, 'debt_before' => 0,
            'debt_after' => 100, 'credit_limit_snapshot' => 250,
            'available_credit_snapshot' => 150, 'reference_type' => 'qa_fixture',
            'actor_id' => $actorId, 'remarks' => 'Synthetic MariaDB concurrency QA',
            'created_at' => '2026-09-09 04:00:00', 'updated_at' => '2026-09-09 04:00:00',
        ]);
        $insert('deduction_periods', [
            'period_code' => 'QA-RACE-202609-A', 'label' => 'QA Race September 1-15, 2026',
            'frequency' => 'semi_monthly', 'date_start' => '2026-09-01',
            'date_end' => '2026-09-15', 'status' => 'draft',
            'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $periodId = (int) $db->insertID();
        if ($db->transStatus() === false || !$db->transCommit()) {
            throw new RuntimeException('The synthetic QA fixture could not be committed.');
        }
    } catch (Throwable $error) {
        $db->transRollback();
        throw $error;
    }
    $prepared = (new DeductionBatchService())->prepare($periodId, [
        ['user_id' => $employeeId, 'requested_amount' => '100.00', 'deduction_choice' => 'full'],
    ], $actorId);
    if (($prepared['status'] ?? '') !== 'success') {
        throw new RuntimeException('The synthetic batch could not be prepared: ' . ($prepared['message'] ?? 'unknown error'));
    }
    $batchId = (int) $prepared['batch']['id'];
    $itemId = (int) $db->table('deduction_batch_items')->where('batch_id', $batchId)->get()->getRow()->id;
    echo json_encode(compact('actorId', 'employeeId', 'periodId', 'batchId', 'itemId')), PHP_EOL;
    exit;
}

if ($mode === 'seed-edit') {
    $slot = (string) ($argv[2] ?? 'B');
    $periods = [
        'B' => ['code' => 'QA-RACE-202609-B', 'label' => 'QA Race September 16-30, 2026', 'start' => '2026-09-16', 'end' => '2026-09-30'],
        'C' => ['code' => 'QA-RACE-202610-A', 'label' => 'QA Race October 1-15, 2026', 'start' => '2026-10-01', 'end' => '2026-10-15'],
    ];
    $period = $periods[$slot] ?? null;
    if (!$period) {
        throw new InvalidArgumentException('Only the named B and C QA edit periods are supported.');
    }
    $actor = $db->table('users')->where('email', 'qa-race-accounting@ibems.local')->get()->getRowArray();
    $employee = $db->table('users')->where('email', 'qa-race-employee@ibems.local')->get()->getRowArray();
    $first = $db->table('deduction_periods')->where('period_code', 'QA-RACE-202609-A')->get()->getRowArray();
    $balance = $employee ? $db->table('balances')->where('user_id', (int) $employee['id'])->get()->getRowArray() : null;
    if (!$actor || !$employee || !$first || $first['status'] !== 'finalized'
        || !$balance || round((float) $balance['current_debt'], 2) !== 40.0
        || $db->table('deduction_periods')->where('period_code', $period['code'])->countAllResults() !== 0) {
        throw new RuntimeException('The completed first QA fixture is required before the edit race.');
    }
    $now = date('Y-m-d H:i:s');
    if (!$db->table('deduction_periods')->insert([
        'period_code' => $period['code'], 'label' => $period['label'],
        'frequency' => 'semi_monthly', 'date_start' => $period['start'],
        'date_end' => $period['end'], 'status' => 'draft',
        'created_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
    ])) {
        throw new RuntimeException('The second synthetic period could not be created: ' . (string) ($db->error()['message'] ?? 'unknown error'));
    }
    $periodId = (int) $db->insertID();
    $prepared = (new DeductionBatchService())->prepare($periodId, [
        ['user_id' => (int) $employee['id'], 'requested_amount' => '40.00', 'deduction_choice' => 'full'],
    ], (int) $actor['id']);
    if (($prepared['status'] ?? '') !== 'success') {
        throw new RuntimeException('The second synthetic batch could not be prepared: ' . ($prepared['message'] ?? 'unknown error'));
    }
    $batchId = (int) $prepared['batch']['id'];
    $itemId = (int) $db->table('deduction_batch_items')->where('batch_id', $batchId)->get()->getRow()->id;
    echo json_encode(['actorId' => (int) $actor['id'], 'employeeId' => (int) $employee['id'], 'periodId' => $periodId, 'batchId' => $batchId, 'itemId' => $itemId]), PHP_EOL;
    exit;
}

if ($mode === 'act') {
    $action = $argv[2] ?? '';
    $targetMs = (int) ($argv[3] ?? 0);
    $batchId = (int) ($argv[4] ?? 0);
    $actorId = (int) ($argv[5] ?? 0);
    $itemId = (int) ($argv[6] ?? 0);
    if (!in_array($action, ['edit', 'submit', 'confirm', 'reconcile', 'finalize'], true)
        || $targetMs <= 0 || $batchId <= 0 || $actorId <= 0 || $itemId <= 0) {
        throw new InvalidArgumentException('Action, target time, and IDs are required.');
    }
    $fixture = $db->table('deduction_batches b')
        ->select('u.email AS actor_email, p.period_code, p.id AS period_id, i.id AS item_id, i.user_id')
        ->join('users u', 'u.id = b.created_by')
        ->join('deduction_periods p', 'p.id = b.period_id')
        ->join('deduction_batch_items i', 'i.batch_id = b.id')
        ->where('b.id', $batchId)
        ->where('b.created_by', $actorId)
        ->where('i.id', $itemId)
        ->get()->getRowArray();
    if (!$fixture || $fixture['actor_email'] !== 'qa-race-accounting@ibems.local'
        || !in_array($fixture['period_code'], ['QA-RACE-202609-A', 'QA-RACE-202609-B', 'QA-RACE-202610-A'], true)
        || ($fixture['period_code'] === 'QA-RACE-202609-A' && $action === 'edit')
        || ($fixture['period_code'] !== 'QA-RACE-202609-A' && !in_array($action, ['edit', 'submit'], true))) {
        throw new RuntimeException('The requested action does not target the synthetic race fixture.');
    }
    while (microtime(true) * 1000 < $targetMs) {
        usleep(1000);
    }
    $service = new DeductionBatchService();
    $result = match ($action) {
        'edit' => $service->prepare((int) $fixture['period_id'], [
            ['user_id' => (int) $fixture['user_id'], 'requested_amount' => '20.00', 'deduction_choice' => 'partial'],
        ], $actorId),
        'confirm' => $service->confirmResult($itemId, [
            'confirmed_amount' => '60.00',
            'reason_code' => 'insufficient_salary',
            'result_reference' => 'QA-RACE-RESULT-20260925',
        ], $actorId),
        default => $service->{$action}($batchId, $actorId),
    };
    echo json_encode([
        'action' => $action,
        'status' => $result['status'] ?? null,
        'code' => $result['code'] ?? null,
        'idempotent' => $result['idempotent'] ?? false,
        'message' => $result['message'] ?? null,
    ]), PHP_EOL;
    exit;
}

throw new InvalidArgumentException('Use probe, seed, seed-edit, act, or verify.');
