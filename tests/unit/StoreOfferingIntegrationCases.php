<?php

use App\Services\StoreOfferingService;
use App\Models\StoreCapabilityModel;

trait StoreOfferingIntegrationCases
{
    public function testNewTemplatePublishesToPosAtomicallyWithPreparedQuantity(): void
    {
        $service=$this->offeringSetup();
        \Config\Database::forge()->addColumn('products',['family_id'=>['type'=>'INT','null'=>true]]);
        require_once APPPATH.'Database/Migrations/2026-08-13-120000_AddProductFamilies.php';
        (new \App\Database\Migrations\AddProductFamilies())->up();
        require_once APPPATH.'Database/Migrations/2026-09-05-000002_ExpandFoodTemplates.php';
        (new \App\Database\Migrations\ExpandFoodTemplates())->up();
        $db=\Config\Database::connect(); $db->resetDataCache();
        $input=['name'=>'Kitchen Stew','category'=>'Meals','tracking'=>'shared','publish_today'=>true,'today_capacity'=>4,
            'variants'=>[['label'=>'Regular','price'=>'40','unit'=>'serving','portions'=>1],['label'=>'Large','price'=>'70','unit'=>'serving','portions'=>2]]];
        $saved=$service->mutate('template',$input,1,7,'STORE_SYSTEM');
        $this->assertSame($saved['product_ids'],$saved['published_product_ids']);
        $this->assertCount(2,$db->table('daily_food_offerings')->get()->getResultArray());
        $this->assertSame(4,(int)$db->table('food_daily_batches')->get()->getRow('capacity'));
        $catalog=(new \App\Services\StoreCatalogQueryService())->products(1)['products'];
        $this->assertCount(2,$catalog);
        foreach($catalog as $row) $this->assertTrue((bool)$row['available_for_sale']);
        $sale=(new \App\Services\TransactionService())->createTransaction(['store_id'=>1,'payment_method'=>'cash','cash_received'=>70,
            'items'=>[['product_id'=>$saved['product_ids'][1],'qty'=>1]]],7,'STORE_SYSTEM');
        $this->assertSame('success',$sale['status']);
        $this->assertSame(2,(int)$db->table('food_daily_batches')->get()->getRow('used'));
        $input['name']='Saved for tomorrow'; $input['publish_today']=false;
        $future=$service->mutate('template',$input,1,7,'STORE_SYSTEM');
        $this->assertArrayNotHasKey('published_product_ids',$future);
        $this->assertNull(StoreOfferingService::foodForSale(1,$future['product_ids'][0]));
        $input['name']='Invalid preparation'; $input['publish_today']=true; $input['today_capacity']=0;
        $families=$db->table('product_families')->countAllResults();
        try { $service->mutate('template',$input,1,7,'STORE_SYSTEM'); $this->fail('Invalid capacity was accepted'); }
        catch(\InvalidArgumentException $e) { $this->assertStringContainsString('Quantity',$e->getMessage()); }
        $this->assertSame($families,$db->table('product_families')->countAllResults());
        $db->table('store_day_sessions')->update(['status'=>'closed']);
        $input['name']='Closed day'; $input['today_capacity']=10;
        try { $service->mutate('template',$input,1,7,'STORE_SYSTEM'); $this->fail('Closed day publishing was accepted'); }
        catch(\InvalidArgumentException $e) { $this->assertStringContainsString('Open today',$e->getMessage()); }
        $this->assertSame($families,$db->table('product_families')->countAllResults());
    }

