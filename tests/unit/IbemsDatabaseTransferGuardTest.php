<?php

namespace Tests\Unit;

use App\Commands\IbemsDatabaseTransfer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;

final class IbemsDatabaseTransferGuardTest extends TestCase
{
    private string|false $previousResetFlag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResetFlag = getenv('IBEMS_ALLOW_STAGING_RESET');
        putenv('IBEMS_ALLOW_STAGING_RESET=1');
    }

    protected function tearDown(): void
    {
        if ($this->previousResetFlag === false) {
            putenv('IBEMS_ALLOW_STAGING_RESET');
        } else {
            putenv('IBEMS_ALLOW_STAGING_RESET=' . $this->previousResetFlag);
        }
        parent::tearDown();
    }

    public function testVerifiedStagingTargetIsAcceptedWithExplicitResetFlag(): void
    {
        $this->invokeGuard($this->target(
            'aws-0-ap-southeast-1.pooler.supabase.com',
            'postgres.pukjmscgjtmqvhdncjpo'
        ));

        $this->addToAssertionCount(1);
    }

    public function testProductionLikeProjectOwnerIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verified Supabase staging project');

        $this->invokeGuard($this->target(
            'aws-0-ap-southeast-1.pooler.supabase.com',
            'postgres.productionprojectref'
        ));
    }

    public function testResetFlagRemainsRequiredForVerifiedStaging(): void
    {
        putenv('IBEMS_ALLOW_STAGING_RESET');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IBEMS_ALLOW_STAGING_RESET=1');

        $this->invokeGuard($this->target(
            'aws-0-ap-southeast-1.pooler.supabase.com',
            'postgres.pukjmscgjtmqvhdncjpo'
        ));
    }

    private function invokeGuard(object $target): void
    {
        $method = new ReflectionMethod(IbemsDatabaseTransfer::class, 'assertStagingImportTarget');
        $command = (new ReflectionClass(IbemsDatabaseTransfer::class))->newInstanceWithoutConstructor();
        $method->invoke($command, $target);
    }

    private function target(string $hostname, string $username): object
    {
        $target = new stdClass();
        $target->DBDriver = 'Postgre';
        $target->database = 'postgres';
        $target->hostname = $hostname;
        $target->username = $username;

        return $target;
    }
}
