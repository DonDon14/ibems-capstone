<?php
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class DepartmentMoneyInputTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    public function testMalformedMoneyIsRejectedBeforeAnyFinancialWrite(): void
    {
        $this->withRoutes([['POST','test/department-settlement','DepartmentDebtController::recordSettlement'],['POST','test/department-allocation','DepartmentDebtController::setAllocation'],['POST','test/department-allocations','DepartmentDebtController::setAllocations']]);
        foreach(['1garbage','1e3','1.001',true,[],null,'-1'] as $value)foreach(['settlement','allocation','allocations'] as $action){
            $payload=['department_id'=>1,'department_ids'=>[1],'period_id'=>1,'period_month'=>'2026-10','amount'=>$value,'allocation_amount'=>$value,'reference_no'=>'Synthetic','remarks'=>'Synthetic','reason'=>'Synthetic'];
            $response=$this->withHeaders(['Content-Type'=>'application/json',config(\Config\Security::class)->headerName=>service('security')->getHash()])->withBody(json_encode($payload))->post('test/department-'.$action);
            $response->assertStatus(400);
            $body=json_decode((string)$response->getJSON(),true);
            $this->assertSame('error',$body['status']);
            $this->assertStringContainsString('two decimal places',$body['message']);
        }
    }
}