    public function testRichFoodVariantsSharedBatchAndSnapshots(): void
    {
        $service=$this->offeringSetup();
        \Config\Database::forge()->addColumn('products',['family_id'=>['type'=>'INT','null'=>true]]);
        require_once APPPATH.'Database/Migrations/2026-08-13-120000_AddProductFamilies.php';
        (new \App\Database\Migrations\AddProductFamilies())->up();
        require_once APPPATH.'Database/Migrations/2026-09-05-000002_ExpandFoodTemplates.php';
        (new \App\Database\Migrations\ExpandFoodTemplates())->up();
        $db=\Config\Database::connect(); $db->resetDataCache();
        $input=['name'=>'Stew','category'=>'Meals','description'=>'Vegetable stew','allergens'=>'Soy','dietary'=>'Vegetarian','image_url'=>'/uploads/product-images/stew.png','tracking'=>'shared','variants'=>[
            ['label'=>'Regular','price'=>'40','unit'=>'serving','portions'=>1],['label'=>'Large','price'=>'70','unit'=>'serving','portions'=>2]]];
        $saved=$service->mutate('template',$input,1,7,'STORE_SUPERVISOR');
        $this->assertCount(2,$saved['product_ids']);
        $service->mutate('batch',['family_id'=>$saved['family_id'],'capacity'=>3],1,7,'STORE_SYSTEM');
        foreach($saved['product_ids'] as $i=>$id) $service->mutate('food',['product_id'=>$id,'capacity'=>10,'enabled'=>true,'price'=>$i?70:40],1,7,'STORE_SYSTEM');
        $payload=['store_id'=>1,'payment_method'=>'cash','cash_received'=>200,'items'=>[['product_id'=>$saved['product_ids'][1],'qty'=>2]]];
        $checkout=new \App\Services\TransactionService();
        $this->assertSame('error',$checkout->createTransaction($payload,7,'STORE_SYSTEM')['status']);
        $this->assertSame(0,(int)$db->table('food_daily_batches')->get()->getRowArray()['used']);
        $mixed=$payload;
        $mixed['items']=[['product_id'=>$saved['product_ids'][0],'qty'=>2],['product_id'=>$saved['product_ids'][1],'qty'=>1]];
        $this->assertSame('error',$checkout->createTransaction($mixed,7,'STORE_SYSTEM')['status']);
        $this->assertSame(0,(int)$db->table('food_daily_batches')->get()->getRowArray()['used']);
        $this->assertSame(0,(int)$db->table('daily_food_offerings')->selectSum('sold')->get()->getRowArray()['sold']);
        $input['name']='Updated stew'; $input['family_id']=$saved['family_id']; $input['version']=1;
        foreach($input['variants'] as $i=>&$variant) $variant['id']=$saved['product_ids'][$i]; unset($variant);
        $service->mutate('template',$input,1,7,'STORE_SUPERVISOR');
        $payload['items'][0]['qty']=1;
        $sale=$checkout->createTransaction($payload,7,'STORE_SYSTEM');
        $this->assertSame('success',$sale['status'],json_encode($sale));
        $item=$db->table('transaction_items')->get()->getRowArray();
        $this->assertSame('Stew',$item['item_name_snapshot']);
        $this->assertSame('Large',$item['variant_snapshot']);
        $batch=$db->table('food_daily_batches')->get()->getRowArray();
        $this->assertSame(2,(int)$batch['used']);
        $service->mutate('food-close',['batch_id'=>$batch['id'],'leftover'=>1,'disposition'=>'Donated'],1,7,'STORE_SYSTEM');
        $this->assertNull(StoreOfferingService::foodForSale(1,$saved['product_ids'][0]));
        $this->assertSame('error',$checkout->createTransaction($payload,7,'STORE_SYSTEM')['status']);
        $this->expectException(\InvalidArgumentException::class);
        $service->mutate('template',$input,1,7,'STORE_SUPERVISOR');
    }

