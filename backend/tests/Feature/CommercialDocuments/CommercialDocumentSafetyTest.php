<?php

namespace Tests\Feature\CommercialDocuments;

use App\Services\CommercialDocumentService;
use App\Services\DocumentNumberService;
use App\Services\EnterpriseInvoiceService;
use App\Services\FinancialAccountService;
use App\Services\PartyBranchScopeService;
use App\Services\TaxEngineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CommercialDocumentSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['purchase_order_lines','purchase_orders','sales_quotation_lines','sales_quotations','supplier_branches','customer_branches','suppliers','customers','items','tax_codes','company_settings','journal_entries','journal_entry_lines','stock_movements','inventory_lots','inventory_lot_movements','sales_line_lot_sources'] as $table) Schema::dropIfExists($table);
        Schema::create('company_settings',fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->boolean('tax_inclusive_prices')->default(false),$t->unsignedBigInteger('default_sales_tax_code_id')->nullable(),$t->unsignedBigInteger('default_purchase_tax_code_id')->nullable()]);
        Schema::create('tax_codes',fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->string('tax_code'),$t->string('tax_name'),$t->decimal('rate',9,4),$t->boolean('is_exempt')->default(false),$t->boolean('is_out_of_scope')->default(false),$t->boolean('is_active')->default(true),$t->date('valid_from')->nullable(),$t->date('valid_to')->nullable()]);
        foreach ([['customers','customer_name'],['suppliers','supplier_name']] as [$table,$name]) Schema::create($table,fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->string($name),$t->boolean('scope_all_branches')->default(true),$t->boolean('is_active')->default(true)]);
        foreach ([['customer_branches','customer_id'],['supplier_branches','supplier_id']] as [$table,$fk]) Schema::create($table,fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->unsignedBigInteger($fk),$t->unsignedBigInteger('branch_id'),$t->boolean('is_active')->default(true)]);
        Schema::create('items',fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->string('item_name'),$t->string('item_type')->default('STOCK'),$t->boolean('track_inventory')->default(true),$t->boolean('can_sell')->default(true),$t->boolean('can_purchase')->default(true),$t->boolean('is_active')->default(true)]);
        $this->documentTables('sales_quotations','sales_quotation_lines','customer_id','sales_quotation_id','valid_until');
        $this->documentTables('purchase_orders','purchase_order_lines','supplier_id','purchase_order_id','expected_delivery_date');
        foreach (['journal_entries','journal_entry_lines','stock_movements','inventory_lots','inventory_lot_movements','sales_line_lot_sources'] as $table) Schema::create($table,fn(Blueprint $t)=>$t->id());
        DB::table('company_settings')->insert(['company_id'=>10,'tax_inclusive_prices'=>0]);
        DB::table('tax_codes')->insert(['id'=>1,'company_id'=>10,'tax_code'=>'VAT5','tax_name'=>'VAT 5%','rate'=>5,'is_active'=>1]);
        DB::table('customers')->insert([['id'=>1,'company_id'=>10,'customer_name'=>'عميل اختبار','scope_all_branches'=>1,'is_active'=>1],['id'=>2,'company_id'=>20,'customer_name'=>'عميل أجنبي','scope_all_branches'=>1,'is_active'=>1]]);
        DB::table('suppliers')->insert([['id'=>1,'company_id'=>10,'supplier_name'=>'مورد اختبار','scope_all_branches'=>1,'is_active'=>1],['id'=>2,'company_id'=>20,'supplier_name'=>'مورد أجنبي','scope_all_branches'=>1,'is_active'=>1]]);
        DB::table('items')->insert([['id'=>1,'company_id'=>10,'item_name'=>'حديد','is_active'=>1,'can_sell'=>1,'can_purchase'=>1],['id'=>2,'company_id'=>20,'item_name'=>'أجنبي','is_active'=>1,'can_sell'=>1,'can_purchase'=>1]]);
    }

    public function test_quotation_and_purchase_order_are_commercial_only_and_vat_is_authoritative(): void
    {
        $service=$this->service();
        $quotation=$service->save('QUOTATION',$this->payload('customer_id'),10,1,7);
        $order=$service->save('PURCHASE_ORDER',$this->payload('supplier_id'),10,1,7);
        self::assertSame('DRAFT',DB::table('sales_quotations')->where('id',$quotation)->value('status'));
        self::assertSame('DRAFT',DB::table('purchase_orders')->where('id',$order)->value('status'));
        self::assertEquals(5.0,(float)DB::table('sales_quotations')->where('id',$quotation)->value('tax_amount'));
        self::assertEquals(105.0,(float)DB::table('purchase_orders')->where('id',$order)->value('total_amount'));
        $this->assertNoPostingEffects();
    }

    public function test_branch_linked_parties_can_create_commercial_drafts(): void
    {
        DB::table('customers')->insert(['id'=>3,'company_id'=>10,'customer_name'=>'عميل الفرع','scope_all_branches'=>0,'is_active'=>1]);
        DB::table('suppliers')->insert(['id'=>3,'company_id'=>10,'supplier_name'=>'مورد الفرع','scope_all_branches'=>0,'is_active'=>1]);
        DB::table('customer_branches')->insert(['company_id'=>10,'customer_id'=>3,'branch_id'=>7,'is_active'=>1]);
        DB::table('supplier_branches')->insert(['company_id'=>10,'supplier_id'=>3,'branch_id'=>7,'is_active'=>1]);
        $quotation=$this->payload('customer_id');$quotation['customer_id']=3;
        $order=$this->payload('supplier_id');$order['supplier_id']=3;
        self::assertGreaterThan(0,$this->service()->save('QUOTATION',$quotation,10,7,7));
        self::assertGreaterThan(0,$this->service()->save('PURCHASE_ORDER',$order,10,7,7));
        $this->assertNoPostingEffects();
    }

    public function test_foreign_party_item_and_invalid_values_are_rejected_without_writes(): void
    {
        foreach ([['customer_id',2,1],['customer_id',1,2]] as [$party,$partyId,$itemId]) {
            $payload=$this->payload($party);$payload[$party]=$partyId;$payload['items'][0]['item_id']=$itemId;
            try{$this->service()->save('QUOTATION',$payload,10,1,7);self::fail('Cross-company reference must fail.');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}
        }
        $payload=$this->payload('supplier_id');$payload['items'][0]['quantity']=0;
        try{$this->service()->save('PURCHASE_ORDER',$payload,10,1,7);self::fail('Non-positive quantity must fail.');}catch(\RuntimeException $e){self::assertStringContainsString('أكبر من صفر',$e->getMessage());}
        self::assertSame(0,DB::table('sales_quotations')->count());self::assertSame(0,DB::table('purchase_orders')->count());
    }

    public function test_lifecycle_conversion_is_atomic_draft_only_and_idempotent(): void
    {
        $invoice=Mockery::mock(EnterpriseInvoiceService::class);
        $invoice->shouldReceive('saveDraft')->once()->withArgs(fn($mode,$payload,$cid,$bid,$uid)=>$mode==='SALE'&&$cid===10&&$bid===1&&$uid===7&&count($payload['items'])===1)->andReturn(501);
        $invoice->shouldNotReceive('post');
        $service=$this->service($invoice);$id=$service->save('QUOTATION',$this->payload('customer_id'),10,1,7);
        $service->transition('QUOTATION',10,$id,1,'SENT',7);$service->transition('QUOTATION',10,$id,1,'ACCEPTED',7);
        $first=$service->convert('QUOTATION',10,$id,1,7);$second=$service->convert('QUOTATION',10,$id,1,7);
        self::assertSame(['invoice_id'=>501,'existing'=>false],$first);self::assertSame(['invoice_id'=>501,'existing'=>true],$second);
        self::assertSame('CONVERTED',DB::table('sales_quotations')->where('id',$id)->value('status'));
        try{$service->save('QUOTATION',$this->payload('customer_id'),10,1,7,$id);self::fail('Converted document must be immutable.');}catch(\RuntimeException $e){self::assertStringContainsString('لا يمكن تعديل',$e->getMessage());}
        $this->assertNoPostingEffects();
    }

    public function test_purchase_order_conversion_uses_purchase_draft_contract_once(): void
    {
        $invoice=Mockery::mock(EnterpriseInvoiceService::class);
        $invoice->shouldReceive('saveDraft')->once()->withArgs(fn($mode,$payload,$cid,$bid,$uid)=>$mode==='PURCHASE'&&isset($payload['supplier_id'])&&$cid===10&&$bid===1&&$uid===7)->andReturn(601);
        $invoice->shouldNotReceive('post');$service=$this->service($invoice);$id=$service->save('PURCHASE_ORDER',$this->payload('supplier_id'),10,1,7);
        $service->transition('PURCHASE_ORDER',10,$id,1,'APPROVED',7);$first=$service->convert('PURCHASE_ORDER',10,$id,1,7);$second=$service->convert('PURCHASE_ORDER',10,$id,1,7);
        self::assertFalse($first['existing']);self::assertTrue($second['existing']);self::assertSame(601,$second['invoice_id']);$this->assertNoPostingEffects();
    }

    public function test_routes_permissions_entitlements_and_tenant_guards_are_wired(): void
    {
        $routes=file_get_contents(base_path('routes/api.php'));$permissions=file_get_contents(app_path('Http/Middleware/EnsureRoutePermission.php'));$features=require config_path('sulb_features.php');$tenant=file_get_contents(app_path('Services/Tenant/TenantResourceScopeService.php'));
        foreach (['quotations','purchase-orders'] as $path) self::assertStringContainsString("Route::apiResource('{$path}'",$routes);
        foreach (['quotations.view','quotations.convert','purchase_orders.view','purchase_orders.convert'] as $code) self::assertStringContainsString($code,$permissions);
        self::assertSame('sales',$features['route_prefixes']['quotations']);self::assertSame('purchases',$features['route_prefixes']['purchase-orders']);
        self::assertStringContainsString("'quotations/{quotation}'",$tenant);self::assertStringContainsString("'purchase-orders/{purchase_order}'",$tenant);
        self::assertStringContainsString("'support.access'",$routes);self::assertStringContainsString("'tenant.scope'",$routes);self::assertStringContainsString("'feature.entitlement'",$routes);
    }

    private function service(?EnterpriseInvoiceService $invoice=null): CommercialDocumentService
    {
        $money=Mockery::mock(FinancialAccountService::class);$money->shouldReceive('baseCurrency')->zeroOrMoreTimes()->andReturn('SAR');$money->shouldReceive('rate')->zeroOrMoreTimes()->andReturn(1.0);
        $numbers=Mockery::mock(DocumentNumberService::class);$numbers->shouldReceive('next')->zeroOrMoreTimes()->andReturnUsing(fn($cid,$bid,$family,$type)=>($type==='QUOTATION'?'QT':'PO').'-TEST-'.random_int(1000,9999));$numbers->shouldReceive('assertDocumentUnique')->zeroOrMoreTimes();
        $invoice??=Mockery::mock(EnterpriseInvoiceService::class);$invoice->shouldNotReceive('post');
        return new CommercialDocumentService(new PartyBranchScopeService(),new TaxEngineService(),$money,$numbers,$invoice);
    }

    private function payload(string $party): array{return[$party=>1,'document_date'=>'2026-08-29','currency_code'=>'SAR','exchange_rate'=>1,'discount_amount'=>0,'items'=>[['item_id'=>1,'quantity'=>1,'qty_kg'=>1000,'unit_code'=>'TON','price_unit'=>'TON','unit_price'=>100,'discount_amount'=>0,'tax_code_id'=>1]]];}
    private function assertNoPostingEffects(): void{foreach(['journal_entries','journal_entry_lines','stock_movements','inventory_lots','inventory_lot_movements','sales_line_lot_sources']as$table)self::assertSame(0,DB::table($table)->count(),$table.' must remain unchanged.');}
    private function documentTables(string $header,string $lines,string $party,string $fk,string $date): void
    {
        Schema::create($header,function(Blueprint$t)use($party,$date){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger($party);$t->string('document_number');$t->date('document_date');$t->date($date)->nullable();$t->string('status');$t->string('currency_code');$t->decimal('exchange_rate',18,8);$t->decimal('subtotal',18,3);$t->decimal('discount_amount',18,3);$t->decimal('tax_amount',18,3);$t->decimal('total_amount',18,3);$t->text('tax_summary_json')->nullable();$t->text('notes')->nullable();$t->text('terms')->nullable();$t->unsignedBigInteger('converted_invoice_id')->nullable();$t->timestamp('converted_at')->nullable();$t->unsignedBigInteger('created_by')->nullable();$t->unsignedBigInteger('updated_by')->nullable();$t->timestamps();});
        Schema::create($lines,function(Blueprint$t)use($fk){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger($fk);$t->unsignedBigInteger('item_id');$t->text('description')->nullable();$t->decimal('quantity',18,6);$t->string('unit_code');$t->decimal('qty_kg',18,3);$t->string('price_unit');$t->decimal('unit_price',18,6);$t->decimal('unit_price_per_kg',18,6);$t->decimal('discount_amount',18,3);$t->unsignedBigInteger('tax_code_id')->nullable();$t->string('tax_code_snapshot')->nullable();$t->string('tax_name_snapshot')->nullable();$t->decimal('tax_rate_snapshot',9,4);$t->decimal('subtotal',18,3);$t->decimal('tax_amount',18,3);$t->decimal('total_amount',18,3);$t->timestamps();});
    }
}
