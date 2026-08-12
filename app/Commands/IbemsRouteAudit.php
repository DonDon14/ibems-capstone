<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Authorization;

class IbemsRouteAudit extends BaseCommand
{
    protected $group       = 'IBEMS';
    protected $name        = 'ibems:route-audit';
    protected $description = 'Audit route protection on write endpoints (POST/PUT/PATCH/DELETE) and portal boundary hygiene.';
    protected $usage       = 'ibems:route-audit';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params): void
    {
        $routesPath = APPPATH . 'Config/Routes.php';
        if (!is_file($routesPath)) {
            CLI::write('[FAIL] Routes file not found: ' . $routesPath, 'red');
            exit(1);
        }

        $source = (string) file_get_contents($routesPath);
        $lines = preg_split('/\R/', $source) ?: [];

        $errors = 0;
        $warnings = 0;
        $writeCount = 0;
        $authorization = config(Authorization::class);

        CLI::write('IBEMS Route Security Audit', 'yellow');
        CLI::newLine();

        $routeRegex = '/\$routes->(post|put|patch|delete)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[^\)]*\]))?\s*\)\s*;/i';
        $publicWriteWhitelist = [
            'auth/login',
            'auth/select-role',
            'auth/logout',
        ];

        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            if (!preg_match($routeRegex, $line, $m)) {
                continue;
            }

            $verb = strtoupper((string) $m[1]);
            $path = (string) $m[2];
            $target = (string) $m[3];
            $options = isset($m[4]) ? (string) $m[4] : '';
            $writeCount++;

            $isWhitelisted = in_array($path, $publicWriteWhitelist, true);
            $protection = self::inspectAuthorization($options, $authorization);

            if (!$protection['protected'] && !$isWhitelisted) {
                CLI::write("[FAIL] Line {$lineNo}: {$verb} {$path} -> {$target} {$protection['message']}", 'red');
                $errors++;
            } elseif ($isWhitelisted) {
                CLI::write("[OK]   Line {$lineNo}: {$verb} {$path} allowed public auth endpoint", 'green');
            } else {
                CLI::write("[OK]   Line {$lineNo}: {$verb} {$path} protected by {$protection['label']}", 'green');
            }

            // Portal boundary hygiene warning (non-blocking).
            $isStoreRoute = strpos($path, 'store/') === 0;
            $isAccountingTarget = stripos($target, 'AccountingController::') !== false;
            if ($isStoreRoute && $isAccountingTarget) {
                CLI::write("[WARN] Line {$lineNo}: store route mapped to AccountingController ({$path})", 'light_yellow');
                $warnings++;
            }

            $isAccountingRoute = strpos($path, 'accounting/') === 0;
            $isStoreTarget = stripos($target, 'StoreController::') !== false;
            if ($isAccountingRoute && $isStoreTarget) {
                CLI::write("[WARN] Line {$lineNo}: accounting route mapped to StoreController ({$path})", 'light_yellow');
                $warnings++;
            }
        }

        if ($writeCount === 0) {
            CLI::write('[FAIL] No write endpoints were detected. Route parser may be out of sync.', 'red');
            exit(1);
        }

        CLI::newLine();
        CLI::write("Write endpoints checked: {$writeCount}", 'green');
        CLI::write("Warnings: {$warnings}", $warnings > 0 ? 'light_yellow' : 'green');
        CLI::write("Errors: {$errors}", $errors > 0 ? 'red' : 'green');
        CLI::newLine();

        if ($errors > 0) {
            CLI::write('Route audit failed. Protect missing write endpoints before deployment.', 'red');
            exit(1);
        }

        CLI::write('Route audit passed.', 'green');
    }

    /** @return array{protected: bool, label: string, message: string} */
    public static function inspectAuthorization(string $options, Authorization $authorization): array
    {
        if (stripos($options, "'filter'") === false && stripos($options, '"filter"') === false) {
            return ['protected' => false, 'label' => '', 'message' => 'is missing an authorization filter'];
        }

        if (preg_match('/access:([a-z0-9._-]+)/i', $options, $match)) {
            $policy = strtolower((string) $match[1]);
            if ($authorization->rolesFor($policy) === null) {
                return ['protected' => false, 'label' => '', 'message' => "uses unknown access policy {$policy}"];
            }

            return ['protected' => true, 'label' => "access policy {$policy}", 'message' => ''];
        }

        if (preg_match('/role:([A-Z0-9_|,-]+)/i', $options, $match) && trim((string) $match[1]) !== '') {
            return ['protected' => true, 'label' => 'legacy role filter', 'message' => ''];
        }

        return ['protected' => false, 'label' => '', 'message' => 'has a filter but no recognized authorization policy'];
    }
}

