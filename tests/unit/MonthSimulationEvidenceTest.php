<?php

use App\Services\MonthSimulationEvidence;
use CodeIgniter\Test\CIUnitTestCase;

final class MonthSimulationEvidenceTest extends CIUnitTestCase
{
    public function testResultsAreDatabaseScopedAndCannotOverwriteAnEarlierRun(): void
    {
        $directory = WRITEPATH . 'simulation';
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        $paths = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                do {
                    $path = $directory . '/ibems-month-simulation-' . random_int(10000000, 99999999) . '.sqlite';
                } while (file_exists($path) || file_exists($path . '.result.json'));
                $stream = fopen($path, 'xb');
                fclose($stream);
                $paths[] = $path;
                $result = MonthSimulationEvidence::save($path, ['sales_cents' => 100 + $index]);
                $this->assertSame(realpath($path) . '.result.json', $result);
                $row = json_decode(file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(basename($path), $row['database']);
                $this->assertSame(100 + $index, $row['sales_cents']);
            }
            $before = file_get_contents($paths[0] . '.result.json');
            try {
                MonthSimulationEvidence::save($paths[0], ['sales_cents' => 999]);
                $this->fail('An existing result must never be replaced.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserve any existing result', $error->getMessage());
            }
            $this->assertSame($before, file_get_contents($paths[0] . '.result.json'));
        } finally {
            foreach ($paths as $path) {
                if (file_exists($path . '.result.json')) unlink($path . '.result.json');
                unlink($path);
            }
        }
    }

    public function testUnrelatedDatabaseCannotReceiveMonthEvidence(): void
    {
        $this->expectException(RuntimeException::class);
        MonthSimulationEvidence::save(WRITEPATH . 'ifr-browser-qa-20260924.sqlite', ['sales_cents' => 999]);
    }
}
