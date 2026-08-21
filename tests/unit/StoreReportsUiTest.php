<?php
use CodeIgniter\Test\CIUnitTestCase;
final class StoreReportsUiTest extends CIUnitTestCase {
 public function testReportsIncludeReceiptDrilldownAndSafeCustomRange():void{
  $v=(string)file_get_contents(APPPATH.'Views/store/reports.php');$j=(string)file_get_contents(FCPATH.'assets/js/store-reports.js');
  $this->assertStringContainsString('Recent Transactions &amp; Receipts',$v);
  $this->assertStringContainsString('receipt-standard.js',$v);
  $this->assertStringContainsString('data-report-receipt',$j);
  $this->assertStringContainsString('From date cannot be later than To date.',$j);
  $this->assertStringContainsString('Split payments appear under every method used',$v);
  $this->assertStringContainsString('id="reports-custom-clear" class="secondary-btn btn-sm is-hidden"',$v);
  $this->assertStringContainsString('Reset dates',$v);
  $this->assertStringContainsString('rUpdateCustomResetVisibility',$j);
 }
}
