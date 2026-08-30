<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Http\Middleware\EnforceTenantScope;
use App\Services\Tenant\TenantResourceScopeService;
use App\Support\TenantScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Wave1SubscriptionTestCase;

class TenantIsolationTest extends Wave1SubscriptionTestCase
{
    private int $companyA;
    private int $companyB;
    private int $branchA;
    private int $branchA2;
    private int $branchB;
    private TenantResourceScopeService $scope;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['customer_branches','supplier_branches','customers','suppliers','drivers','items','shipments','weighbridge_cards','financial_years','journal_entries','inventory_lots','official_document_attachments'] as $table) Schema::dropIfExists($table);
        foreach (['customers','suppliers'] as $table) Schema::create($table,function(Blueprint $t):void{$t->id();$t->foreignId('company_id');$t->foreignId('branch_id')->nullable();$t->boolean('scope_all_branches')->default(false);$t->boolean('is_active')->default(true);});
        foreach (['customer_branches'=>'customer_id','supplier_branches'=>'supplier_id'] as $table=>$foreign) Schema::create($table,function(Blueprint $t)use($foreign):void{$t->id();$t->foreignId('company_id');$t->foreignId($foreign);$t->foreignId('branch_id');$t->boolean('is_active')->default(true);});
        foreach (['drivers','shipments','weighbridge_cards','journal_entries','inventory_lots'] as $table) Schema::create($table,function(Blueprint $t):void{$t->id();$t->foreignId('company_id');$t->foreignId('branch_id')->nullable();});
        Schema::create('items',fn(Blueprint $t)=>[$t->id(),$t->foreignId('company_id')]);
        Schema::create('financial_years',fn(Blueprint $t)=>[$t->id(),$t->foreignId('company_id')]);
        Schema::create('official_document_attachments',fn(Blueprint $t)=>[$t->id(),$t->foreignId('company_id'),$t->foreignId('document_id')]);
        $this->companyA=DB::table('companies')->insertGetId(['company_name'=>'TENANT_A_TEST','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $this->companyB=DB::table('companies')->insertGetId(['company_name'=>'TENANT_B_TEST','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $this->branchA=DB::table('branches')->insertGetId(['company_id'=>$this->companyA,'branch_name'=>'A_MAIN_TEST','is_active'=>1]);
        $this->branchA2=DB::table('branches')->insertGetId(['company_id'=>$this->companyA,'branch_name'=>'A_BRANCH_2_TEST','is_active'=>1]);
        $this->branchB=DB::table('branches')->insertGetId(['company_id'=>$this->companyB,'branch_name'=>'B_MAIN_TEST','is_active'=>1]);
        $this->scope=app(TenantResourceScopeService::class);
    }

    public function test_tenant_cannot_read_update_or_delete_foreign_customer(): void
    {
        $id=$this->row('customers',$this->companyB,$this->branchB);
        foreach(['GET','PUT','DELETE'] as $method)$this->assertDenied($this->routeRequest($method,'customers/{customer}',['customer'=>$id]));
    }

    public function test_tenant_cannot_read_foreign_supplier_shipment_weighbridge_or_journal(): void
    {
        foreach([
            [$this->row('suppliers',$this->companyB,$this->branchB),'suppliers/{supplier}','supplier'],
            [$this->row('shipments',$this->companyB,$this->branchB),'shipments/{shipment}','shipment'],
            [$this->row('weighbridge_cards',$this->companyB,$this->branchB),'weighbridge/cards/{id}','id'],
            [$this->row('journal_entries',$this->companyB,$this->branchB),'journal-entries/{id}','id'],
        ] as [$id,$uri,$parameter])$this->assertDenied($this->routeRequest('GET',$uri,[$parameter=>$id]));
    }

    public function test_nested_foreign_item_vehicle_driver_and_financial_year_are_tenant_scoped(): void
    {
        foreach([
            ['lines'=>[['item_id'=>$this->row('items',$this->companyB)]]],
            ['car_id'=>$this->row('cars',$this->companyB)],
            ['driver_id'=>$this->row('drivers',$this->companyB,$this->branchB)],
            ['financial_year_id'=>$this->row('financial_years',$this->companyB)],
        ] as $payload)$this->assertDenied($this->plainRequest('POST',$payload));
    }

    public function test_client_company_id_is_overwritten_and_cannot_widen_access(): void
    {
        $request=$this->plainRequest('POST',['company_id'=>$this->companyB]);
        $response=$this->middleware()->handle($request,fn(Request $next)=>response()->json(['company_id'=>$next->input('company_id')]));
        self::assertSame($this->companyA,$response->getData(true)['company_id']);
    }

    public function test_branch_user_cannot_read_or_create_against_another_branch(): void
    {
        $customer=$this->row('customers',$this->companyA,$this->branchA2);
        $this->assertDenied($this->routeRequest('GET','customers/{customer}',['customer'=>$customer]));
        $request=$this->plainRequest('POST',['branch_id'=>$this->branchA2]);
        $this->middleware()->handle($request,fn()=>response()->noContent());
        self::assertSame($this->branchA,$request->input('branch_id'));
    }

    public function test_branch_user_can_use_linked_or_all_branch_customer_but_not_other_or_foreign_customer(): void
    {
        $linked=$this->party('customers',$this->companyA,false);$this->linkParty('customer_branches','customer_id',$linked,$this->companyA,$this->branchA);
        $all=$this->party('customers',$this->companyA,true);
        $other=$this->party('customers',$this->companyA,false);$this->linkParty('customer_branches','customer_id',$other,$this->companyA,$this->branchA2);
        $foreign=$this->party('customers',$this->companyB,true);
        foreach([$linked,$all]as$id){$response=$this->middleware()->handle($this->plainRequest('POST',['customer_id'=>$id]),fn()=>response()->noContent());self::assertSame(204,$response->getStatusCode());}
        foreach([$other,$foreign]as$id)$this->assertDenied($this->plainRequest('POST',['customer_id'=>$id]));
    }

    public function test_branch_user_can_use_linked_or_all_branch_supplier_but_not_other_or_foreign_supplier(): void
    {
        $linked=$this->party('suppliers',$this->companyA,false);$this->linkParty('supplier_branches','supplier_id',$linked,$this->companyA,$this->branchA);
        $all=$this->party('suppliers',$this->companyA,true);
        $other=$this->party('suppliers',$this->companyA,false);$this->linkParty('supplier_branches','supplier_id',$other,$this->companyA,$this->branchA2);
        $foreign=$this->party('suppliers',$this->companyB,true);
        foreach([$linked,$all]as$id){$response=$this->middleware()->handle($this->plainRequest('POST',['supplier_id'=>$id]),fn()=>response()->noContent());self::assertSame(204,$response->getStatusCode());}
        foreach([$other,$foreign]as$id)$this->assertDenied($this->plainRequest('POST',['supplier_id'=>$id]));
    }

    public function test_branch_and_company_payloads_cannot_widen_party_scope(): void
    {
        $party=$this->party('customers',$this->companyA,true);
        $request=$this->plainRequest('POST',['company_id'=>$this->companyB,'branch_id'=>$this->branchA2,'customer_id'=>$party]);
        $response=$this->middleware()->handle($request,fn(Request$r)=>response()->json(['company_id'=>$r->input('company_id'),'branch_id'=>$r->input('branch_id')]));
        self::assertSame(['company_id'=>$this->companyA,'branch_id'=>$this->branchA],$response->getData(true));
    }

    public function test_company_wide_role_can_access_company_branches_but_not_foreign_branch(): void
    {
        $id=$this->row('customers',$this->companyA,$this->branchA2);
        $response=$this->middleware()->handle($this->routeRequest('GET','customers/{customer}',['customer'=>$id],null,'MANAGER'),fn()=>response()->noContent());
        self::assertSame(204,$response->getStatusCode());
        $this->assertDenied($this->plainRequest('POST',['branch_id'=>$this->branchB],null,'MANAGER'));
    }

    public function test_support_target_company_and_branch_are_server_bound(): void
    {
        $request=$this->plainRequest('POST',['company_id'=>$this->companyB,'branch_id'=>$this->branchA2],$this->branchA,'SUPPORT',true);
        $this->middleware()->handle($request,fn()=>response()->noContent());
        self::assertSame($this->companyA,$request->input('company_id'));
        self::assertSame($this->branchA,$request->input('branch_id'));
        self::assertFalse(TenantScope::isCompanyWide($request));
    }

    public function test_support_cannot_access_foreign_tenant_or_other_target_branch_by_id(): void
    {
        foreach([$this->row('shipments',$this->companyB,$this->branchB),$this->row('shipments',$this->companyA,$this->branchA2)] as $id)
            $this->assertDenied($this->routeRequest('GET','shipments/{shipment}',['shipment'=>$id],$this->branchA,'SUPPORT',true));
    }

    public function test_attachment_lookup_is_company_scoped_without_existence_leak(): void
    {
        $document=$this->row('official_documents',$this->companyB);
        $attachment=DB::table('official_document_attachments')->insertGetId(['company_id'=>$this->companyB,'document_id'=>$document]);
        try{$this->middleware()->handle($this->routeRequest('DELETE','official-documents/attachments/{attachmentId}',['attachmentId'=>$attachment]),fn()=>response()->noContent());self::fail('Foreign attachment was exposed.');}
        catch(HttpResponseException $e){self::assertSame(404,$e->getResponse()->getStatusCode());self::assertSame('RESOURCE_OUT_OF_SCOPE',$e->getResponse()->getData(true)['code']);}
    }

    public function test_scope_inventory_covers_high_risk_routes_and_relationships(): void
    {
        $source=$this->productionSource('app/Services/Tenant/TenantResourceScopeService.php');
        foreach(['inventory-operations/{id}','commercial-returns/{id}','opening-balances/{id}','imports/batch/{id}','official-documents/attachments/{attachmentId}','weighbridge_card_id','inventory_lot_id','cost_center_id'] as $expected)self::assertStringContainsString("'{$expected}'",$source);
        $documents=$this->productionSource('app/Http/Controllers/Api/OfficialDocumentController.php');
        self::assertStringContainsString("'local'",$documents);
        self::assertStringNotContainsString("asset('storage/' . \$row->file_path)",$documents);
    }

    public function test_concurrency_guardrails_remain_lock_based_and_idempotent(): void
    {
        self::assertStringContainsString('lockForUpdate()', $this->productionSource('app/Services/Entitlement/UsageLimitService.php'));
        $provisioning=$this->productionSource('app/Services/Provisioning/CompanyProvisioningService.php');
        self::assertStringContainsString('lockForUpdate()', $provisioning);self::assertStringContainsString('idempotent_replay',$provisioning);
        self::assertStringContainsString('lockForUpdate()', $this->productionSource('app/Services/WeighbridgeService.php'));
        self::assertStringContainsString('lockForUpdate()', $this->productionSource('app/Services/EnterpriseInvoiceService.php'));
    }

    private function middleware(): EnforceTenantScope{return new EnforceTenantScope($this->scope);}
    private function plainRequest(string $method,array $payload=[],?int $branchId=null,string $role='BRANCH_MANAGER',bool $support=false): Request
    {
        $r=Request::create('/api/test',$method,$payload);$r->attributes->set('tenant_company_id',$this->companyA);$r->attributes->set('tenant_branch_id',$branchId??($role==='MANAGER'?null:$this->branchA));$r->attributes->set('effective_role_code',$role);$r->attributes->set('is_support_mode',$support);return $r;
    }
    private function routeRequest(string $method,string $uri,array $parameters,?int $branchId=null,string $role='BRANCH_MANAGER',bool $support=false): Request
    {
        $r=$this->plainRequest($method,[],$branchId,$role,$support);$route=new Route($method,'api/'.$uri,fn()=>null);$r->setRouteResolver(fn()=>$route);$route->bind($r);foreach($parameters as$key=>$value)$route->setParameter($key,$value);return$r;
    }
    private function assertDenied(Request $request): void
    {
        try{$this->middleware()->handle($request,fn()=>response()->noContent());self::fail('Out-of-scope request was accepted.');}catch(HttpResponseException $e){self::assertContains($e->getResponse()->getStatusCode(),[403,404,422]);}
    }
    private function row(string $table,int $companyId,?int $branchId=null): int
    {
        $data=['company_id'=>$companyId];if($branchId!==null&&Schema::hasColumn($table,'branch_id'))$data['branch_id']=$branchId;return DB::table($table)->insertGetId($data);
    }
    private function party(string$table,int$companyId,bool$allBranches): int{return DB::table($table)->insertGetId(['company_id'=>$companyId,'scope_all_branches'=>(int)$allBranches,'is_active'=>1]);}
    private function linkParty(string$table,string$foreign,int$partyId,int$companyId,int$branchId): void{DB::table($table)->insert(['company_id'=>$companyId,$foreign=>$partyId,'branch_id'=>$branchId,'is_active'=>1]);}
}