    private function offeringSetup(): StoreOfferingService
    {
        require_once APPPATH . 'Database/Migrations/2026-09-05-000001_AddRentalAndDailyFood.php';
        (new \App\Database\Migrations\AddRentalAndDailyFood())->up();
        $db = \Config\Database::connect();
        $db->query('CREATE TABLE ' . $db->prefixTable('store_cash_movements') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER, business_date TEXT, channel TEXT, movement_type TEXT, amount REAL, reason TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
        $db->resetDataCache();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        (new StoreCapabilityModel())->syncForStore(1, ['food_service', 'rental']);
        return new StoreOfferingService();
    }

    private function offeringBooking(StoreOfferingService $service): array
    {
        $cap = $service->mutate('asset', ['name' => 'Cap', 'quantity' => 1], 1, 7, 'STORE_SUPERVISOR')['asset_id'];
        $gown = $service->mutate('asset', ['name' => 'Gown medium', 'quantity' => 1], 1, 7, 'STORE_SUPERVISOR')['asset_id'];
        $package = $service->mutate('package', ['name' => 'Whole set', 'components' => [$cap => 1, $gown => 1], 'fee' => '100.00', 'deposit' => '50.00'], 1, 7, 'STORE_SUPERVISOR')['package_id'];
        $input = ['package_id' => $package, 'quantity' => 1, 'borrower' => 'Test Borrower', 'contact' => 'Test reference', 'starts_on' => ibems_business_date(), 'due_on' => ibems_business_date(), 'request_key' => 'test-booking-request-0001'];
        $id = $service->mutate('book', $input, 1, 7, 'STORE_SYSTEM')['booking_id'];
        return compact('cap', 'gown', 'package', 'input', 'id');
    }

    public function testDailyFoodTemplateSelectionLimitsAndHistory(): void
    {
        $service = $this->offeringSetup();
        $id = $service->mutate('template', ['name' => 'Vegetable meal', 'price' => '40'], 1, 7, 'STORE_SYSTEM')['product_id'];
        $payload = ['store_id' => 1, 'payment_method' => 'cash', 'cash_received' => 100, 'items' => [['product_id' => $id, 'qty' => 2]]];
        $checkout = new \App\Services\TransactionService();
        $this->assertSame('error', $checkout->createTransaction($payload, 7, 'STORE_SYSTEM')['status']);
        $service->mutate('food', ['product_id' => $id, 'enabled' => true, 'capacity' => 2, 'price' => '45'], 1, 7, 'STORE_SYSTEM');
        $sale = $checkout->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $sale['status'], json_encode($sale));
        $this->assertSame(90.0, $sale['total_amount']);
        $this->assertSame('error', $checkout->createTransaction($payload, 7, 'STORE_SYSTEM')['status']);
        $db = \Config\Database::connect();
        $this->assertSame(0, $db->table('inventory_movements')->countAllResults());
        $this->assertSame(2, (int) $db->table('daily_food_offerings')->get()->getRowArray()['sold']);
        $service->mutate('food', ['product_id' => $id, 'enabled' => false, 'capacity' => 2, 'price' => '50'], 1, 7, 'STORE_SUPERVISOR');
        $this->assertSame(45.0, (float) $db->table('transaction_items')->get()->getRowArray()['unit_price']);
        $db->table('daily_food_offerings')->update(['business_date' => '2000-01-01']);
        $this->assertNull(StoreOfferingService::foodForSale(1, $id));
    }

    public function testRentalSetsShareComponentsAndBookingRetriesDoNotDuplicate(): void
    {
        $service = $this->offeringSetup();
        $b = $this->offeringBooking($service);
        $this->assertSame($b['id'], $service->mutate('book', $b['input'], 1, 7, 'STORE_SYSTEM')['booking_id']);
        $capOnly = $service->mutate('package', ['name' => 'Cap only', 'components' => [$b['cap'] => 1], 'fee' => '20', 'deposit' => '0'], 1, 7, 'STORE_SUPERVISOR')['package_id'];
        $this->expectException(\InvalidArgumentException::class);
        $service->mutate('book', array_replace($b['input'], ['package_id' => $capOnly, 'request_key' => 'another-booking-request']), 1, 7, 'STORE_SYSTEM');
    }

