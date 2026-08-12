<?php

namespace App\Commands;

use App\Libraries\ConventionScanner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class IbemsConventions extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:conventions';
    protected $description = 'Report repository convention drift without changing files or failing the build.';
    protected $usage = 'ibems:conventions';
    protected $arguments = [];
    protected $options = [];

    public function run(array $params): void
    {
        $findings = (new ConventionScanner(ROOTPATH))->scan();
        $warningCount = count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning'));
        $infoCount = count($findings) - $warningCount;

        CLI::write('IBEMS Convention Report', 'yellow');
        CLI::write('Report-only: findings do not fail the command or modify the repository.', 'light_gray');
        CLI::newLine();

        foreach ($findings as $finding) {
            $label = strtoupper($finding['severity']);
            $color = $finding['severity'] === 'warning' ? 'light_yellow' : 'light_gray';
            CLI::write("[{$label}] {$finding['path']}:{$finding['line']} {$finding['rule']} - {$finding['message']}", $color);
        }

        if ($findings === []) {
            CLI::write('[OK] No convention drift detected.', 'green');
        }

        CLI::newLine();
        CLI::write("Warnings: {$warningCount}", $warningCount > 0 ? 'light_yellow' : 'green');
        CLI::write("Information: {$infoCount}", $infoCount > 0 ? 'light_gray' : 'green');
        CLI::write('Result: report generated successfully (non-blocking).', 'green');
    }
}
