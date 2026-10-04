<?php

use App\Models\StoreCapabilityModel;
use App\Services\StoreInventoryOperationService;
use App\Services\StoreOfferingService;
use App\Services\TransactionService;
use Config\Database;

/** Reuses the transaction integration fixtures, with named business-type datasets. */
trait StoreTypeScenarioCases
{
    public static function mixedStoreTypes(): array
    {
        return [
            'school supplies'=>[['retail'],[['stock_item','tracked','piece'],['stock_item','tracked','pack']],false],
            'cafeteria'=>[['retail','food_service'],[['prepared_item','tracked','serving'],['stock_item','tracked','bottle']],false],
            'milk products'=>[['retail','production'],[['manufactured_item','tracked','bottle'],['stock_item','tracked','pack']],false],
            'water refilling'=>[['retail','refill_service','container_deposits'],[['service','untracked','gallon'],['stock_item','tracked','bottle'],['deposit','untracked','container']],true],
            'business center retail'=>[['retail','rental'],[['stock_item','tracked','piece'],['stock_item','tracked','pack']],false],
        ];
    }

    private function storeTypeFixture(array $capabilities, array $types, bool $internal): array
    {
        $offer=$this->offeringSetup(); $db=Database::connect(); $now=ibems_now()->format('Y-m-d H:i:s');
        (new StoreCapabilityModel())->syncForStore(1,$capabilities);
        if ($internal) $this->seedDebtCustomer(501,'staff',1000,0,'1234',$now);
        $items=[];
        foreach ($types as $index=>[$type,$policy,$unit]) {
            $id=101+$index;
            $this->seedProduct($id,$policy==='tracked'?2:0,25,$now,$type,$policy,$unit);
            if ($type==='prepared_item') $offer->mutate('food',['product_id'=>$id,'enabled'=>true,'capacity'=>2,'price'=>'25'],1,7,'STORE_SYSTEM');
            $items[]=['product_id'=>$id,'qty'=>2];
        }
        $request=['store_id'=>1,'payment_method'=>'cash','cash_received'=>500,'items'=>$items];
        if ($internal) $request['customer_user_id']=501;
        return $request;
    }

