<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;

final class OperationalSurfaceTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
    }
    protected function tearDown():void
    {
        DB::disconnect();parent::tearDown();gc_collect_cycles();
    }
    public function test_legacy_lot_writers_cannot_reinterpret_base_items_as_kilograms():void
    {
        $lots=app(\App\Services\InventoryLotService::class);
        foreach(['receive','issue'] as $operation){
            try{
                if($operation==='receive')$lots->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>1,'base_cost'=>10,'received_at'=>'2026-10-24']);
                else $lots->consumeFifo(1,1,1,1,'QA_LEGACY',1);
                self::fail('BASE item interpreted as kilograms');
            }catch(\RuntimeException $error){self::assertSame('UOM_LEGACY_WEIGHT_ADAPTER_REQUIRED',$error->getMessage());}
        }
        self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('inventory_lot_movements')->count());
        self::assertSame(1,(int)DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->value('next_number'));
        self::assertSame(0,DB::transactionLevel());
    }
    public function test_remaining_legacy_scalar_paths_and_capitalization_cannot_corrupt_base_evidence():void
    {
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');
        $tables=['inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines'];
        $snapshot=fn()=>serialize(array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->all(),$tables));$before=$snapshot();
        $legacy=app(\App\Services\InventoryService::class);$data=['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty'=>1,'unit_cost'=>10,'source_type'=>'QA_LEGACY','source_id'=>1];
        foreach([
            fn()=>$legacy->stockIn($data),fn()=>$legacy->stockOut($data),fn()=>$legacy->availableQty(1,1),
            fn()=>$legacy->reverseSource(1,'PURCHASE',$purchase),
            fn()=>app(\App\Services\Accounting\InventoryCostService::class)->averageInboundCost(1,1,1),
        ] as $call){try{$call();self::fail('Scalar weight path accepted BASE evidence');}catch(\RuntimeException $error){self::assertSame('UOM_LEGACY_WEIGHT_ADAPTER_REQUIRED',$error->getMessage());}}
        $lot=DB::table('inventory_lots')->where('item_id',1)->value('id');
        try{app(\App\Services\InventoryLotService::class)->addCapitalizedCost(1,$lot,1);self::fail('BASE value silently capitalized');}
        catch(\RuntimeException $error){self::assertSame('UOM_CAPITALIZATION_ADAPTER_REQUIRED',$error->getMessage());}
        self::assertSame($before,$snapshot());
        // Unenrolled historical weight items retain the existing scalar contract.
        $legacy->stockIn(array_replace($data,['item_id'=>2]));
        self::assertSame(1.0,$legacy->availableQty(1,2));
        self::assertSame(10.0,app(\App\Services\Accounting\InventoryCostService::class)->averageInboundCost(1,1,2));
        $legacy->reverseSource(1,'QA_LEGACY',1);self::assertSame($before,$snapshot());
    }
    public function test_precision_boundaries_reject_invalid_documents_and_returns_without_partial_drafts():void
    {
        $service=app(EnterpriseInvoiceService::class);$this->invoice('PURCHASE','CARTON','1','120');
        $sale=$this->invoice('SALE','PCS','1','15');$source=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');
        $snapshot=fn()=>serialize(array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->all(),['purchase_invoices','purchase_invoice_lines','sales_invoices','sales_invoice_lines','commercial_returns','commercial_return_lines','inventory_lots','stock_movements','journal_entries','journal_entry_lines','sulb_document_sequences']));
        $before=$snapshot();
        foreach(['0','-1','1e-6','0.0000001','0.5','1000000000000'] as $quantity){
            try{$service->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>'PCS','quantity'=>$quantity,'unit_price'=>'15','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);self::fail('Invalid precision/indivisible quantity accepted: '.$quantity);}
            catch(\RuntimeException|\InvalidArgumentException $error){self::assertStringContainsString('UOM_',$error->getMessage());}
            self::assertSame($before,$snapshot());
        }
        foreach(['0','-1','1e-6','0.0000001','0.5','2'] as $quantity){
            try{app(\App\Services\CommercialReturnService::class)->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$source,'quantity'=>$quantity]]]);self::fail('Invalid return quantity accepted: '.$quantity);}
            catch(\RuntimeException|\InvalidArgumentException $error){self::assertStringContainsString('UOM_',$error->getMessage());}
            self::assertSame($before,$snapshot());
        }
        self::assertSame(0,DB::transactionLevel());
    }
    public function test_unadapted_processing_and_commercial_documents_fail_before_saving_or_numbering():void
    {
        $before=serialize(DB::table('sulb_document_sequences')->orderBy('id')->get()->all());
        foreach([[1,2,'UOM_PROCESSING_WEIGHT_POLICY_REQUIRED'],[2,1,'UOM_LEGACY_WEIGHT_ADAPTER_REQUIRED']] as [$from,$to,$reason]){
            try{app(\App\Services\InventoryOperationService::class)->create(['company_id'=>1,'from_branch_id'=>1,'operation_type'=>'CONVERSION','operation_date'=>'2026-10-24','created_by'=>1,'from_lines'=>[['item_id'=>$from,'qty_kg'=>1]],'to_lines'=>[['item_id'=>$to,'qty_kg'=>1]]]);self::fail('BASE interpreted as processing weight');}
            catch(\RuntimeException $error){self::assertSame($reason,$error->getMessage());}
        }
        $documents=app(\App\Services\CommercialDocumentService::class);
        foreach(['QUOTATION','PURCHASE_ORDER'] as $type){
            try{$documents->save($type,['customer_id'=>1,'supplier_id'=>1,'document_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>'1','unit_code'=>'PCS','unit_price'=>'15','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);self::fail('Unadapted commercial BASE document accepted');}
            catch(\RuntimeException $error){self::assertSame('UOM_COMMERCIAL_SCHEMA_REQUIRED',$error->getMessage());}
        }
        foreach(['inventory_operations','sales_quotations','purchase_orders'] as $table)self::assertSame(0,DB::table($table)->count());
        self::assertSame($before,serialize(DB::table('sulb_document_sequences')->orderBy('id')->get()->all()));
        $legacy=$documents->save('QUOTATION',['customer_id'=>1,'document_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>2,'quantity'=>1,'unit_code'=>'TON','unit_price'=>15,'price_unit'=>'TON','vat_percent'=>0]]],1,1,1);
        self::assertGreaterThan(0,$legacy);self::assertSame('1000.000',DB::table('sales_quotation_lines')->where('sales_quotation_id',$legacy)->value('qty_kg'));
        self::assertSame(0,DB::transactionLevel());
    }
    public function test_http_return_reads_and_mutations_deny_other_branch_and_company_without_side_effects():void
    {
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'Other branch']);
        $this->invoice('PURCHASE','CARTON','1','120');$sale=$this->invoice('SALE','PCS','5','15');
        $body=['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id'),'quantity'=>'1']]];
        $id=app(\App\Services\CommercialReturnService::class)->saveDraft(1,1,1,$body);
        DB::table('plans')->insert(['id'=>1,'plan_code'=>'SURFACE-QA','plan_name'=>'QA']);
        foreach([1,2] as $company)DB::table('subscriptions')->insert(['company_id'=>$company,'plan_id'=>1,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach(['sales','purchases','inventory','accounting'] as $feature)DB::table('plan_features')->insert(['plan_id'=>1,'feature_code'=>$feature,'is_enabled'=>1]);
        DB::table('roles')->insert(['id'=>3,'role_code'=>'BRANCH_MANAGER','role_name'=>'QA branch']);
        foreach(['returns.draft','returns.post','returns.void'] as $code){
            $permission=DB::table('permissions')->insertGetId(['permission_code'=>$code,'permission_name'=>$code,'permission_scope'=>'COMPANY']);
            DB::table('role_permissions')->insert(['role_id'=>3,'permission_id'=>$permission,'company_id'=>1]);
        }
        DB::table('users')->insert(['id'=>3,'company_id'=>1,'branch_id'=>3,'name'=>'Other branch actor','username'=>'qa-branch','password'=>'synthetic']);
        DB::table('user_roles')->insert(['user_id'=>3,'role_id'=>3,'company_id'=>1]);
        $snapshot=function(){return serialize(array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->all(),['commercial_returns','commercial_return_lines','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','sulb_document_sequences']));};
        $before=$snapshot();$this->actingAs(\App\Models\User::findOrFail(3),'sanctum');
        $this->getJson('/api/commercial-returns/source/'.$sale.'?type=SALES_RETURN&branch_id=1')->assertUnprocessable()->assertJsonMissingPath('data');
        $this->getJson('/api/commercial-returns/'.$id)->assertNotFound();
        $this->putJson('/api/commercial-returns/'.$id,$body+['branch_id'=>1])->assertNotFound();
        $this->postJson('/api/commercial-returns/'.$id.'/post')->assertNotFound();
        $this->postJson('/api/commercial-returns/'.$id.'/void',['reason'=>'Cross branch denial'])->assertNotFound();
        self::assertSame($before,$snapshot());
        DB::table('user_roles')->insert(['user_id'=>2,'role_id'=>1,'company_id'=>2]);$this->actingAs(\App\Models\User::findOrFail(2),'sanctum');
        $this->getJson('/api/commercial-returns/source/'.$sale.'?type=SALES_RETURN')->assertUnprocessable()->assertJsonMissingPath('data');
        $this->getJson('/api/commercial-returns/'.$id)->assertNotFound();
        $this->postJson('/api/commercial-returns/'.$id.'/post')->assertNotFound();
        self::assertSame($before,$snapshot());self::assertSame(0,DB::transactionLevel());
    }
    public function test_dashboard_mixed_units_keep_tiny_quantities_and_legacy_kilogram_contract():void
    {
        $this->invoice('PURCHASE','CARTON','1','120');
        $units=app(\App\Services\Uom\ItemUnitService::class);$units->registerNew(1,2,3);$units->reviseMapping(1,2,3,'1');
        $service=app(EnterpriseInvoiceService::class);
        $id=$service->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>2,'unit_code'=>'M','quantity'=>'0.000001','unit_price'=>'1000000','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
        $service->post('PURCHASE',1,$id,1,1);
        DB::table('items')->insert(['id'=>4,'company_id'=>1,'item_code'=>'LEGACY-TON','item_name'=>'Legacy ton display','base_unit_code'=>'TON','inventory_account_id'=>11]);
        $legacy=\Tests\Support\UomOperationalLockFixture::purchase(1,4);$service->post('PURCHASE',1,$legacy,1,1);
        $r=\Illuminate\Http\Request::create('/api/dashboard');$r->attributes->set('tenant_company_id',1);$r->attributes->set('tenant_branch_id',1);
        $rows=app(\App\Http\Controllers\Api\DashboardController::class)->index($r)->getData(true)['cards']['stock_quantities_by_unit'];
        self::assertSame([['base_unit_code'=>'KG','base_quantity'=>'10.000000'],['base_unit_code'=>'M','base_quantity'=>'0.000001'],['base_unit_code'=>'PCS','base_quantity'=>'12.000000']],$rows);
        $r->attributes->set('tenant_branch_id',3);
        self::assertSame([],app(\App\Http\Controllers\Api\DashboardController::class)->index($r)->getData(true)['cards']['stock_quantities_by_unit']);
    }
    private function invoice(string $mode,string $unit,string $quantity,string $price,int $branch=1):int
    {
        $id=app(EnterpriseInvoiceService::class)->saveDraft($mode,[($mode==='SALE'?'customer_id':'supplier_id')=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>$unit,'quantity'=>$quantity,'unit_price'=>$price,'price_unit'=>'UNIT','vat_percent'=>0]]],1,$branch,1);
        app(EnterpriseInvoiceService::class)->post($mode,1,$id,1,$branch);return $id;
    }
    public function test_dashboard_units_and_return_readers_follow_captured_units_and_company():void
    {
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'QA other branch']);
        $this->invoice('PURCHASE','CARTON','1','120');$sale=$this->invoice('SALE','PCS','5','15');
        $request=\Illuminate\Http\Request::create('/api/dashboard');$request->attributes->set('tenant_company_id',1);$request->attributes->set('tenant_branch_id',1);
        $data=app(\App\Http\Controllers\Api\DashboardController::class)->index($request)->getData(true);
        self::assertSame([['base_unit_code'=>'PCS','base_quantity'=>'7.000000']],$data['cards']['stock_quantities_by_unit']);
        $returns=app(\App\Services\CommercialReturnService::class);$source=$returns->sourceLines(1,'SALES_RETURN',$sale)['lines']->sole();
        self::assertSame('5.000000',$source->returnable_qty);self::assertSame('0.000000',$source->returned_qty);
        $id=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$source->id,'quantity'=>'2']]]);
        $source=$returns->sourceLines(1,'SALES_RETURN',$sale)['lines']->sole();self::assertSame('3.000000',$source->returnable_qty);self::assertSame('2.000000',$source->returned_qty);
        $returns->post(1,$id,1,1);
        $request->attributes->set('tenant_branch_id',3);
        $request->attributes->set('effective_role_code','BRANCH_MANAGER');
        $request->query->set('type','SALES_RETURN');
        $denied=app(\App\Http\Controllers\Api\CommercialReturnController::class)->source($request,$sale,app(\App\Services\Accounting\AccountingContext::class),$returns);
        self::assertSame(422,$denied->getStatusCode());self::assertArrayNotHasKey('data',$denied->getData(true));
        $request->attributes->set('tenant_company_id',2);$request->attributes->set('tenant_branch_id',2);
        self::assertSame([],app(\App\Http\Controllers\Api\DashboardController::class)->index($request)->getData(true)['cards']['stock_quantities_by_unit']);
    }
    public function test_new_item_base_unit_is_atomic_and_legacy_or_existing_base_identity_cannot_change():void
    {
        $controller=app(\App\Http\Controllers\Api\ItemController::class);$context=app(\App\Services\Accounting\AccountingContext::class);
        $make=function(array $body){$r=\Illuminate\Http\Request::create('/api/items','POST',$body);$r->attributes->set('tenant_company_id',1);return $r;};
        $response=$controller->store($make(['company_id'=>2,'item_name'=>'New QA length','item_code'=>'NEW-M','item_type'=>'STOCK','base_uom_id'=>3]),$context);
        self::assertSame(201,$response->getStatusCode());$id=$response->getData(true)['id'];
        $row=DB::table('items')->where('id',$id)->first();self::assertSame(1,(int)$row->company_id);self::assertSame('M',$row->base_unit_code);self::assertSame('BASE_V1',$row->inventory_quantity_version);
        self::assertSame('1.000000',DB::table('item_uom_mappings')->where('item_id',$id)->value('factor_to_base'));
        $controller->update($make(['item_name'=>'Updated QA length','item_type'=>'STOCK']),$id,$context);
        self::assertSame('M',DB::table('items')->where('id',$id)->value('base_unit_code'));
        foreach([[$id,['base_unit_code'=>'KG']],[2,['base_uom_id'=>3]]] as [$target,$change]){
            $before=DB::table('items')->where('id',$target)->first();
            try{$controller->update($make(['item_name'=>'Rejected mutation','item_type'=>'STOCK',...$change]),$target,$context);self::fail('Historical/base identity changed');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(422,$e->getStatusCode());}
            self::assertEquals($before,DB::table('items')->where('id',$target)->first());
        }
        $count=DB::table('items')->count();config(['uom.operational_enabled'=>false]);
        try{$controller->store($make(['item_name'=>'Schema-disabled','item_type'=>'STOCK','base_uom_id'=>3]),$context);self::fail('Disabled schema used');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(409,$e->getStatusCode());}
        self::assertSame($count,DB::table('items')->count());
    }
    public function test_return_draft_id_cannot_be_reassigned_from_another_branch():void
    {
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'QA second branch']);
        $this->invoice('PURCHASE','CARTON','1','120');$sale=$this->invoice('SALE','PCS','5','15');
        $returns=app(\App\Services\CommercialReturnService::class);
        $data=['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id'),'quantity'=>'1']]];
        $return=$returns->saveDraft(1,1,1,$data);$before=serialize($returns->details(1,$return,1));
        $this->invoice('PURCHASE','CARTON','1','120',3);$otherSale=$this->invoice('SALE','PCS','1','15',3);
        $data['source_invoice_id']=$otherSale;$data['lines'][0]['source_invoice_line_id']=DB::table('sales_invoice_lines')->where('sales_invoice_id',$otherSale)->value('id');
        try{$returns->saveDraft(1,3,1,$data,$return);self::fail('Cross-branch draft rewritten');}
        catch(\RuntimeException $e){self::assertSame('المردود خارج نطاق فرع العملية.',$e->getMessage());}
        self::assertSame($before,serialize($returns->details(1,$return,1)));self::assertSame(0,DB::transactionLevel());
    }
}
