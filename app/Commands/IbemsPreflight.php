<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class IbemsPreflight extends BaseCommand
{
    protected $group       = 'IBEMS';
    protected $name        = 'ibems:preflight';
    protected $description = 'Run all IBEMS release preflight checks (smoke, auth-audit, data-audit, route-audit).';
    protected $usage       = 'ibems:preflight';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params): void
    {
        $checks = [
            'ibems:smoke',
            'ibems:auth-audit',
            'ibems:data-audit',
            'ibems:route-audit',
        ];

        $failures = 0;

        CLI::write('IBEMS Preflight', 'yellow');
        CLI::newLine();

        foreach ($checks as $check) {
            CLI::write("Running {$check}...", 'light_gray');
            $result = $this->runSparkCommand($check);

            if ($result['output'] !== '') {
                CLI::write($result['output']);
            }

            if ($result['exit_code'] !== 0) {
                CLI::write("[FAIL] {$check} failed with exit code {$result['exit_code']}", 'red');
                $failures++;
            } else {
                CLI::write("[OK] {$check} passed", 'green');
            }

            CLI::newLine();
        }

        if ($failures > 0) {
            CLI::write("Preflight failed: {$failures} check(s) failed.", 'red');
            exit(1);
        }

        CLI::write('Preflight passed: all checks are green.', 'green');
    }

    /**
     * @return array{output:string, exit_code:int}
     */
    private function runSparkCommand(string $command): array
    {
        $php = escapeshellarg(PHP_BINARY);
        $spark = escapeshellarg(ROOTPATH . 'spark');
        $full = "{$php} {$spark} {$command}";

        $output = [];
        $exitCode = 0;
        exec($full, $output, $exitCode);

        return [
            'output' => trim(implode(PHP_EOL, $output)),
            'exit_code' => (int) $exitCode,
        ];
    }
}