    private function storeTypeFinancialState(): array
    {
        $db=Database::connect(); $state=[];
        foreach (['products','transactions','transaction_items','transaction_payments','inventory_movements','daily_food_offerings','rental_bookings','store_cash_movements','debt_cashbook_entries'] as $table) $state[$table]=$db->table($table)->orderBy('id')->get()->getResultArray();
        return $state;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mixedStoreTypes')]
    public function testStoreTypeMixedBasketUsesExactStockAndImmutableReceipt(array $capabilities,array $types,bool $internal): void
    {
        $request=$this->storeTypeFixture($capabilities,$types,$internal); $db=Database::connect();
        $result=(new TransactionService())->createTransaction($request,7,'STORE_SYSTEM');
        $this->assertSame('success',$result['status'],json_encode($result));
        $this->assertSame(count($types)*50.0,(float)$result['total_amount']);
        $this->assertSame(1,$db->table('transactions')->countAllResults());
        $this->assertSame(count($types),$db->table('transaction_items')->countAllResults());
        $this->assertSame(count(array_filter($types,static fn($type)=>$type[1]==='tracked')),$db->table('inventory_movements')->countAllResults());
        foreach ($types as $index=>[$type,$policy,$unit]) {
            $id=101+$index;
            $this->assertSame(0,(int)$db->table('products')->where('id',$id)->get()->getRow('stock_qty'));
            $db->table('products')->where('id',$id)->update(['name'=>'Renamed after sale','price'=>99]);
            $item=$db->table('transaction_items')->where('product_id',$id)->get()->getRowArray();
            $this->assertSame('Coffee',$item['item_name_snapshot']);
            $this->assertSame($type,$item['item_type_snapshot']);
            $this->assertSame($unit,$item['unit_code_snapshot']);
            $this->assertSame(25.0,(float)$item['unit_price']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mixedStoreTypes')]
    public function testStoreTypeMixedBasketShortageRollsBackEveryLine(array $capabilities,array $types,bool $internal): void
    {
        $request=$this->storeTypeFixture($capabilities,$types,$internal);
        foreach ($types as $index=>$type) if ($type[1]==='tracked') $tracked=101+$index;
        Database::connect()->table('products')->where('id',$tracked)->update(['stock_qty'=>1]);
        $before=$this->storeTypeFinancialState();
        $result=(new TransactionService())->createTransaction($request,7,'STORE_SYSTEM');
        $this->assertSame('error',$result['status']);
        $this->assertSame($before,$this->storeTypeFinancialState(),'No partial sale, payment, inventory or menu consumption may survive.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mixedStoreTypes')]
    public function testStoreTypeDisabledCapabilityRejectsStaleCart(array $capabilities,array $types,bool $internal): void
    {
        $request=$this->storeTypeFixture($capabilities,$types,$internal);
        (new StoreCapabilityModel())->syncForStore(1,['rental']);
        $before=$this->storeTypeFinancialState();
        $this->assertSame('error',(new TransactionService())->createTransaction($request,7,'STORE_SYSTEM')['status']);
        $this->assertSame($before,$this->storeTypeFinancialState());
    }

    public static function untrackedInventoryTypes(): array
    {
        return ['refill service'=>['service','gallon'],'container deposit'=>['deposit','container'],'rental fee'=>['rental','service'],'made-to-order meal'=>['prepared_item','serving'],'untracked manufactured product'=>['manufactured_item','bottle']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrackedInventoryTypes')]
    public function testUntrackedStoreOfferingsCannotBeRestockedOrPhysicallyAdjusted(string $type,string $unit): void
    {
        $this->offeringSetup(); $db=Database::connect(); $now=ibems_now()->format('Y-m-d H:i:s');
        $this->seedProduct(101,0,25,$now,$type,'untracked',$unit);
        Database::forge()->addColumn('inventory_movements',['unit_cost'=>['type'=>'REAL','null'=>true],'total_cost'=>['type'=>'REAL','null'=>true],'expected_profit'=>['type'=>'REAL','null'=>true]]);
        $db->resetDataCache(); $before=$this->storeTypeFinancialState(); $inventory=new StoreInventoryOperationService();
        $restock=$inventory->restock(['store_id'=>1,'product_id'=>101,'qty'=>5,'unit_cost'=>0,'sell_price'=>25],7,'STORE_SYSTEM');
        $this->assertSame('error',$restock['payload']['status'],'Untracked offerings must not acquire physical inventory.');
        $this->assertSame($before,$this->storeTypeFinancialState());
        $adjust=$inventory->adjustStock(['store_id'=>1,'product_id'=>101,'actual_qty'=>5,'expected_qty'=>0],7,'STORE_SYSTEM');
        $this->assertSame('error',$adjust['payload']['status']);
        $this->assertSame($before,$this->storeTypeFinancialState());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rentalStoreTypes')]
    public function testRentalStoreRejectsMixedFeeBasketWithoutConsumingRetailStock(string $label): void
    {
        $offer=$this->offeringSetup(); $booking=$this->offeringBooking($offer); $db=Database::connect();
        (new StoreCapabilityModel())->syncForStore(1,['retail','rental']);
        $this->seedProduct(900,2,25,ibems_now()->format('Y-m-d H:i:s'));
        $row=$db->table('rental_bookings')->where('id',$booking['id'])->get()->getRowArray();
        $before=$this->storeTypeFinancialState();
        $request=['store_id'=>1,'payment_method'=>'cash','cash_received'=>125,'rental_booking_id'=>$booking['id'],'items'=>[['product_id'=>$row['product_id'],'qty'=>1],['product_id'=>900,'qty'=>1]]];
        $this->assertSame('error',(new TransactionService())->createTransaction($request,7,'STORE_SYSTEM')['status'],$label);
        $this->assertSame($before,$this->storeTypeFinancialState());
        $request['items']=[$request['items'][0]];
        $this->assertSame('success',(new TransactionService())->createTransaction($request,7,'STORE_SYSTEM')['status']);
        $this->assertSame(2,(int)$db->table('products')->where('id',900)->get()->getRow('stock_qty'));
    }

    public static function rentalStoreTypes(): array { return ['business center'=>['Business center'],'Toga'=>['Toga rental']]; }
}
