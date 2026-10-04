<?php
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class HrRequestFormatTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testMultipartAndUrlEncodedBodiesReachHrValidationWithoutJsonParseErrors(): void
    {
        $this->withRoutes([['POST','test/hr-format','HrController::createStaff']]);
        foreach(['multipart/form-data; boundary=qa-boundary','application/x-www-form-urlencoded'] as $type){
            $response=$this->withHeaders(['Content-Type'=>$type,config(\Config\Security::class)->headerName=>service('security')->getHash()])->withBody('not-json-form-body')
                ->post('test/hr-format',[]);
            $response->assertStatus(400);
            $body=json_decode((string)$response->getJSON(),true);
            $this->assertSame('error',$body['status']);
            $this->assertStringNotContainsString('JSON',(string)$body['message']);
        }
    }
}
