<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class StaffRecordsUiTest extends CIUnitTestCase
{
    public function testEmployeeRecordsUsesAccessibleExplicitActions(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/staff-records.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-staff-records.js');

        $this->assertStringContainsString('aria-label="Scan employee QR or ID"', $view);
        $this->assertStringContainsString('id="debt-clear-btn"', $view);
        $this->assertStringContainsString('class="search-clear-btn is-hidden"', $view);
        $this->assertStringContainsString('id="txn-clear-btn"', $view);
        $this->assertStringContainsString('id="txn-clear-btn" class="secondary-btn is-hidden"', $view);
        $this->assertStringContainsString('Reset filters', $view);
        $this->assertStringNotContainsString('id="txn-search-btn"', $view);
        $this->assertStringContainsString('Overall Debt Now', $view);
        $this->assertStringContainsString('staff-view-transactions', $script);
        $this->assertStringContainsString('bi bi-receipt', $script);
        $this->assertStringContainsString('addEventListener("search"', $script);
        $this->assertStringContainsString('class="search-scan-btn"', $view);
        $this->assertStringContainsString('srIsActive', $script);
        $this->assertStringContainsString('id="txn-store-select"', $view);
        $this->assertStringContainsString('document.getElementById("staff-employee-title").textContent = "Transaction history"', $script);
        $this->assertStringContainsString('data-current-debt', $script);
        $this->assertStringContainsString('staff-summary-finance', $script);
        $this->assertStringContainsString('srStores.some', $script);
    }

    public function testEmployeeTransactionFiltersHaveValidationAndDataStates(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-staff-records.js');

        $this->assertStringContainsString('srTransactionDataState', $script);
        $this->assertStringContainsString('From date cannot be later than To date.', $script);
        $this->assertStringContainsString('Loading employee transactions...', $script);
        $this->assertStringContainsString('event.key !== "Escape"', $script);
        $this->assertStringContainsString('Unable to load receipt.', $script);
        $this->assertStringContainsString('window.IbemsReceipt.renderReceipt', $script);
        $this->assertStringContainsString('window.IbemsReceipt.printReceipt', $script);
        $this->assertStringContainsString('receipt-standard.js', (string) file_get_contents(APPPATH . 'Views/store/staff-records.php'));
        $this->assertStringContainsString('requestId !== srDebtRequestSequence', $script);
        $this->assertStringContainsString('requestId !== srTransactionRequestSequence', $script);
        $this->assertStringContainsString('["txn-date-from", "txn-date-to", "txn-debt-only"]', $script);
        $this->assertStringContainsString('srUpdateTransactionResetVisibility', $script);
    }

    public function testEmployeeListUsesOneRecordPanelBoundary(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/staff-records.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-staff-records.css');

        $this->assertStringContainsString('staff-record-list-wrap record-panel', $view);
        $this->assertStringContainsString('staff-record-list record-list', $view);
        $this->assertStringContainsString('border-bottom: 1px solid #e2e8f0;', $styles);
    }

    public function testEmployeeTransactionsClampPaginationBeforeDividing(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/StoreController.php');
        $methodStart = strpos($controller, 'public function staffTransactions()');
        $this->assertNotFalse($methodStart);
        $method = substr($controller, (int) $methodStart);

        $pageSizePosition = strpos($method, '$pageSize = max(10, min(100');
        $divisionPosition = strpos($method, 'ceil($total / $pageSize)');
        $this->assertNotFalse($pageSizePosition);
        $this->assertNotFalse($divisionPosition);
        $this->assertLessThan($divisionPosition, $pageSizePosition);
        $this->assertStringContainsString("'amount' => 't.amount'", $method);
    }
}
