<?php

declare(strict_types=1);

// Manual browser QA only: discard one successful repayment acknowledgement.
$qaRoot = dirname(__DIR__, 2);
$qaScenario = getenv('IBEMS_REPAYMENT_BROWSER_SCENARIO') ?: 'reload';
$qaFile = $qaScenario === 'tab-close' ? 'ibems-month-simulation-20261104.sqlite' : 'ibems-month-simulation-20261103.sqlite';
$qaDatabase = $qaRoot . '/writable/simulation/' . $qaFile;
if (PHP_SAPI !== 'cli-server' || getenv('IBEMS_REPAYMENT_BROWSER_QA') !== '1'
    || !in_array($qaScenario, ['reload', 'tab-close'], true)
    || getenv('CI_ENVIRONMENT') !== 'development' || getenv('IBEMS_DATABASE_DRIVER') !== 'SQLite3'
    || realpath((string) getenv('IBEMS_DATABASE_NAME')) !== realpath($qaDatabase)
    || getenv('IBEMS_SIMULATION_NOW') !== '2026-11-01 04:00:00'
    || !is_file($qaDatabase) || ($_SERVER['SERVER_PORT'] ?? '') !== '8082'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(503);
    exit('This router requires the explicitly isolated repayment browser QA server.');
}
$qaPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$_SERVER['SCRIPT_NAME'] = '/index.php';
if ($qaPath !== '/' && is_file($qaRoot . '/public' . $qaPath)) return false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($qaPath, ['/index.php/store/debt-repayments/create', '/store/debt-repayments/create'], true)) {
    require_once $qaRoot . '/vendor/codeigniter4/framework/system/Events/Events.php';
    // Register after the framework's development pre_system buffer reset.
    \CodeIgniter\Events\Events::on('pre_system', static function () use ($qaRoot, $qaScenario): void {
      ob_start(static function (string $body) use ($qaRoot, $qaScenario): string {
        $response = json_decode($body, true);
        if (($response['status'] ?? '') !== 'success') return $body;
        $marker = $qaRoot . '/writable/simulation/' . ($qaScenario === 'tab-close'
            ? 'repayment-browser-tabclose-discarded-ack.json' : 'repayment-browser-discarded-ack.json');
        $file = @fopen($marker, 'x');
        if ($file === false) return $body;
        fwrite($file, $body);
        fclose($file);
        http_response_code(502);
        return json_encode(['status' => 'error', 'message' => 'Synthetic QA acknowledgement failure. Reopen checkout and check the unfinished payment.'], JSON_THROW_ON_ERROR);
      });
    }, \CodeIgniter\Events\Events::PRIORITY_LOW);
}
require $qaRoot . '/public/index.php';
