<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** A virtual clock restricted to explicitly selected synthetic simulation/demo databases. */
final class SimulationClock
{
    public static function portalLabel(): ?string
    {
        if (trim((string) getenv('IBEMS_SIMULATION_NOW')) === '' && getenv('IBEMS_INSTRUCTOR_DEMO') !== '1') {
            return null;
        }

        // Validate all isolation guards before advertising a simulated operating date.
        return self::now()->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y g:i A');
    }

    public static function now(): DateTimeImmutable
    {
        $override = trim((string) getenv('IBEMS_SIMULATION_NOW'));
        if ($override === '') {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $database = realpath((string) getenv('IBEMS_DATABASE_NAME'));
        $directory = realpath(WRITEPATH . 'simulation');
        $localSimulation = ENVIRONMENT === 'development' && getenv('IBEMS_SIMULATION') === '1'
            && $database !== false && $directory !== false && dirname($database) === $directory
            && preg_match('/^ibems-month-simulation-\d{8}\.sqlite$/', basename($database));
        $demoDirectory = realpath(WRITEPATH . 'instructor-demo');
        $instructorDemo = ENVIRONMENT === 'production' && getenv('IBEMS_INSTRUCTOR_DEMO') === '1'
            && getenv('IBEMS_DATABASE_DRIVER') === 'SQLite3'
            && $database !== false && $demoDirectory !== false && dirname($database) === $demoDirectory
            && basename($database) === 'ibems-instructor-demo.sqlite';
        if ($instructorDemo) {
            $connection = new \SQLite3($database, SQLITE3_OPEN_READONLY);
            try {
                $hasMarker = $connection->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='instructor_demo_metadata'") === 1;
                $instructorDemo = $hasMarker && $connection->querySingle("SELECT marker FROM instructor_demo_metadata LIMIT 1") === 'synthetic-instructor-demo-v1';
            } finally {
                $connection->close();
            }
        }
        if ((!$localSimulation && !$instructorDemo)
            || getenv('IBEMS_DATABASE_DRIVER') !== 'SQLite3'
        ) {
            throw new RuntimeException('Simulation time requires an isolated local simulation or a marked instructor demo SQLite database.');
        }

        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $override, new DateTimeZone('UTC'));
        if (!$instant || $instant->format('Y-m-d H:i:s') !== $override) {
            throw new RuntimeException('IBEMS_SIMULATION_NOW must be an exact UTC YYYY-MM-DD HH:MM:SS timestamp.');
        }

        return $instant;
    }
}
