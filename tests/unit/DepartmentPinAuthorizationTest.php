<?php

use App\Services\DepartmentAuthorizationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class DepartmentPinAuthorizationTest extends CIUnitTestCase
{
    private BaseConnection $pinDb;
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ibems-pin-qa-' . bin2hex(random_bytes(8));
        mkdir($this->fixtureDirectory, 0700);
        mkdir($this->fixtureDirectory . '/runtime', 0700);
        foreach (['logs', 'cache', 'session', 'debugbar'] as $directory) {
            mkdir($this->fixtureDirectory . '/runtime/' . $directory, 0700);
        }
        $this->pinDb = Database::connect($this->databaseConfig(), false);
        $this->pinDb->query('CREATE TABLE pin_users (id INTEGER PRIMARY KEY, is_active INTEGER)');
        $this->pinDb->query('CREATE TABLE pin_departments (id INTEGER PRIMARY KEY, head_user_id INTEGER, is_active INTEGER)');
        $this->pinDb->query('CREATE TABLE pin_department_authorization_pins (user_id INTEGER PRIMARY KEY, pin_hash TEXT, failed_attempts INTEGER NOT NULL DEFAULT 0, window_started_at TEXT, locked_until TEXT, last_failed_at TEXT, last_success_at TEXT, updated_at TEXT)');
        $this->pinDb->query('CREATE TABLE pin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, payload_json TEXT, created_at TEXT)');
        $this->pinDb->table('users')->insert(['id' => 600, 'is_active' => 1]);
        $this->pinDb->table('departments')->insert(['id' => 10, 'head_user_id' => 600, 'is_active' => 1]);
        $this->pinDb->table('department_authorization_pins')->insert(['user_id' => 600, 'pin_hash' => password_hash('2468', PASSWORD_DEFAULT), 'failed_attempts' => 0]);
    }

    protected function tearDown(): void
    {
        $this->pinDb->close();
        // Only remove the uniquely-created, verified disposable fixture directory.
        $root = realpath($this->fixtureDirectory);
        if ($root !== false && dirname($root) === realpath(sys_get_temp_dir()) && str_starts_with(basename($root), 'ibems-pin-qa-')) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
        parent::tearDown();
    }

    public function testFifthFailureLocksAndCorrectPinCannotBypassLockout(): void
    {
        $service = new DepartmentAuthorizationService($this->pinDb);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $service->authorize(10, 600, '0000', 700 + $attempt, 91, 50);
            $this->assertSame($attempt === 5 ? 423 : 401, $result['code']);
            $this->assertSame($attempt, (int) $this->state()['failed_attempts']);
        }
        $service->authorize(10, 600, '0000', 710, 91, 50);
        $this->assertSame(423, $service->authorize(10, 600, '2468', 711, 91, 50)['code']);
        $this->assertSame(5, (int) $this->state()['failed_attempts']);
        $this->assertNotEmpty($this->state()['locked_until']);
        $this->assertSame(7, $this->pinDb->table('audit_logs')->countAllResults());
    }

    public function testSuccessfulApprovalResetsFailuresAndCommitsAuditOnSameConnection(): void
    {
        $this->pinDb->table('department_authorization_pins')->where('user_id', 600)->update(['failed_attempts' => 4, 'window_started_at' => date('Y-m-d H:i:s')]);
        $result = (new DepartmentAuthorizationService($this->pinDb))->authorize(10, 600, '2468', 700, 91, 50);
        $this->assertSame('success', $result['status']);
        $this->assertSame(0, (int) $this->state()['failed_attempts']);
        $this->assertNull($this->state()['window_started_at']);
        $this->assertNull($this->state()['locked_until']);
        $this->assertSame('DEPARTMENT_PIN_AUTHORIZED', $this->pinDb->table('audit_logs')->get()->getRowArray()['action']);
    }

    public function testExpiredAttemptWindowStartsNewCounter(): void
    {
        $this->pinDb->table('department_authorization_pins')->where('user_id', 600)->update(['failed_attempts' => 4, 'window_started_at' => date('Y-m-d H:i:s', time() - 16 * 60)]);
        $result = (new DepartmentAuthorizationService($this->pinDb))->authorize(10, 600, '0000', 700, 91, 50);
        $this->assertSame(401, $result['code']);
        $this->assertSame(4, $result['attempts_remaining']);
        $this->assertSame(1, (int) $this->state()['failed_attempts']);
    }

    public function testMissingPinReturnsConflictWithoutAuthorization(): void
    {
        $this->pinDb->table('department_authorization_pins')->where('user_id', 600)->delete();
        $this->assertSame(409, (new DepartmentAuthorizationService($this->pinDb))->authorize(10, 600, '0000', 700, 91, 50)['code']);
    }

    public function testAuditFailureRollsBackCounterAndFailsClosed(): void
    {
        $this->pinDb->query('DROP TABLE pin_audit_logs');
        $result = (new DepartmentAuthorizationService($this->pinDb))->authorize(10, 600, '0000', 700, 91, 50);
        $this->assertSame(503, $result['code']);
        $this->assertSame(0, (int) $this->state()['failed_attempts']);
        $this->assertNull($this->state()['window_started_at']);
    }

    public function testWriterLockTimeoutFailsClosedWithoutCheckingPin(): void
    {
        $contender = Database::connect(array_merge($this->databaseConfig(), ['busyTimeout' => 1]), false);
        $this->pinDb->transBegin();
        $this->pinDb->table('department_authorization_pins')->where('user_id', 600)->update(['failed_attempts' => 2]);
        try {
            $result = (new DepartmentAuthorizationService($contender))->authorize(10, 600, '2468', 700, 91, 50);
            $this->assertSame(503, $result['code']);
        } finally {
            $contender->close();
            $this->pinDb->transRollback();
        }
        $this->assertSame(0, (int) $this->state()['failed_attempts']);
    }

    public function testEightConcurrentConnectionsPermitOnlyFiveFailedGuesses(): void
    {
        $this->assertTrue(function_exists('proc_open'), 'Independent-process concurrency verification requires proc_open.');
        $workers = [];
        $deadline = microtime(true) + 20;
        try {
            for ($id = 1; $id <= 8; $id++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, ROOTPATH . 'tests/qa/department_pin_worker.php', $this->fixtureDirectory, (string) $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH,
                    array_merge(getenv(), ['IBEMS_DEPARTMENT_PIN_QA' => '1']));
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            while (count(glob($this->fixtureDirectory . '/ready-*')) < 8 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(8, glob($this->fixtureDirectory . '/ready-*'), 'All independent workers must reach the start barrier.');
            touch($this->fixtureDirectory . '/start');
            $codes = [];
            foreach ($workers as [$process, $pipes]) {
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) break;
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertFalse($status['running'], 'PIN worker exceeded concurrency-test deadline.');
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                $this->assertSame('', $error);
                $result = json_decode($output, true);
                $this->assertIsArray($result, 'PIN worker must return JSON.');
                $codes[] = $result['code'];
            }
            $this->assertSame(4, count(array_filter($codes, static fn ($code) => $code === 401)), 'Only the first four guesses may fail without lockout.');
            $this->assertSame(4, count(array_filter($codes, static fn ($code) => $code === 423)), 'The fifth guess and all later guesses must be locked.');
            $this->assertSame(5, (int) $this->state()['failed_attempts']);
            $this->assertNotEmpty($this->state()['locked_until']);
            $this->assertSame(8, $this->pinDb->table('audit_logs')->countAllResults());
            $this->assertSame(1, $this->pinDb->table('audit_logs')->where('action', 'DEPARTMENT_PIN_LOCKED')->countAllResults());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                proc_close($process);
            }
        }
    }

    private function state(): array
    {
        return $this->pinDb->table('department_authorization_pins')->where('user_id', 600)->get()->getRowArray();
    }

    private function databaseConfig(): array
    {
        return ['DBDriver' => 'SQLite3', 'database' => $this->fixtureDirectory . '/pin.sqlite', 'DBPrefix' => 'pin_', 'DBDebug' => true, 'busyTimeout' => 10000];
    }
}
