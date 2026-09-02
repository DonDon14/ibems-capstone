<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ControllerDecompositionTest extends TestCase
{
    public function testAccountingControllerDelegatesExtractedFinancialWorkflows(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AccountingController.php');

        $this->assertLessThanOrEqual(800, count(file(APPPATH . 'Controllers/AccountingController.php')));
        foreach ([
            'AccountingReportingService',
            'AccountingDebtQueryService',
            'AccountingSettlementQueryService',
            'AccountingFinancialProfileService',
        ] as $service) {
            $this->assertStringContainsString($service, $controller);
            $this->assertFileExists(APPPATH . 'Services/' . $service . '.php');
        }
    }

    public function testStoreDashboardAndReportsDelegateToQueryServices(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/StoreController.php');
        $dashboard = (string) file_get_contents(APPPATH . 'Services/StoreDashboardService.php');
        $reports = (string) file_get_contents(APPPATH . 'Services/StoreReportService.php');
        $reportRequest = (string) file_get_contents(APPPATH . 'Services/StoreReportRequestService.php');

        $this->assertStringContainsString('(new StoreDashboardService())->data(', $controller);
        $this->assertStringContainsString('(new StoreReportService())->summary(', $reportRequest);
        $this->assertStringContainsString('new StoreCatalogQueryService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreCatalogQueryService.php');
        $this->assertStringContainsString('new StoreTransactionQueryService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreTransactionQueryService.php');
        $this->assertStringContainsString('new StoreInventoryQueryService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreInventoryQueryService.php');
        $this->assertStringContainsString('new StoreDayOperationService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreDayOperationService.php');
        $this->assertStringContainsString('new StoreCashOperationService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreCashOperationService.php');
        $this->assertStringContainsString('new StoreConfigurationService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreConfigurationService.php');
        $this->assertStringContainsString('new StoreInventoryOperationService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreInventoryOperationService.php');
        $this->assertStringContainsString('new StoreReportRequestService()', $controller);
        $this->assertFileExists(APPPATH . 'Services/StoreReportRequestService.php');
        $this->assertStringContainsString("'paymentBreakdown' => \$paymentBreakdown", $dashboard);
        $this->assertStringContainsString("'cash_drawer' => [", $reports);
        $this->assertStringContainsString("'payment_account_breakdown' => \$paymentAccountBreakdown", $reports);
        $this->assertStringContainsString("'profit_basis' =>", $reports);
        $this->assertStringContainsString("(string) (\$filters['date_from'] ?? '')", $reportRequest);
        $this->assertStringNotContainsString("(string) \$filters['date_from'] ??", $reportRequest);
    }

    public function testAdminControllerDelegatesCohesiveManagementWorkflows(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');

        $this->assertLessThanOrEqual(800, count(file(APPPATH . 'Controllers/AdminController.php')));
        foreach ([
            'AdminDashboardService',
            'AdminProductService',
            'AdminDebtQueryService',
            'AdminAuditQueryService',
            'AdminUserService',
        ] as $service) {
            $this->assertStringContainsString($service, $controller);
            $this->assertFileExists(APPPATH . 'Services/' . $service . '.php');
        }
    }

    public function testUserControllerDelegatesDashboardFinancialSummary(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/UserController.php');

        $this->assertLessThanOrEqual(800, count(file(APPPATH . 'Controllers/UserController.php')));
        $this->assertStringContainsString('UserDashboardService', $controller);
        $this->assertStringContainsString('(new UserDashboardService())->debtStatus(', $controller);
        $this->assertFileExists(APPPATH . 'Services/UserDashboardService.php');
    }
}
