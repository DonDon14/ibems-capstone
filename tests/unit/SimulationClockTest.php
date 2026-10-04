<?php

use CodeIgniter\Test\CIUnitTestCase;

final class SimulationClockTest extends CIUnitTestCase
{
    public function testInstructorSiteHidesNoticeWithoutChangingRealOperatingTime(): void
    {
        $previousFlag=getenv('IBEMS_INSTRUCTOR_DEMO'); $previousTime=getenv('IBEMS_SIMULATION_NOW');
        putenv('IBEMS_INSTRUCTOR_DEMO=1'); putenv('IBEMS_SIMULATION_NOW');
        try {
            $this->assertNotNull(\App\Services\SimulationClock::portalLabel());
            $this->assertStringNotContainsString('Synthetic accounts and records',view('components/simulation_notice'));
        } finally {
            putenv($previousFlag===false?'IBEMS_INSTRUCTOR_DEMO':'IBEMS_INSTRUCTOR_DEMO='.$previousFlag);
            putenv($previousTime===false?'IBEMS_SIMULATION_NOW':'IBEMS_SIMULATION_NOW='.$previousTime);
        }
    }

    public function testNormalPortalDoesNotRenderSimulationNotice(): void
    {
        $previous = getenv('IBEMS_SIMULATION_NOW');
        putenv('IBEMS_SIMULATION_NOW');
        try {
            $this->assertStringNotContainsString('aria-label="Simulation environment"', view('components/simulation_notice'));
        } finally {
            putenv($previous === false ? 'IBEMS_SIMULATION_NOW' : 'IBEMS_SIMULATION_NOW=' . $previous);
        }
    }

    public function testVirtualClockUsesUtcAndRejectsInvalidDatesAndUnsafeTargets(): void
    {
        $root = sys_get_temp_dir() . '/ibems-clock-' . bin2hex(random_bytes(6));
        mkdir($root . '/simulation', 0777, true);
        $path = $root . '/simulation/ibems-month-simulation-20261001.sqlite';
        $db = new SQLite3($path);
        $db->exec('CREATE TABLE simulation_fixture (id INTEGER)');
        $db->close();
        mkdir($root . '/instructor-demo', 0777, true);
        $demoPath = $root . '/instructor-demo/ibems-instructor-demo.sqlite';
        $demo = new SQLite3($demoPath);
        $demo->exec("CREATE TABLE instructor_demo_metadata (marker TEXT); INSERT INTO instructor_demo_metadata VALUES ('synthetic-instructor-demo-v1')");
        $demo->close();
        try {
            foreach ([
                ['development', $path, '2026-10-15 04:00:00', 'October 15, 2026 12:00 PM'],
                ['development', $path, '2026-10-15 16:00:00', 'October 16, 2026 12:00 AM'],
                ['development', $path, '', 'OFF'],
                ['development', $path, '2026-02-30 04:00:00', 'REJECTED'],
                ['production', $path, '2026-10-15 04:00:00', 'REJECTED'],
                ['development', $root . '/production.sqlite', '2026-10-15 04:00:00', 'REJECTED'],
                ['production', $demoPath, '2026-11-01 04:00:00', 'November 1, 2026 12:00 PM'],
                ['production', $path, '2026-11-01 04:00:00', 'REJECTED'],
            ] as [$environment, $database, $instant, $expected]) {
                $code = 'define("ENVIRONMENT", ' . var_export($environment, true) . ');'
                    . 'define("WRITEPATH", ' . var_export($root . '/', true) . ');'
                    . 'putenv("IBEMS_SIMULATION=1"); putenv("IBEMS_DATABASE_DRIVER=SQLite3");'
                    . 'putenv("IBEMS_INSTRUCTOR_DEMO=' . ($database === $demoPath ? '1' : '0') . '");'
                    . 'putenv(' . var_export('IBEMS_DATABASE_NAME=' . $database, true) . ');'
                    . 'putenv(' . var_export('IBEMS_SIMULATION_NOW=' . $instant, true) . ');'
                    . 'require ' . var_export(APPPATH . 'Services/SimulationClock.php', true) . ';'
                    . 'try { echo App\\Services\\SimulationClock::portalLabel() ?? "OFF"; } catch (RuntimeException $e) { echo "REJECTED"; }';
                $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $this->assertSame($expected, $output);
            }
        } finally {
            unlink($path);
            unlink($demoPath);
            rmdir($root . '/instructor-demo');
            rmdir($root . '/simulation');
            rmdir($root);
        }
    }
}
