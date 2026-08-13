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
        $this->assertStringContainsString('Overall Debt Now', $view);
        $this->assertStringContainsString('staff-view-transactions', $script);
        $this->assertStringContainsString('bi bi-receipt', $script);
        $this->assertStringContainsString('addEventListener("search"', $script);
        $this->assertStringContainsString('class="search-scan-btn"', $view);
        $this->assertStringContainsString('srIsActive', $script);
        $this->assertStringContainsString('id="txn-store-select"', $view);
        $this->assertStringContainsString('Transactions at ${storeName}', $script);
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
    }

    public function testEmployeeListUsesOneRecordPanelBoundary(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/staff-records.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-staff-records.css');

        $this->assertStringContainsString('staff-record-list-wrap record-panel', $view);
        $this->assertStringContainsString('staff-record-list record-list', $view);
        $this->assertStringContainsString('border-bottom: 1px solid #e2e8f0;', $styles);
    }
}
