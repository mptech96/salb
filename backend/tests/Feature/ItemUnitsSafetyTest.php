<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Services\Accounting\AccountingContext;
use App\Http\Controllers\Api\ItemController;

class ItemUnitsSafetyTest extends \Tests\Feature\PhaseC\AccountMappingSelectionTest
{
    private function unitRequest(array $data):Request{$r=Request::create('/api/items','POST',$data);$r->attributes->set('tenant_company_id',1);app()->instance('request',$r);return $r;}
    public function test_item_kg_default_is_one_not_a_thousand_and_round_trips():void
    {
        $c=app(ItemController::class);$ctx=app(AccountingContext::class);$payload=['item_name'=>'Synthetic KG','item_type'=>'STOCK','commercial_unit_code'=>'KG'];
        $created=$c->store($this->unitRequest($payload),$ctx);self::assertSame(201,$created->getStatusCode());$id=$created->getData(true)['id'];
        $data=$c->show($this->unitRequest([]),$id,$ctx)->getData(true)['data'];self::assertEquals(1,$data['commercial_to_base_factor']);self::assertSame('KG',$data['base_unit_code']);self::assertSame('كجم',$data['unit_name']);
        $payload['default_buy_price']=5;self::assertSame(200,$c->update($this->unitRequest($payload),$id,$ctx)->getStatusCode());self::assertEquals(1,DB::table('items')->value('commercial_to_base_factor'));
    }
    public function test_unsupported_physical_units_and_invalid_factors_leave_no_item():void
    {
        $c=app(ItemController::class);$ctx=app(AccountingContext::class);
        foreach([['base_unit_code'=>'PCS'],['base_unit_code'=>'M'],['base_unit_code'=>'L'],['commercial_unit_code'=>'CARTON','commercial_to_base_factor'=>24],['commercial_unit_code'=>'KG','commercial_to_base_factor'=>1000]] as $units){try{$c->store($this->unitRequest(['item_name'=>'Invalid','item_type'=>'STOCK',...$units]),$ctx);self::fail('Definition accepted');}catch(ValidationException $e){self::assertNotEmpty($e->errors());}self::assertSame(0,DB::table('items')->count());}
    }
    public function test_import_rejects_unsupported_item_units_before_persistence():void
    {
        $service=app(\App\Services\MigrationCenterService::class);$method=new \ReflectionMethod($service,'upsertItem');
        foreach([['base_unit_code'=>'M'],['commercial_unit_code'=>'كجم','commercial_to_base_factor'=>1000]] as $units){try{$method->invoke($service,1,['item_code'=>'BAD','item_name'=>'Invalid','item_type'=>'STOCK',...$units],1,'isolated',false);self::fail('Import accepted invalid units');}catch(ValidationException $e){self::assertNotEmpty($e->errors());}self::assertSame(0,DB::table('items')->count());}
    }
    public function test_invoice_import_rejects_units_instead_of_assuming_kg():void
    {
        $service=app(\App\Services\MigrationCenterService::class);$method=new \ReflectionMethod($service,'unit');
        self::assertSame('KG',$method->invoke($service,'كجم','KG'));self::assertSame('TON',$method->invoke($service,'طن','KG'));self::assertSame('UNIT',$method->invoke($service,'خدمة','UNIT'));
        foreach(['M','L','PCS','UNIT','CARTON'] as $unit){try{$method->invoke($service,$unit,'KG');self::fail('Unit assumed to be KG');}catch(ValidationException $e){self::assertNotEmpty($e->errors());}}
    }
}
