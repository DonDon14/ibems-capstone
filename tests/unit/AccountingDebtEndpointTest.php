<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use App\Services\SalaryImportIdentityGuard;
use App\Services\SalaryCreditPolicy;
use App\Services\AdminUserService;
use App\Services\HrStaffService;
use App\Controllers\AccountingController;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * @internal
 */
final class AccountingDebtEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testSharedDeductionWorkspaceProvidesScalableEmployeeFilters(): void
    {
        $view = file_get_contents(APPPATH . 'Views/office/deduction_workspace.php') . file_get_contents(APPPATH . 'Views/components/deduction_batch_request_form.php');
        $script = file_get_contents(FCPATH . 'assets/js/deduction-workspace.js');
        $styles = file_get_contents(FCPATH . 'assets/css/deduction-workspace.css');

        foreach (['deduction-register-search', 'deduction-register-type', 'deduction-register-salary', 'deduction-select-matching', 'deduction-clear-selection', 'deduction-match-count', 'data-deduction-candidate'] as $control) {
            $this->assertStringContainsString($control, $view);
        }
        $this->assertStringContainsString('data-search=', $view);
        $this->assertStringContainsString('data-employee-type=', $view);
        $this->assertStringContainsString('data-salary-state=', $view);
        $this->assertStringContainsString('function filterRows()', $script);
        $this->assertStringContainsString('if (!row.hidden)', $script);
        $this->assertStringContainsString('.deduction-row[hidden]', $styles);
    }

    public function testSalaryCsvIdentityGuardRejectsCrossAccountAndRepeatedRows(): void
    {
        $this->seedBalance(501, 100, 0);
        $this->seedBalance(502, 100, 0);
        $db = Database::connect();
        $db->table('users')->where('id', 502)->update(['email' => 'Debt502@Example.Test']);
        $guard = new SalaryImportIdentityGuard();
        $seenIds = [];
        $seenEmails = [];

        $crossAccount = $guard->inspect($db, 'EMP-501', 'debt502@example.test', $seenIds, $seenEmails);
        $this->assertContains('employee_id and email belong to different existing users', $crossAccount['errors']);

        $wrongEmployeeId = $guard->inspect($db, 'EMP-999', 'debt501@example.test', $seenIds, $seenEmails);
        $this->assertContains('email belongs to a different employee_id', $wrongEmployeeId['errors']);

        $valid = $guard->inspect($db, 'EMP-501', 'debt501@example.test', $seenIds, $seenEmails);
        $this->assertSame([], $valid['errors']);
        $this->assertSame(501, (int) $valid['existing']['id']);
        $guard->claim('EMP-501', 'debt501@example.test', $seenIds, $seenEmails);

        $repeatedId = $guard->inspect($db, 'EMP-501', 'new@example.test', $seenIds, $seenEmails);
        $this->assertContains('employee_id is repeated in this CSV', $repeatedId['errors']);
        $repeatedEmail = $guard->inspect($db, 'EMP-999', 'DEBT501@example.test', $seenIds, $seenEmails);
        $this->assertContains('email is repeated in this CSV', $repeatedEmail['errors']);

        $controller = file_get_contents(APPPATH . 'Controllers/AccountingController.php');
        $this->assertSame(2, substr_count($controller, '$identityGuard->inspect('));
        $this->assertSame(2, substr_count($controller, '$identityGuard->claim('));
    }

    public function testSalaryCsvPreviewAndApplyRejectUnsupportedAndRepeatedHeadersBeforeWriting(): void
    {
        $this->seedBalance(501, 100, 10);
        $db = Database::connect();
        $userBefore = $db->table('users')->where('id', 501)->get()->getRowArray();
        $balanceBefore = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $header = 'employee_id,name,email,user_type,employment_type,salary_grade,salary_step,salary_effective_date';
        $row = 'EMP-501,Changed Staff,debt501@example.test,staff,plantilla,SG-1,1,2026-08-21';
        foreach (['current_debt' => 'Unsupported CSV columns', 'monthly_salary' => 'Unsupported CSV columns', 'salary_grade' => 'CSV column names must be unique'] as $extra => $message) {
            $csv = "{$header},{$extra}\n{$row},99999\n";
            $path = tempnam(sys_get_temp_dir(), 'ibems-salary-schema-');
            file_put_contents($path, $csv);
            try {
                foreach (['previewImportCsv', 'importCsv'] as $method) {
                    $response = $this->salaryCsvController($path, $csv)->{$method}();
                    $body = json_decode((string) $response->getJSON(), true);
                    $this->assertSame(400, $response->getStatusCode());
                    $this->assertStringContainsString($message, $body['message']);
                    $this->assertSame(0, $db->table('salary_import_batches')->countAllResults());
                    $this->assertSame(0, $db->table('audit_logs')->countAllResults());
                    $this->assertSame($userBefore, $db->table('users')->where('id', 501)->get()->getRowArray());
                    $this->assertSame($balanceBefore, $db->table('balances')->where('user_id', 501)->get()->getRowArray());
                }
            } finally {
                unlink($path);
            }
        }
    }

    public function testSalaryCsvMalformedRowsAreInvalidInPreviewAndApplyWithoutProfileWrites(): void
    {
        $this->seedBalance(501, 100, 10);
        $db = Database::connect();
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'salary_import_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER, employee_id TEXT,
            name TEXT, email TEXT, monthly_salary REAL, status TEXT, error_msg TEXT
        )');
        $userBefore = $db->table('users')->where('id', 501)->get()->getRowArray();
        $balanceBefore = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $csv = "employee_id,name,email,user_type,employment_type,salary_grade,salary_step,salary_effective_date,credit_percentage\n"
            . "EMP-501,Changed Staff,debt501@example.test,staff,plantilla,SG-1,1,2026-08-21,25,99999\n"
            . "EMP-501,Changed Staff,debt501@example.test,staff,plantilla,SG-1,1,2026-08-21\n";
        $path = tempnam(sys_get_temp_dir(), 'ibems-salary-shape-');
        file_put_contents($path, $csv);
        try {
            foreach (['previewImportCsv', 'importCsv'] as $method) {
                $response = $this->salaryCsvController($path, $csv)->{$method}();
                $body = json_decode((string) $response->getJSON(), true);
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame(0, $body['valid_rows']);
                $this->assertSame(2, $body['invalid_rows']);
                foreach ($body['invalid_preview'] as $invalid) {
                    $this->assertStringContainsString('exactly one value for each CSV column', $invalid['error']);
                }
                $this->assertSame($userBefore, $db->table('users')->where('id', 501)->get()->getRowArray());
                $this->assertSame($balanceBefore, $db->table('balances')->where('user_id', 501)->get()->getRowArray());
                $this->assertSame(1, $db->table('users')->countAllResults());
            }
            $this->assertSame(2, $db->table('salary_import_rows')->where('status', 'invalid')->countAllResults());
            $this->assertSame(0, $db->table('salary_import_rows')->where('status', 'valid')->countAllResults());
        } finally {
            unlink($path);
        }
    }

    private function salaryCsvController(string $path, string $csv): AccountingController
    {
        $file = new class($path, 'salary.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool { return true; }
        };
        $request = $this->getMockBuilder(\CodeIgniter\HTTP\IncomingRequest::class)
            ->disableOriginalConstructor()->onlyMethods(['getFile'])->getMock();
        $request->method('getFile')->with('csv_file')->willReturn($file);
        $controller = new AccountingController();
        $controller->initController($request, \Config\Services::response(null, false), \Config\Services::logger());

        return $controller;
    }

    public function testSalaryCsvPreviewAndApplyRejectConflictingAccountsWithoutChangingTheirBalances(): void
    {
        $this->seedBalance(501, 100, 10);
        $this->seedBalance(502, 200, 20);
        $db = Database::connect();
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'salary_import_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER, employee_id TEXT,
            name TEXT, email TEXT, monthly_salary REAL, status TEXT, error_msg TEXT
        )');

        $futureDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $csv = "employee_id,name,email,user_type,employment_type,salary_grade,salary_step,salary_effective_date,credit_percentage\n"
            . "EMP-501,First Staff,debt501@example.test,staff,plantilla,SG-1,1,2026-08-21,25\n"
            . "EMP-501,Repeated ID,repeated@example.test,staff,plantilla,SG-1,1,2026-08-21,25\n"
            . "EMP-502,Cross Account,debt501@example.test,staff,plantilla,SG-1,1,2026-08-21,25\n"
            . "EMP-503,Bad Step,badstep@example.test,staff,plantilla,SG-1,1.9,2026-08-21,25\n"
            . "EMP-504,Bad Percentage,badcredit@example.test,staff,plantilla,SG-1,1,2026-08-21,25x\n"
            . "EMP-505,Future Salary,future.salary@example.test,staff,plantilla,SG-1,1,{$futureDate},25\n";
        $path = tempnam(sys_get_temp_dir(), 'ibems-salary-');
        $this->assertNotFalse($path);
        file_put_contents($path, $csv);

        try {
            $file = new class($path, 'salary.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK) extends UploadedFile {
                public function isValid(): bool { return true; }
            };
            $request = $this->getMockBuilder(\CodeIgniter\HTTP\IncomingRequest::class)
                ->disableOriginalConstructor()->onlyMethods(['getFile'])->getMock();
            $request->method('getFile')->with('csv_file')->willReturn($file);
            $controller = new AccountingController();
            $controller->initController($request, \Config\Services::response(null, false), \Config\Services::logger());
            $preview = json_decode((string) $controller->previewImportCsv()->getJSON(), true);
            $this->assertSame(1, $preview['valid_rows']);
            $this->assertSame(5, $preview['invalid_rows']);
            $this->assertSame('update', $preview['valid_preview'][0]['action']);
            $this->assertStringContainsString('salary_step must be an integer', $preview['invalid_preview'][2]['error']);
            $this->assertStringContainsString('credit_percentage must be a number', $preview['invalid_preview'][3]['error']);
            $this->assertStringContainsString('future-effective salary profiles cannot be imported', $preview['invalid_preview'][4]['error']);

            session()->set('user_id', 900);
            $controller = new AccountingController();
            $controller->initController($request, \Config\Services::response(null, false), \Config\Services::logger());
            $applied = json_decode((string) $controller->importCsv()->getJSON(), true);
            $this->assertSame(1, $applied['valid_rows']);
            $this->assertSame(5, $applied['invalid_rows']);
            $this->assertSame(2, $db->table('users')->countAllResults());
            $this->assertSame(20.0, (float) $db->table('balances')->where('user_id', 502)->get()->getRow('current_debt'));
            $this->assertSame(200.0, (float) $db->table('balances')->where('user_id', 502)->get()->getRow('credit_limit'));
            $history = (new \App\Services\AccountingDebtQueryService())->history($db, 501, 20);
            $this->assertCount(1, $history);
            $this->assertSame('ACCOUNTING_UPDATE_FINANCIAL_PROFILE', $history[0]['action']);
            $payload = $history[0]['payload'];
            $this->assertSame('salary_csv_import', $payload['source']);
            $this->assertSame('update', $payload['operation']);
            $this->assertSame($applied['batch_id'], $payload['import_batch_id']);
            $this->assertSame(hash('sha256', $csv), $payload['source_sha256']);
            $this->assertSame(2, $payload['source_line']);
            $this->assertSame(0, $payload['previous_base_salary']);
            $this->assertSame(100, $payload['previous_credit_limit']);
            $this->assertSame('SG-TEST', $payload['previous_salary_profile']['salary_grade']);
            $this->assertSame('SG-1', $payload['new_salary_profile']['salary_grade']);
            $this->assertSame(10, $payload['current_debt']);
            $this->assertSame((float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'), (float) $payload['new_base_salary']);
            $this->assertSame((float) $db->table('balances')->where('user_id', 501)->get()->getRow('credit_limit'), (float) $payload['new_credit_limit']);
            $this->assertSame([], (new \App\Services\AccountingDebtQueryService())->history($db, 502, 20));
            $batchAudit = json_decode($db->table('audit_logs')->where('action', 'ACCOUNTING_IMPORT_HR_CSV')->get()->getRow('payload_json'), true);
            $this->assertSame($payload['source_sha256'], $batchAudit['source_sha256']);
        } finally {
            session()->remove('user_id');
            unlink($path);
        }
    }

    public function testSalaryCsvNewEmployeeAuditRecordsInitialProfileWithoutInventingPreviousAmounts(): void
    {
        $db = Database::connect();
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'salary_import_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER, employee_id TEXT,
            name TEXT, email TEXT, monthly_salary REAL, status TEXT, error_msg TEXT
        )');
        $csv = "employee_id,name,email,user_type,employment_type,salary_grade,salary_step,salary_effective_date\n"
            . "EMP-NEW,New Faculty,new.faculty@example.test,faculty,cos,SG-1,1,2026-08-21\n";
        $path = tempnam(sys_get_temp_dir(), 'ibems-salary-create-');
        file_put_contents($path, $csv);
        session()->set('user_id', 900);
        try {
            $response = $this->salaryCsvController($path, $csv)->importCsv();
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(1, json_decode((string) $response->getJSON(), true)['valid_rows']);
            $user = $db->table('users')->where('employee_id', 'EMP-NEW')->get()->getRowArray();
            $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();
            $this->assertSame(900, (int) $audit['actor_id']);
            $this->assertSame((int) $user['id'], (int) $audit['entity_id']);
            $payload = json_decode($audit['payload_json'], true);
            $this->assertSame('create', $payload['operation']);
            $this->assertNull($payload['previous_base_salary']);
            $this->assertNull($payload['previous_credit_limit']);
            $this->assertNull($payload['previous_salary_profile']);
            $this->assertSame('faculty', $payload['new_salary_profile']['user_type']);
            $this->assertSame('cos', $payload['new_salary_profile']['employment_type']);
            $this->assertSame(hash('sha256', $csv), $payload['source_sha256']);
            $this->assertSame(0, $payload['current_debt']);
            $this->assertArrayNotHasKey('password_hash', $payload['new_salary_profile']);
            $this->assertArrayNotHasKey('qr_token', $payload['new_salary_profile']);
            $this->assertCount(1, (new \App\Services\AccountingDebtQueryService())->history($db, (int) $user['id'], 20));
        } finally {
            session()->remove('user_id');
            unlink($path);
        }
    }

    public function testSalaryCsvImportRollsBackWhenProfileAuditCannotBeSaved(): void
    {
        $this->seedBalance(501, 100, 10, 400);
        $db = Database::connect();
        $db->query('CREATE TABLE ' . $db->getPrefix() . 'salary_import_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER, employee_id TEXT,
            name TEXT, email TEXT, monthly_salary REAL, status TEXT, error_msg TEXT
        )');
        $db->query("CREATE TRIGGER reject_import_audit BEFORE INSERT ON " . $db->getPrefix() . "audit_logs
            WHEN NEW.action = 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE'
            BEGIN SELECT RAISE(ABORT, 'Synthetic QA audit storage failure'); END");
        $userBefore = $db->table('users')->where('id', 501)->get()->getRowArray();
        $balanceBefore = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $csv = "employee_id,name,email,user_type,employment_type,salary_grade,salary_step,salary_effective_date\n"
            . "EMP-501,Changed Staff,debt501@example.test,staff,plantilla,SG-1,1,2026-08-21\n";
        $path = tempnam(sys_get_temp_dir(), 'ibems-salary-audit-');
        file_put_contents($path, $csv);
        try {
            $response = $this->salaryCsvController($path, $csv)->importCsv();
            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringContainsString('No changes were applied', json_decode((string) $response->getJSON(), true)['message']);
            $this->assertSame($userBefore, $db->table('users')->where('id', 501)->get()->getRowArray());
            $this->assertSame($balanceBefore, $db->table('balances')->where('user_id', 501)->get()->getRowArray());
            $this->assertSame(0, $db->table('salary_import_batches')->countAllResults());
            $this->assertSame(0, $db->table('salary_import_rows')->countAllResults());
            $this->assertSame(0, $db->table('audit_logs')->countAllResults());
        } finally {
            $db->query('DROP TRIGGER IF EXISTS reject_import_audit');
            $db->resetTransStatus();
            unlink($path);
        }
    }

    public function testAdminAndHrImportsRejectEmployeeIdEmailConflicts(): void
    {
        $this->seedBalance(501, 100, 10);
        $this->seedBalance(502, 200, 20);
        $csv = "name,email,employee_id,user_type\nCross Account,debt502@example.test,EMP-501,staff\n";
        $hrCsv = "name,email,employee_id\nCross Account,debt502@example.test,EMP-501\n";
        $path = tempnam(sys_get_temp_dir(), 'ibems-users-');
        $hrPath = tempnam(sys_get_temp_dir(), 'ibems-hr-users-');
        $this->assertNotFalse($path);
        $this->assertNotFalse($hrPath);
        file_put_contents($path, $csv);
        file_put_contents($hrPath, $hrCsv);

        try {
            $file = new class($path, 'users.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK) extends UploadedFile {
                public function isValid(): bool { return true; }
            };
            $hrFile = new class($hrPath, 'hr-users.csv', 'text/csv', strlen($hrCsv), UPLOAD_ERR_OK) extends UploadedFile {
                public function isValid(): bool { return true; }
            };
            foreach ([[new AdminUserService(), $file], [new HrStaffService(), $hrFile]] as [$service, $source]) {
                $result = $service->importCsv($source, 900);
                $this->assertSame(200, $result['code']);
                $this->assertSame(0, $result['payload']['updated']);
                $this->assertSame(0, $result['payload']['created']);
                $this->assertSame(1, $result['payload']['invalid']);
            }
            $db = Database::connect();
            $this->assertSame('debt501@example.test', $db->table('users')->where('id', 501)->get()->getRow('email'));
            $this->assertSame('EMP-502', $db->table('users')->where('id', 502)->get()->getRow('employee_id'));
            $this->assertSame(100.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('credit_limit'));
        } finally {
            unlink($path);
            unlink($hrPath);
        }
    }

    public function testHrStaffImportRejectsUnmappedClassificationAndSalaryColumns(): void
    {
        $db = Database::connect();
        foreach ([
            "name,email,employee_id,user_type\nWrong Class,wrong.class@example.test,HR-WRONG,faculty\n",
            "name,email,employee_id,base_salary,credit_limit\nWrong Salary,wrong.salary@example.test,HR-SALARY,99999,24999.75\n",
        ] as $csv) {
            $path = tempnam(sys_get_temp_dir(), 'ibems-hr-profile-');
            $this->assertNotFalse($path);
            file_put_contents($path, $csv);
            try {
                $file = new class($path, 'hr-users.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK) extends UploadedFile {
                    public function isValid(): bool { return true; }
                };
                $result = (new HrStaffService())->importCsv($file, 900);
                $this->assertSame(400, $result['code']);
                $this->assertStringContainsString('accepts only these columns', $result['payload']['message']);
            } finally {
                unlink($path);
            }
        }
        $this->assertSame(0, $db->table('users')->whereIn('email', ['wrong.class@example.test', 'wrong.salary@example.test'])->countAllResults());
    }

    public function testHrStaffImportDoesNotIgnoreExtraOrMissingCells(): void
    {
        foreach ([
            "employee_id,name,email\nHR-EXTRA,Extra Cells,extra.cells@example.test,faculty\n",
            "employee_id,name,email\nHR-MISSING,Missing Cells\n",
        ] as $csv) {
            $path = tempnam(sys_get_temp_dir(), 'ibems-hr-cells-');
            $this->assertNotFalse($path);
            file_put_contents($path, $csv);
            try {
                $file = new class($path, 'hr-users.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK) extends UploadedFile {
                    public function isValid(): bool { return true; }
                };
                $result = (new HrStaffService())->importCsv($file, 900);
                $this->assertSame(200, $result['code']);
                $this->assertSame(0, $result['payload']['created']);
                $this->assertSame(1, $result['payload']['invalid']);
            } finally {
                unlink($path);
            }
        }
        $this->assertSame(0, Database::connect()->table('users')->where('email', 'extra.cells@example.test')->countAllResults());
    }

    public function testHrStaffImportStillCreatesAnAuditedStandardProfile(): void
    {
        $csv = "employee_id,name,email\nHR-CSV-001,CSV Staff,csv.staff@example.test\n";
        $path = tempnam(sys_get_temp_dir(), 'ibems-hr-valid-');
        $this->assertNotFalse($path);
        file_put_contents($path, $csv);
        try {
            $file = new class($path, 'hr-users.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK) extends UploadedFile {
                public function isValid(): bool { return true; }
            };
            $result = (new HrStaffService())->importCsv($file, 900);
            $this->assertSame(200, $result['code'], json_encode($result['payload']));
            $this->assertSame(1, $result['payload']['created']);
            $db = Database::connect();
            $user = $db->table('users')->where('employee_id', 'HR-CSV-001')->get()->getRowArray();
            $this->assertNotNull($user);
            $this->assertSame('staff', $user['user_type']);
            $this->assertSame('plantilla', $user['employment_type']);
            $standard = (new \App\Services\SalaryScheduleService())->standardProfile($db);
            $this->assertSame((float) $standard['monthly_salary'], (float) $user['base_salary']);
            $balance = $db->table('balances')->where('user_id', (int) $user['id'])->get()->getRowArray();
            $this->assertSame(SalaryCreditPolicy::creditLimit((float) $standard['monthly_salary']), (float) $balance['credit_limit']);
            $this->assertSame(0.25, (float) $balance['credit_rate']);
            $this->assertSame(1, $db->table('audit_logs')->where('action', 'HR_IMPORT_STAFF_CSV')->countAllResults());
        } finally {
            unlink($path);
        }
    }

    public function testHrCreatesFacultyAndStaffAcrossEmploymentTypesWithImmediateGradeBasedCredit(): void
    {
        $db = Database::connect();
        $salary = (new \App\Services\SalaryScheduleService())->resolveRate($db, 1, 'SG-11', 1);
        $this->assertNotNull($salary);

        foreach ([
            ['faculty', 'plantilla'],
            ['staff', 'cos'],
            ['faculty', 'part_time'],
        ] as $index => [$userType, $employmentType]) {
            $email = "hr.credit.{$index}@example.test";
            $result = (new HrStaffService())->create([
                'employee_id' => "HR-CREDIT-{$index}",
                'name' => "HR Credit {$index}",
                'email' => $email,
                'user_type' => $userType,
                'employment_type' => $employmentType,
                'salary_schedule_id' => 1,
                'salary_grade' => 'SG-11',
                'salary_step' => 1,
            ], 900);

            $this->assertSame(200, $result['code'], json_encode($result['payload']));
            $user = $db->table('users')->where('email', $email)->get()->getRowArray();
            $this->assertSame($userType, $user['user_type'] ?? null);
            $this->assertSame($employmentType, $user['employment_type'] ?? null);
            $this->assertSame((float) $salary['monthly_salary'], (float) ($user['base_salary'] ?? 0));
            $balance = $db->table('balances')->where('user_id', $user['id'])->get()->getRowArray();
            $this->assertSame(SalaryCreditPolicy::creditLimit((float) $salary['monthly_salary']), (float) ($balance['credit_limit'] ?? 0));
            $this->assertSame(0.25, (float) ($balance['credit_rate'] ?? 0));
        }

        $invalid = (new HrStaffService())->create([
            'name' => 'Bad Step', 'email' => 'bad.step@example.test',
            'user_type' => 'staff', 'employment_type' => 'cos',
            'salary_schedule_id' => 1, 'salary_grade' => 'SG-11', 'salary_step' => '1.5',
        ], 900);
        $this->assertSame(400, $invalid['code']);
        $this->assertSame(0, $db->table('users')->where('email', 'bad.step@example.test')->countAllResults());
    }

    public function testHrCreateRequiresExplicitClassificationAndSalarySelection(): void
    {
        $db = Database::connect();
        $complete = [
            'employee_id' => 'HR-REQUIRED',
            'name' => 'Required Fields',
            'email' => 'hr.required@example.test',
            'user_type' => 'faculty',
            'employment_type' => 'cos',
            'salary_schedule_id' => '1',
            'salary_grade' => 'SG-11',
            'salary_step' => '1',
        ];
        foreach (['user_type', 'employment_type', 'salary_schedule_id', 'salary_grade', 'salary_step'] as $missing) {
            $request = $complete;
            unset($request[$missing]);
            $result = (new HrStaffService())->create($request, 900);
            $this->assertSame(400, $result['code'], $missing);
        }
        $request = $complete;
        $request['salary_schedule_id'] = '1invalid';
        $this->assertSame(400, (new HrStaffService())->create($request, 900)['code']);
        $this->assertSame(0, $db->table('users')->where('email', 'hr.required@example.test')->countAllResults());
        $this->assertSame(0, $db->table('audit_logs')->where('action', 'HR_CREATE_STAFF')->countAllResults());
    }

    public function testSalaryCreditInputsRejectCoercionBeforeFinancialProfileUpdate(): void
    {
        foreach (['1.9', '1e0', 'invalid', '', '0', '9'] as $step) {
            $this->assertNull(SalaryCreditPolicy::parseSalaryStep($step));
        }
        foreach (['banana', '25x', '1e2', '-1', '100.01', '25.001', 'INF', ''] as $percentage) {
            $this->assertNull(SalaryCreditPolicy::parseCreditPercentage($percentage));
        }
        $this->assertSame(3, SalaryCreditPolicy::parseSalaryStep('3'));
        $this->assertSame(25.5, SalaryCreditPolicy::parseCreditPercentage('25.50'));

        $this->seedBalance(501, 5000, 1200, 20000);
        $request = [
            'user_id' => 501,
            'employment_type' => 'plantilla',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-18',
            'salary_step' => '3',
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => '25',
            'reason' => 'Reviewed profile',
        ];
        foreach ([['salary_step', '3.9'], ['credit_percentage', 'banana'], ['credit_percentage', '25.001']] as [$field, $badValue]) {
            $invalid = $request;
            $invalid[$field] = $badValue;
            $result = $this->accountingPost('accounting/debts/financial-profile', $invalid);
            $result->assertStatus(400);
        }

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $user = $db->table('users')->where('id', 501)->get()->getRowArray();
        $this->assertSame(5000.0, (float) $balance['credit_limit']);
        $this->assertSame(1200.0, (float) $balance['current_debt']);
        $this->assertSame(20000.0, (float) $user['base_salary']);
        $this->assertSame(0, $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->countAllResults());

        $adminRequest = [
            'name' => 'New Faculty',
            'email' => 'new.faculty@example.test',
            'user_type' => 'faculty',
            'employment_type' => 'plantilla',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-18',
            'salary_step' => '3',
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 'bad',
        ];
        $adminResult = (new AdminUserService())->create($adminRequest, 900);
        $this->assertSame(400, $adminResult['code']);
        $this->assertSame(0, $db->table('users')->where('email', 'new.faculty@example.test')->countAllResults());
    }

    public function testFutureEffectiveSalaryWaitsAndSameDateRevisionActivatesOnce(): void
    {
        $this->seedBalance(501, 5000, 1200, 20000);
        $futureDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $this->assertTrue(SalaryCreditPolicy::isFutureEffectiveDate($futureDate));

        $futureProfile = [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => $futureDate,
            'credit_percentage' => 25,
            'reason' => 'Future compensation change',
        ];
        $accounting = $this->accountingPost('accounting/debts/financial-profile', $futureProfile);
        $accounting->assertStatus(202);
        $first = $this->jsonBody($accounting);
        $this->assertSame('scheduled', $first['status'] ?? null);
        $this->assertSame(1, (int) ($first['revision'] ?? 0));

        $revision = $futureProfile;
        $revision['monthly_salary'] = '40000.00';
        $revision['reason'] = 'Revised future compensation';
        $second = $this->accountingPost('accounting/debts/financial-profile', $revision);
        $second->assertStatus(202);
        $secondBody = $this->jsonBody($second);
        $this->assertSame(2, (int) ($secondBody['revision'] ?? 0));
        $this->assertSame((int) $first['scheduled_profile_id'], (int) $secondBody['replaced_profile_id']);

        $admin = (new AdminUserService())->create([
            'name' => 'Future Faculty',
            'email' => 'future.faculty@example.test',
            'user_type' => 'faculty',
            'employment_type' => 'plantilla',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => $futureDate,
            'credit_percentage' => 25,
        ], 900);
        $this->assertSame(400, $admin['code']);

        $db = Database::connect();
        $this->assertSame(20000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $this->assertSame(5000.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('credit_limit'));
        $this->assertSame(1200.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
        $this->assertSame(0, $db->table('users')->where('email', 'future.faculty@example.test')->countAllResults());
        $this->assertSame(0, $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->countAllResults());
        $this->assertSame(['replaced', 'scheduled'], array_column($db->table('scheduled_salary_profiles')->orderBy('revision')->get()->getResultArray(), 'status'));
        $profileResponse = $this->accountingGet('accounting/debts/profile?user_id=501');
        $profileResponse->assertOK();
        $profileBody = $this->jsonBody($profileResponse);
        $pending = $profileBody['data']['pending_salary_profiles'] ?? [];
        $this->assertCount(1, $pending);
        $this->assertSame(2, (int) $pending[0]['revision']);

        $scheduler = new \App\Services\ScheduledSalaryProfileService();
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $scheduledId = (int) $secondBody['scheduled_profile_id'];
        $this->assertSame(409, $scheduler->activate($db, $scheduledId, 900, $today)['code']);
        $this->assertSame(20000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $this->assertSame(200, $scheduler->activate($db, $scheduledId, 900, $futureDate)['code']);
        $this->assertSame(409, $scheduler->activate($db, $scheduledId, 900, $futureDate)['code']);
        $this->assertSame(40000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $this->assertSame(10000.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('credit_limit'));
        $this->assertSame(1200.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'SALARY_PROFILE_ACTIVATED')->countAllResults());
        $this->assertSame(409, $scheduler->schedule($db, 501, [
            'effective_date' => $futureDate,
            'employment_type' => 'cos',
            'schedule_id' => 1,
            'schedule_code' => 'TEST',
            'salary_grade_label' => 'SG-11',
            'salary_step' => 1,
            'schedule_monthly_salary' => 40000,
            'monthly_salary' => 41000,
            'credit_rate' => 0.25,
            'credit_limit' => 10250,
        ], 900, 'Too late to revise')['code']);
    }

    public function testAccountingActivationEndpointAppliesOnlyDueProfileAndPreservesDebt(): void
    {
        $this->seedBalance(501, 5000, 1200, 20000);
        $futureDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $scheduled = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => $futureDate,
            'monthly_salary' => '40000.00',
            'credit_percentage' => 25,
            'reason' => 'Approved future compensation',
        ]);
        $scheduled->assertStatus(202);
        $profileId = (int) $this->jsonBody($scheduled)['scheduled_profile_id'];
        $path = 'accounting/debts/scheduled-salary/' . $profileId . '/activate';
        $early = $this->accountingPost($path, []);
        $early->assertStatus(409);

        // Advance only this synthetic profile to a due date; production activation never accepts a client date.
        $db = Database::connect();
        $db->table('scheduled_salary_profiles')->where('id', $profileId)->update(['effective_date' => $today]);
        $queue = $this->accountingGet('accounting/debts/scheduled-salary');
        $queue->assertOK();
        $queueBody = $this->jsonBody($queue);
        $this->assertSame(1, (int) $queueBody['due_count']);
        $this->assertSame('Debt Account', $queueBody['rows'][0]['name']);
        $activated = $this->accountingPost($path, []);
        $activated->assertOK();
        $this->assertSame('success', $this->jsonBody($activated)['status']);
        $this->assertSame(40000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(10000.0, (float) $balance['credit_limit']);
        $this->assertSame(1200.0, (float) $balance['current_debt']);
        $this->accountingPost($path, [])->assertStatus(409);
        $this->assertSame(0, (int) $this->jsonBody($this->accountingGet('accounting/debts/scheduled-salary'))['due_count']);
        $historyResponse = $this->accountingGet('accounting/debts/history?user_id=501');
        $historyResponse->assertOK();
        $history = $this->jsonBody($historyResponse)['data'];
        $this->assertSame(['SALARY_PROFILE_ACTIVATED', 'ACCOUNTING_SCHEDULE_FINANCIAL_PROFILE'], array_column($history, 'action'));
        $this->assertSame(20000.0, (float) $history[0]['payload']['previous_profile']['base_salary']);
        $this->assertSame(40000.0, (float) $history[0]['payload']['new_monthly_salary']);
        $this->seedBalance(502, 100, 0);
        $otherHistory = $this->accountingGet('accounting/debts/history?user_id=502');
        $otherHistory->assertOK();
        $this->assertSame([], $this->jsonBody($otherHistory)['data']);
    }

    public function testScheduledSalaryQueueCanReachPendingItemsBeyondFirstHundred(): void
    {
        $this->seedBalance(501, 5000, 0, 20000);
        $db = Database::connect();
        $start = new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila'));
        $rows = [];
        for ($index = 0; $index < 101; $index++) {
            $rows[] = [
                'user_id' => 501,
                'effective_date' => $start->modify('+' . $index . ' days')->format('Y-m-d'),
                'revision' => 1,
                'status' => 'scheduled',
                'employment_type' => 'cos',
                'salary_schedule_id' => 1,
                'salary_schedule_code' => 'PH-NG-2026-T3',
                'salary_grade' => 'SG-11',
                'salary_step' => 1,
                'schedule_monthly_salary' => 31705,
                'monthly_salary' => 32000,
                'credit_rate' => 0.25,
                'credit_limit' => 8000,
                'reason' => 'Synthetic queue item ' . $index,
                'created_by' => 900,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        $db->table('scheduled_salary_profiles')->insertBatch($rows);

        $first = $this->jsonBody($this->accountingGet('accounting/debts/scheduled-salary'));
        $this->assertSame(101, (int) $first['total_pending']);
        $this->assertCount(50, $first['rows']);
        $this->assertTrue($first['has_more']);
        $third = $this->jsonBody($this->accountingGet('accounting/debts/scheduled-salary?page=3'));
        $this->assertSame(3, (int) $third['page']);
        $this->assertCount(1, $third['rows']);
        $this->assertSame('Synthetic queue item 100', $third['rows'][0]['reason']);
        $this->assertFalse($third['has_more']);
        $this->accountingGet('accounting/debts/scheduled-salary?page=0')->assertStatus(400);
    }

    public function testScheduledSalaryProfilesActivateInDateOrderAndCannotOverwriteNewerActiveSalary(): void
    {
        $this->seedBalance(501, 5000, 300, 20000);
        $dayOne = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $dayTwo = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+2 days')->format('Y-m-d');
        $base = [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'credit_percentage' => 25,
        ];
        $first = $this->accountingPost('accounting/debts/financial-profile', $base + [
            'salary_effective_date' => $dayOne,
            'monthly_salary' => '30000.00',
            'reason' => 'First approved change',
        ]);
        $first->assertStatus(202);
        $firstId = (int) $this->jsonBody($first)['scheduled_profile_id'];
        $second = $this->accountingPost('accounting/debts/financial-profile', $base + [
            'salary_effective_date' => $dayTwo,
            'monthly_salary' => '40000.00',
            'reason' => 'Second approved change',
        ]);
        $second->assertStatus(202);
        $secondId = (int) $this->jsonBody($second)['scheduled_profile_id'];
        $db = Database::connect();
        $service = new \App\Services\ScheduledSalaryProfileService();
        $this->assertSame(409, $service->activate($db, $secondId, 900, $dayTwo)['code']);
        $this->assertSame(20000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $firstActivation = $service->activate($db, $firstId, 900, $dayTwo);
        $this->assertSame(200, $firstActivation['code'], json_encode($firstActivation['payload']));
        $this->assertSame(30000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $this->assertSame(200, $service->activate($db, $secondId, 900, $dayTwo)['code']);
        $this->assertSame(40000.0, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $this->assertSame(300.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));

        $this->seedBalance(502, 5000, 0, 20000);
        $third = $this->accountingPost('accounting/debts/financial-profile', array_replace($base, [
            'user_id' => 502,
            'salary_effective_date' => $dayOne,
            'monthly_salary' => '35000.00',
            'reason' => 'Superseded by a current decision',
        ]));
        $third->assertStatus(202);
        $thirdId = (int) $this->jsonBody($third)['scheduled_profile_id'];
        $db->table('users')->where('id', 502)->update(['salary_effective_date' => $dayTwo, 'base_salary' => 45000]);
        $this->assertSame(409, $service->activate($db, $thirdId, 900, $dayTwo)['code']);
        $this->assertSame(45000.0, (float) $db->table('users')->where('id', 502)->get()->getRow('base_salary'));
    }

    public function testAccountingCanAuditIndividualSalaryOverrideAndDerivedCredit(): void
    {
        $this->seedBalance(501, 5000, 1200, 20000);
        $request = [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'monthly_salary' => '32123.45',
            'credit_percentage' => 25,
            'reason' => 'Approved institutional compensation differs from grade rate',
        ];

        $invalid = $request;
        $invalid['monthly_salary'] = '1e5';
        $this->accountingPost('accounting/debts/financial-profile', $invalid)->assertStatus(400);
        $this->assertSame(20000.0, (float) Database::connect()->table('users')->where('id', 501)->get()->getRow('base_salary'));

        $result = $this->accountingPost('accounting/debts/financial-profile', $request);
        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(32123.45, (float) ($body['new_base_salary'] ?? 0));
        $this->assertSame(31705.0, (float) ($body['schedule_monthly_salary'] ?? 0));
        $this->assertTrue((bool) ($body['salary_override'] ?? false));
        $this->assertSame(8030.86, (float) ($body['new_credit_limit'] ?? 0));

        $db = Database::connect();
        $this->assertSame(32123.45, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(8030.86, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(1200.0, (float) ($balance['current_debt'] ?? 0));
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();
        $payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
        $this->assertSame('Approved institutional compensation differs from grade rate', $payload['reason'] ?? null);
        $this->assertTrue((bool) ($payload['salary_override'] ?? false));
        $this->assertSame(31705.0, (float) ($payload['schedule_monthly_salary'] ?? 0));
    }

    public function testAdminAccountEditDoesNotEraseAccountingSalaryOverride(): void
    {
        $this->seedBalance(501, 5000, 1200, 20000);
        $profile = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'monthly_salary' => '32123.45',
            'credit_percentage' => 25,
            'reason' => 'Accounting-approved institutional rate',
        ]);
        $profile->assertOK();

        $updated = (new AdminUserService())->update([
            'user_id' => 501,
            'employee_id' => 'EMP-501',
            'name' => 'Renamed Staff',
            'email' => 'debt501@example.test',
            'user_type' => 'staff',
            'roles' => ['USER'],
            'is_active' => 1,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 25,
        ], 900);
        $this->assertSame(200, $updated['code']);
        $db = Database::connect();
        $this->assertSame('Renamed Staff', $db->table('users')->where('id', 501)->get()->getRow('name'));
        $this->assertSame(32123.45, (float) $db->table('users')->where('id', 501)->get()->getRow('base_salary'));
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(8030.86, (float) $balance['credit_limit']);
        $this->assertSame(1200.0, (float) $balance['current_debt']);
    }

    public function testAdminGradeChangeCannotReplaceAccountingSalaryOverride(): void
    {
        $this->seedBalance(501, 5000, 1200, 20000);
        $profile = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'monthly_salary' => '32123.45',
            'credit_percentage' => 25,
            'reason' => 'Accounting-approved institutional rate',
        ]);
        $profile->assertOK();

        $updated = (new AdminUserService())->update([
            'user_id' => 501,
            'employee_id' => 'EMP-501',
            'name' => 'Attempted Rename',
            'email' => 'debt501@example.test',
            'user_type' => 'staff',
            'roles' => ['USER'],
            'is_active' => 1,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-18',
            'salary_step' => 3,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 25,
        ], 900);
        $this->assertSame(403, $updated['code']);
        $db = Database::connect();
        $user = $db->table('users')->where('id', 501)->get()->getRowArray();
        $this->assertSame('SG-11', $user['salary_grade']);
        $this->assertSame(32123.45, (float) $user['base_salary']);
        $this->assertSame(8030.86, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('credit_limit'));
        $this->assertSame(1200.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
    }

    public function testAccountingUsesTheSharedInAppPayrollDeductionWorkflow(): void
    {
        $layout = file_get_contents(APPPATH . 'Views/layouts/accounting.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = file_get_contents(APPPATH . 'Controllers/AccountingController.php');
        $view = file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $script = file_get_contents(FCPATH . 'assets/js/accounting-debts.js') . file_get_contents(FCPATH . 'assets/js/accounting-debts.part2.js') . file_get_contents(FCPATH . 'assets/js/accounting-debts.part3.js');

        $this->assertStringContainsString("'deductions/workspace'", $layout);
        $this->assertStringNotContainsString("accounting/deduction-batches/(:num)/export", $routes);
        $this->assertStringNotContainsString("accounting/deduction-batches/(:num)/import-results", $routes);
        $this->assertStringNotContainsString('public function exportDeductionBatch(', $controller);
        $this->assertStringNotContainsString('public function importDeductionResults(', $controller);
        $this->assertStringNotContainsString('private function addDebtCashbookEntry(', $controller);
        $this->assertStringNotContainsString('workflow-results-import', $view);
        $this->assertStringContainsString("site_url('deductions/workspace')", $view);
        $this->assertStringNotContainsString('Edit deductions', $script);
    }

    public function testAdminIdentityEditsAndCsvCannotChangeExistingCredit(): void
    {
        $this->seedBalance(501, 5000, 1200, 20000);
        $db = Database::connect();
        $beforeUser = $db->table('users')->where('id',501)->get()->getRowArray();
        $beforeBalance = $db->table('balances')->where('user_id',501)->get()->getRowArray();
        $identity = ['user_id'=>501,'employee_id'=>'EMP-501','name'=>'Renamed identity','email'=>'debt501@example.test','user_type'=>'staff','roles'=>['USER','HR'],'is_active'=>1];
        foreach ([['credit_percentage'=>40],['credit_limit'=>9000],['credit_rate'=>.4],['salary_grade'=>'SG-18'],['base_salary'=>40000],['credit_percentage'=>false]] as $forged) {
            $result = (new AdminUserService())->update($identity + $forged,900);
            $this->assertSame(403,$result['code']);
            $this->assertSame($beforeUser,$db->table('users')->where('id',501)->get()->getRowArray());
            $this->assertSame($beforeBalance,$db->table('balances')->where('user_id',501)->get()->getRowArray());
        }
        $this->assertSame(200,(new AdminUserService())->update($identity,900)['code']);
        $this->assertSame('Renamed identity',$db->table('users')->where('id',501)->get()->getRow('name'));
        $this->assertSame($beforeBalance,$db->table('balances')->where('user_id',501)->get()->getRowArray());
        $this->assertSame(20000.0,(float)$db->table('users')->where('id',501)->get()->getRow('base_salary'));
        $this->assertSame(['HR','USER'],array_column($db->table('user_roles')->where('user_id',501)->orderBy('role')->get()->getResultArray(),'role'));
        // A category correction must not reset credit or clear outstanding debt.
        $identity['user_type']='student';
        $this->assertSame(200,(new AdminUserService())->update($identity,900)['code']);
        $this->assertSame($beforeBalance,$db->table('balances')->where('user_id',501)->get()->getRowArray());
        // An existing legacy profile cannot be re-onboarded through a CSV update.
        $db->table('users')->where('id',501)->update(['salary_schedule_id'=>null]);
        $path=tempnam(sys_get_temp_dir(),'admin-credit-qa-');
        try {
            file_put_contents($path,"employee_id,name,email,user_type\nEMP-501,CSV identity,debt501@example.test,staff\n");
            $this->assertSame(200,(new AdminUserService())->importCsv(new class($path,'synthetic.csv','text/csv',filesize($path),UPLOAD_ERR_OK) extends UploadedFile { public function isValid(): bool { return true; } },900)['code']);
            $this->assertSame($beforeBalance,$db->table('balances')->where('user_id',501)->get()->getRowArray());
            $this->assertNull($db->table('users')->where('id',501)->get()->getRow('salary_schedule_id'));
        } finally {unlink($path);}
    }

    public function testCreditAuthorityUsesActiveRoleEvenWithAdminAndAccountingAssignments(): void
    {
        $this->seedBalance(501,5000,1200,20000);$db=Database::connect();
        $db->table('users')->insert(['id'=>900,'name'=>'Synthetic multi-role','email'=>'multi@example.test','role'=>'ADMIN','user_type'=>'staff','is_active'=>1]);
        foreach(['ADMIN','ACCOUNTING_OFFICE'] as $role)$db->table('user_roles')->insert(['user_id'=>900,'role'=>$role]);
        $this->withRoutes([
            ['POST','admin/user-view/update','AdminController::updateUser',['filter'=>'access:system.manage']],
            ['POST','accounting/debts/financial-profile','AccountingController::updateFinancialProfile',['filter'=>'access:accounting.debt_manage']],
        ]);
        $post=function(string $role,string $path,array $payload){
            $security=service('security');$payload[$security->getTokenName()]=$security->getHash();
            return $this->withSession(['logged_in'=>true,'user_id'=>900,'role'=>$role,'available_roles'=>['ADMIN','ACCOUNTING_OFFICE']])
                ->withHeaders(['Accept'=>'application/json'])->withBodyFormat('json')->post($path,$payload);
        };
        $profile=['user_id'=>501,'employment_type'=>'plantilla','salary_schedule_id'=>1,'salary_grade'=>'SG-11','salary_step'=>1,'salary_effective_date'=>'2026-08-21','monthly_salary'=>'20000.00','credit_percentage'=>40,'reason'=>'Approved Accounting credit review'];
        $post('ADMIN','admin/user-view/update',['user_id'=>501,'name'=>'Forbidden','email'=>'debt501@example.test','user_type'=>'staff','roles'=>['USER'],'credit_percentage'=>40])->assertStatus(403);
        $post('ADMIN','accounting/debts/financial-profile',$profile)->assertStatus(403);
        $this->assertSame(5000.0,(float)$db->table('balances')->where('user_id',501)->get()->getRow('credit_limit'));
        $post('ACCOUNTING_OFFICE','accounting/debts/financial-profile',$profile)->assertOK();
        $balance=$db->table('balances')->where('user_id',501)->get()->getRowArray();
        $this->assertSame(8000.0,(float)$balance['credit_limit']);$this->assertSame(.4,(float)$balance['credit_rate']);$this->assertSame(1200.0,(float)$balance['current_debt']);
        $audit=$db->table('audit_logs')->where('action','ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();$snapshot=json_decode($audit['payload_json'],true);
        $this->assertSame(900,(int)$audit['actor_id']);$this->assertSame(5000.0,(float)$snapshot['previous_credit_limit']);$this->assertSame(8000.0,(float)$snapshot['new_credit_limit']);
        $this->assertSame(25.0,(float)$snapshot['previous_credit_percentage']);$this->assertSame(40.0,(float)$snapshot['new_credit_percentage']);$this->assertSame($profile['reason'],$snapshot['reason']);
        unset($profile['reason']);$post('ACCOUNTING_OFFICE','accounting/debts/financial-profile',$profile)->assertStatus(400);
        $post('ACCOUNTING_OFFICE','admin/user-view/update',['user_id'=>501,'name'=>'Wrong portal','email'=>'debt501@example.test'])->assertStatus(403);
    }

    public function testAuthorizedFinancialChangeRollsBackIfItsAuditCannotBeWritten(): void
    {
        $this->seedBalance(501,5000,1200,20000);$db=Database::connect();$table=$db->getPrefix().'audit_logs';
        $db->query("CREATE TRIGGER reject_financial_audit BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT,'Synthetic audit failure'); END");
        $result=(new \App\Services\AccountingFinancialProfileService())->update($db,['user_id'=>501,'employment_type'=>'plantilla','salary_schedule_id'=>1,'salary_grade'=>'SG-11','salary_step'=>1,'salary_effective_date'=>'2026-08-21','monthly_salary'=>'20000.00','credit_percentage'=>40,'reason'=>'Synthetic audited review'],900);
        $db->resetTransStatus();
        $this->assertSame(500,$result['code']);$this->assertSame(5000.0,(float)$db->table('balances')->where('user_id',501)->get()->getRow('credit_limit'));
        $this->assertSame(.25,(float)$db->table('balances')->where('user_id',501)->get()->getRow('credit_rate'));$this->assertSame(1200.0,(float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt'));
        $this->assertSame(0,$db->table('audit_logs')->countAllResults());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->withRoutes([
            ['GET', 'accounting/dashboard/data', 'AccountingController::dashboardData'],
            ['GET', 'accounting/debts/scheduled-salary', 'AccountingController::scheduledSalaryQueue'],
            ['GET', 'accounting/debts/data', 'AccountingController::debtsData'],
            ['GET', 'accounting/debts/daily-summary', 'AccountingController::dailySummary'],
            ['GET', 'accounting/salary-schedules', 'AccountingController::salarySchedules'],
            ['GET', 'accounting/settlement/preview', 'AccountingController::settlementPreview'],
            ['GET', 'accounting/settlement/runs', 'AccountingController::settlementRuns'],
            ['GET', 'accounting/settlement/runs/(:num)', 'AccountingController::settlementRunDetails/$1'],
            ['POST', 'accounting/debts/financial-profile', 'AccountingController::updateFinancialProfile'],
            ['POST', 'accounting/debts/scheduled-salary/(:num)/activate', 'AccountingController::activateScheduledSalaryProfile/$1'],
        ]);
    }

    public function testDebtDataUsesBalanceStateLabelsAndAccountCount(): void
    {
        $this->seedBalance(501, 100, 100);
        $this->seedBalance(502, 500, 125);
        $this->seedBalance(503, 500, 0);

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/debts/data');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(3, (int) ($body['summary']['employee_account_count'] ?? 0));
        $this->assertSame(2, (int) ($body['summary']['employee_debt_accounts'] ?? 0));

        $statuses = array_column($body['data'] ?? [], 'debt_status_label', 'user_id');
        $this->assertSame('At Credit Limit', $statuses[501] ?? null);
        $this->assertSame('Outstanding', $statuses[502] ?? null);
        $this->assertSame('No Outstanding Debt', $statuses[503] ?? null);
    }

    public function testDashboardReportingPreservesItsResponseContractAfterExtraction(): void
    {
        $this->seedBalance(501, 500, 125);

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/dashboard/data');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertSame(1, (int) ($body['summary']['total_accounts'] ?? 0));
        $this->assertSame(1, (int) ($body['summary']['with_debt'] ?? 0));
        $this->assertSame(125.0, (float) ($body['summary']['total_debt'] ?? 0));
        $this->assertSame(501, (int) ($body['top_debt_accounts'][0]['user_id'] ?? 0));
        $this->assertSame('day', $body['period']['key'] ?? null);
        $this->assertCount(1, $body['trend'] ?? []);
        $this->assertArrayHasKey('over_limit', $body['alerts'] ?? []);
        $this->assertArrayHasKey('stale_debts', $body['alerts'] ?? []);
        $this->assertArrayHasKey('failed_imports', $body['alerts'] ?? []);
    }

    public function testLegacySettlementPreviewRemainsReadOnlyAndPreservesItsContract(): void
    {
        $this->seedBalance(501, 1000, 600, 250);

        $result = $this->accountingGet('accounting/settlement/preview?run_month=2026-07');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('2026-07', $body['run_month'] ?? null);
        $this->assertSame(1, (int) ($body['summary']['candidate_count'] ?? 0));
        $this->assertSame(250.0, (float) ($body['summary']['total_deductible'] ?? 0));
        $this->assertSame(350.0, (float) ($body['summary']['total_debt_after'] ?? 0));
        $this->assertSame(0, Database::connect()->table('debt_cashbook_entries')->countAllResults());
    }

    public function testLegacySettlementHistoryRemainsReadableAfterExtraction(): void
    {
        $this->seedBalance(501, 1000, 350, 250);
        $db = Database::connect();
        $db->table('settlement_runs')->insert([
            'id' => 7,
            'run_month' => '2026-07',
            'run_by' => 900,
            'run_at' => '2026-07-31 09:00:00',
            'total_accounts' => 1,
            'total_debt_before' => 600,
            'notes' => json_encode(['note' => 'Legacy payroll run']),
        ]);
        $db->table('audit_logs')->insert([
            'actor_id' => 900,
            'action' => 'ACCOUNTING_SETTLEMENT_DEDUCT',
            'entity' => 'balances',
            'entity_id' => 501,
            'payload_json' => json_encode([
                'run_id' => 7,
                'previous_debt' => 600,
                'deducted_amount' => 250,
                'new_debt' => 350,
                'monthly_salary' => 250,
            ]),
            'created_at' => '2026-07-31 09:00:00',
        ]);

        $runs = $this->accountingGet('accounting/settlement/runs');
        $runs->assertOK();
        $runsBody = $this->jsonBody($runs);
        $this->assertSame(7, (int) ($runsBody['data'][0]['id'] ?? 0));

        $details = $this->accountingGet('accounting/settlement/runs/7');
        $details->assertOK();
        $detailsBody = $this->jsonBody($details);
        $this->assertSame(1, (int) ($detailsBody['summary']['processed_accounts'] ?? 0));
        $this->assertSame(250.0, (float) ($detailsBody['summary']['total_deducted'] ?? 0));
        $this->assertSame(350.0, (float) ($detailsBody['items'][0]['new_debt'] ?? 0));
    }

    public function testDailySummaryUsesManilaBusinessDayForUtcCashbookTimestamps(): void
    {
        $this->seedBalance(502, 500, 40);
        $manila = new DateTimeZone('Asia/Manila');
        $utc = new DateTimeZone('UTC');
        $storedAt = (new DateTimeImmutable('today 00:30:00', $manila))->setTimezone($utc)->format('Y-m-d H:i:s');

        Database::connect()->table('debt_cashbook_entries')->insert([
            'user_id' => 501,
            'entry_type' => 'confirmed_salary_deduction',
            'direction' => 'credit',
            'amount' => 140,
            'debt_before' => 200,
            'debt_after' => 60,
            'created_at' => $storedAt,
            'updated_at' => $storedAt,
        ]);

        Database::connect()->table('deduction_batch_items')->insert([
            'batch_id' => 1,
            'user_id' => 502,
            'debt_at_cutoff' => 40,
            'requested_amount' => 40,
            'confirmed_amount' => 40,
            'carryover_amount' => 0,
            'result_status' => 'fully_deducted',
            'confirmed_at' => $storedAt,
            'created_at' => $storedAt,
            'updated_at' => $storedAt,
        ]);
        Database::connect()->table('deduction_periods')->insert(['id' => 1, 'period_code' => 'TEST-CURRENT', 'status' => 'finalized']);
        Database::connect()->table('deduction_batches')->insert(['id' => 1, 'period_id' => 1]);
        Database::connect()->table('debt_cashbook_entries')->insert([
            'user_id' => 502,
            'entry_type' => 'salary_deduction',
            'direction' => 'credit',
            'amount' => 40,
            'reference_type' => 'deduction_batch',
            'reference_id' => 1,
            'created_at' => $storedAt,
            'updated_at' => $storedAt,
        ]);

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/debts/daily-summary');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame((new DateTimeImmutable('now', $manila))->format('Y-m-d'), $body['date'] ?? null);
        $this->assertSame(1, (int) ($body['deduction_count'] ?? 0));
        $this->assertSame(40.0, (float) ($body['deducted_amount'] ?? 0));

        $dashboard = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/dashboard/data');
        $dashboard->assertOK();
        $dashboardBody = $this->jsonBody($dashboard);
        $this->assertSame(1, (int) ($dashboardBody['summary']['period_deduction_count'] ?? 0));
        $this->assertSame(40.0, (float) ($dashboardBody['summary']['period_deduction_amount'] ?? 0));
        $this->assertSame(1, (int) ($dashboardBody['trend'][0]['count'] ?? 0));
        $this->assertSame(40.0, (float) ($dashboardBody['trend'][0]['amount'] ?? 0));
    }

    public function testSalaryReductionPreservesDebtAndDerivesCreditLimit(): void
    {
        $this->seedBalance(501, 5000, 1200, 32000);

        $result = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'cos',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-1',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 10,
            'reason' => 'Contract compensation adjustment',
        ]);

        $this->assertTrue($result->isOK(), (string) $result->getJSON());
        $body = $this->jsonBody($result);
        $this->assertSame(5000.0, (float) ($body['previous_credit_limit'] ?? 0));
        $this->assertSame(1463.4, (float) ($body['new_credit_limit'] ?? 0));

        $db = Database::connect();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();
        $this->assertSame(1463.4, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(0.1, (float) ($balance['credit_rate'] ?? 0));
        $this->assertSame(1200.0, (float) ($balance['current_debt'] ?? 0));
        $this->assertNotNull($audit);
    }

    public function testAccountingSetsSalaryAndCreditTogetherWithAuditTrail(): void
    {
        $adminSource = file_get_contents(APPPATH . 'Services/AdminUserService.php');
        $adminView = file_get_contents(APPPATH . 'Views/admin/user-view.php');
        $this->assertStringContainsString('resolveEmployeeFinancialProfile', $adminSource);
        $this->assertStringNotContainsString('DEFAULT_EMPLOYEE_CREDIT_LIMIT', $adminSource);
        $this->assertStringContainsString('uv-a-schedule', $adminView);
        $this->assertStringContainsString('uv-a-credit-percent', $adminView);

        $this->seedBalance(501, 0, 0, 0);

        $result = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 501,
            'employment_type' => 'plantilla',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-18',
            'salary_step' => 3,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 30,
            'reason' => 'Initial financial profile assignment',
        ]);

        $this->assertTrue($result->isOK(), (string) $result->getJSON());
        $body = $this->jsonBody($result);
        $this->assertSame(54933.0, (float) ($body['new_base_salary'] ?? 0));
        $this->assertSame(16479.9, (float) ($body['new_credit_limit'] ?? 0));

        $db = Database::connect();
        $user = $db->table('users')->where('id', 501)->get()->getRowArray();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $audit = $db->table('audit_logs')->where('action', 'ACCOUNTING_UPDATE_FINANCIAL_PROFILE')->get()->getRowArray();

        $this->assertSame(54933.0, (float) ($user['base_salary'] ?? 0));
        $this->assertSame('plantilla', $user['employment_type'] ?? null);
        $this->assertSame('SG-18', $user['salary_grade'] ?? null);
        $this->assertSame(16479.9, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(0.3, (float) ($balance['credit_rate'] ?? 0));
        $this->assertSame(0.0, (float) ($balance['current_debt'] ?? -1));
        $this->assertSame(900, (int) ($audit['actor_id'] ?? 0));
        $payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
        $this->assertSame('Initial financial profile assignment', $payload['reason'] ?? null);
    }

    public function testAccountingListsAndCanInitializeEligibleEmployeeWithoutBalance(): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 504,
            'employee_id' => 'EMP-504',
            'name' => 'Needs Setup',
            'email' => 'needs.setup@example.test',
            'role' => 'USER',
            'user_type' => 'faculty',
            'base_salary' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $list = $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get('accounting/debts/data');
        $list->assertOK();
        $body = $this->jsonBody($list);
        $row = array_values(array_filter($body['data'] ?? [], static fn(array $item): bool => (int) ($item['user_id'] ?? 0) === 504))[0] ?? [];
        $this->assertFalse((bool) ($row['financial_profile_configured'] ?? true));
        $this->assertSame(1, (int) ($body['summary']['needs_setup_count'] ?? 0));

        $save = $this->accountingPost('accounting/debts/financial-profile', [
            'user_id' => 504,
            'employment_type' => 'part_time',
            'salary_schedule_id' => 1,
            'salary_grade' => 'SG-11',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'credit_percentage' => 25,
            'reason' => 'Initial Accounting setup',
        ]);
        $this->assertTrue($save->isOK(), (string) $save->getJSON());
        $saved = $this->jsonBody($save);
        $this->assertTrue((bool) ($saved['financial_profile_created'] ?? false));
        $balance = $db->table('balances')->where('user_id', 504)->get()->getRowArray();
        $this->assertSame(7926.25, (float) ($balance['credit_limit'] ?? 0));
        $this->assertSame(0.0, (float) ($balance['current_debt'] ?? -1));
    }

    private function accountingPost(string $path, array $payload)
    {
        $security = service('security');
        $payload[$security->getTokenName()] = $security->getHash();

        return $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->withBodyFormat('json')->post($path, $payload);
    }

    private function accountingGet(string $path)
    {
        // Profile/history use the real protected routes: their session actor must
        // exist authoritatively, rather than relying on cached-role fallback.
        $db = Database::connect();
        if (!$db->table('users')->where('id', 900)->countAllResults()) {
            $db->table('users')->insert([
                'id' => 900, 'name' => 'Synthetic Accounting Operator',
                'email' => 'accounting900@example.test', 'role' => 'ACCOUNTING_OFFICE',
                'user_type' => 'staff', 'is_active' => 1,
            ]);
            $db->table('user_roles')->insert(['user_id' => 900, 'role' => 'ACCOUNTING_OFFICE']);
        }
        return $this->withSession([
            'logged_in' => true,
            'user_id' => 900,
            'role' => 'ACCOUNTING_OFFICE',
            'available_roles' => ['ACCOUNTING_OFFICE'],
        ])->get($path);
    }

    private function jsonBody($result): array
    {
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function seedBalance(int $userId, float $creditLimit, float $currentDebt, float $baseSalary = 0): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => $userId,
            'employee_id' => 'EMP-' . $userId,
            'name' => 'Debt Account',
            'email' => 'debt' . $userId . '@example.test',
            'role' => 'USER',
            'user_type' => 'staff',
            'base_salary' => $baseSalary,
            'employment_type' => 'plantilla',
            'salary_grade' => 'SG-TEST',
            'salary_step' => 1,
            'salary_effective_date' => '2026-08-21',
            'salary_schedule_id' => 1,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('balances')->insert([
            'user_id' => $userId,
            'credit_limit' => $creditLimit,
            'credit_rate' => 0.25,
            'current_debt' => $currentDebt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        foreach (['salary_import_rows', 'salary_import_batches', 'deduction_periods', 'deduction_batches', 'deduction_batch_items', 'debt_cashbook_entries', 'audit_logs', 'settlement_runs', 'salary_schedule_rates', 'salary_schedules', 'scheduled_salary_profiles', 'balances', 'user_roles', 'stores', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('stores') . ' (id INTEGER PRIMARY KEY, officer_id INTEGER)');

        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT,
            qr_token TEXT,
            profile_image_url TEXT,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            base_salary REAL NOT NULL DEFAULT 0,
            employment_type TEXT,
            salary_grade TEXT,
            salary_step INTEGER,
            salary_effective_date TEXT,
            salary_schedule_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('balances') . ' (
            user_id INTEGER PRIMARY KEY,
            credit_limit REAL NOT NULL DEFAULT 0,
            credit_rate REAL NOT NULL DEFAULT 0.25,
            current_debt REAL NOT NULL DEFAULT 0,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('salary_schedules') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            effective_from TEXT NOT NULL,
            effective_to TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('salary_schedule_rates') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_id INTEGER NOT NULL,
            salary_grade INTEGER NOT NULL,
            salary_step INTEGER NOT NULL,
            monthly_salary REAL NOT NULL
        )');

        $db->table('salary_schedules')->insert([
            'id' => 1,
            'code' => 'PH-NG-2026-T3',
            'name' => 'Philippine National Government 2026 - Third Tranche',
            'effective_from' => '2026-01-01',
            'is_active' => 1,
        ]);
        $db->table('salary_schedule_rates')->insertBatch([
            ['schedule_id' => 1, 'salary_grade' => 1, 'salary_step' => 1, 'monthly_salary' => 14634],
            ['schedule_id' => 1, 'salary_grade' => 11, 'salary_step' => 1, 'monthly_salary' => 31705],
            ['schedule_id' => 1, 'salary_grade' => 18, 'salary_step' => 3, 'monthly_salary' => 54933],
        ]);

        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            payload_json TEXT,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('settlement_runs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_month TEXT NOT NULL UNIQUE,
            run_by INTEGER,
            run_at TEXT,
            total_accounts INTEGER NOT NULL DEFAULT 0,
            total_debt_before REAL NOT NULL DEFAULT 0,
            notes TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            entry_type TEXT,
            direction TEXT,
            amount REAL,
            debt_before REAL,
            debt_after REAL,
            credit_limit_snapshot REAL,
            available_credit_snapshot REAL,
            reference_type TEXT,
            reference_id INTEGER,
            actor_id INTEGER,
            remarks TEXT,
            meta_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('deduction_batch_items') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER,
            user_id INTEGER,
            debt_at_cutoff REAL,
            requested_amount REAL,
            confirmed_amount REAL,
            carryover_amount REAL,
            result_status TEXT,
            result_reference TEXT,
            confirmed_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('deduction_periods') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_code TEXT,
            label TEXT,
            status TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('user_roles') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('scheduled_salary_profiles') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            effective_date TEXT NOT NULL,
            revision INTEGER NOT NULL,
            status TEXT NOT NULL,
            employment_type TEXT NOT NULL,
            salary_schedule_id INTEGER NOT NULL,
            salary_schedule_code TEXT NOT NULL,
            salary_grade TEXT NOT NULL,
            salary_step INTEGER NOT NULL,
            schedule_monthly_salary REAL NOT NULL,
            monthly_salary REAL NOT NULL,
            credit_rate REAL NOT NULL,
            credit_limit REAL NOT NULL,
            reason TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            replaces_id INTEGER,
            created_at TEXT NOT NULL,
            applied_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('deduction_batches') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER
        )');

        $db->query('CREATE TABLE ' . $tn('salary_import_batches') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT,
            imported_by INTEGER,
            imported_at TEXT,
            total_rows INTEGER NOT NULL DEFAULT 0,
            valid_rows INTEGER NOT NULL DEFAULT 0,
            invalid_rows INTEGER NOT NULL DEFAULT 0
        )');

        $db->resetDataCache();
    }
}
