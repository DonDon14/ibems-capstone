<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalaryScheduleMigrationConventionTest extends TestCase
{
    public function testLocalMigrationDoesNotOverwriteExistingFinancialProfiles(): void
    {
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-08-21-000003_CreateDynamicSalarySchedules.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString("->table('users')->where('id', " . '$userId' . ")->update", $source);
        $this->assertStringNotContainsString("'credit_limit' => " . '$standardLimit', $source);
        $this->assertStringContainsString('Do not guess a grade/step', $source);
    }

    public function testHostedMigrationDoesNotOverwriteExistingFinancialProfiles(): void
    {
        $source = file_get_contents(ROOTPATH . 'database/postgresql/012_dynamic_salary_schedules.sql');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('set base_salary = 31705.00', $source);
        $this->assertStringNotContainsString('set credit_limit = 7926.25', $source);
        $this->assertStringContainsString('Leave them unchanged', $source);
    }
}