    public function testRentalPaymentPartialReturnsAndDepositCashReconcile(): void
    {
        $service = $this->offeringSetup();
        $b = $this->offeringBooking($service);
        $db = \Config\Database::connect();
        $booking = $db->table('rental_bookings')->where('id', $b['id'])->get()->getRowArray();
        $payload = ['store_id' => 1, 'payment_method' => 'cash', 'cash_received' => 100, 'rental_booking_id' => $b['id'], 'items' => [['product_id' => (int) $booking['product_id'], 'qty' => 1]]];
        $checkout = new \App\Services\TransactionService();
        $sale = $checkout->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $sale['status'], json_encode($sale));
        $this->assertSame('error', $checkout->createTransaction($payload, 7, 'STORE_SYSTEM')['status']);
        $service->mutate('issue', ['booking_id' => $b['id'], 'version' => 1], 1, 7, 'STORE_SYSTEM');
        $this->assertSame('cash_in', $db->table('store_cash_movements')->get()->getRowArray()['movement_type']);
        $service->mutate('return', ['booking_id' => $b['id'], 'version' => 2, 'components' => [$b['cap'] => 1]], 1, 7, 'STORE_SYSTEM');
        $this->assertSame('issued', $db->table('rental_bookings')->get()->getRowArray()['status']);
        // Disabling new rentals must never prevent completing an outstanding return/refund.
        (new StoreCapabilityModel())->syncForStore(1, ['food_service']);
        $service->mutate('return', ['booking_id' => $b['id'], 'version' => 3, 'components' => [$b['gown'] => 1]], 1, 7, 'STORE_SYSTEM');
        $service->mutate('refund', ['booking_id' => $b['id'], 'version' => 4], 1, 7, 'STORE_SYSTEM');
        $movements = $db->table('store_cash_movements')->orderBy('id')->get()->getResultArray();
        $this->assertSame('cash_out', $movements[1]['movement_type']);
        $this->assertSame(50.0, (float) $movements[0]['amount']);
        $this->assertSame(50.0, (float) $movements[1]['amount']);
        $this->assertSame(1, $db->table('transactions')->countAllResults());
        $this->assertSame(0, $db->table('inventory_movements')->countAllResults());
        $this->expectException(\InvalidArgumentException::class);
        $service->mutate('refund', ['booking_id' => $b['id'], 'version' => 4], 1, 7, 'STORE_SYSTEM');
    }

    public function testRentalRejectsForeignComponents(): void
    {
        $service = $this->offeringSetup();
        $b = $this->offeringBooking($service);
        (new StoreCapabilityModel())->syncForStore(2, ['rental']);
        $this->expectException(\InvalidArgumentException::class);
        $service->mutate('package', ['name' => 'Wrong store', 'components' => [$b['cap'] => 1], 'fee' => '20'], 2, 7, 'STORE_SUPERVISOR');
    }

    public function testRentalAvailabilityUsesPeakOverlapAndOutstandingPhysicalItems(): void
    {
        $rows = [
            ['status' => 'reserved', 'starts_on' => '2026-09-05', 'due_on' => '2026-09-06', 'components_json' => '{"1":2}', 'returned_json' => '{}'],
            ['status' => 'reserved', 'starts_on' => '2026-09-07', 'due_on' => '2026-09-08', 'components_json' => '{"1":3}', 'returned_json' => '{}'],
        ];
        $this->assertSame(3, StoreOfferingService::peakReserved($rows, 1, '2026-09-05', '2026-09-08'));
        $rows[0]['status'] = 'issued';
        $this->assertSame(5, StoreOfferingService::peakReserved($rows, 1, '2026-09-07', '2026-09-08'));
        $rows[0]['returned_json'] = '{"1":1}';
        $this->assertSame(4, StoreOfferingService::peakReserved($rows, 1, '2026-09-07', '2026-09-08'));
    }
}
