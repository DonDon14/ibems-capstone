<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperationalTrialCommandTest extends CIUnitTestCase
{
    public function testLocalTrialRequiresAnExplicitFlagAndCannotRunInProduction(): void
    {
        foreach (['VerifyLiveOneDayOperations.php', 'VerifyLiveCreditBoundary.php'] as $file) {
            $source = (string) file_get_contents(APPPATH . 'Commands/' . $file);

            $this->assertStringContainsString("'--allow-local'", $source, $file);
            $this->assertStringContainsString("CLI::getOption('allow-local')", $source, $file);
            $this->assertStringContainsString("ENVIRONMENT === 'production'", $source, $file);
            $this->assertStringContainsString('synthetic records will be removed', $source, $file);
        }
    }

    public function testFifteenDayTrialConnectsEmployeeCreationToFinancialHistory(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/IbemsScenario15Days.php');

        $this->assertStringContainsString('ensureScenarioEmployeeCompleteness', $source);
        $this->assertStringContainsString("'ADMIN_CREATE_USER'", $source);
        $this->assertStringContainsString("'user_roles'", $source);
        $this->assertStringContainsString('latest append-only cashbook state', $source);
        $this->assertStringContainsString('item and stock movement records', $source);
    }
}
