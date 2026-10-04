<?php

namespace App\Services;

use RuntimeException;

final class MonthSimulationEvidence
{
    public static function path(string $database): string
    {
        $source = realpath($database);
        $directory = realpath(WRITEPATH . 'simulation');
        if ($source === false || $directory === false || dirname($source) !== $directory
            || !preg_match('/^ibems-month-simulation-\d{8}\.sqlite$/D', basename($source))) {
            throw new RuntimeException('Month evidence requires an isolated simulation database.');
        }
        return $source . '.result.json';
    }

    public static function save(string $database, array $summary): string
    {
        $path = self::path($database);
        $summary['database'] = basename($database);
        $summary['saved_at_utc'] = gmdate('c');
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create month evidence; preserve any existing result: ' . basename($path));
        }
        try {
            if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)) {
                throw new RuntimeException('Month evidence write failed; retain the result file for diagnosis.');
            }
        } finally {
            fclose($stream);
        }
        return $path;
    }
}
