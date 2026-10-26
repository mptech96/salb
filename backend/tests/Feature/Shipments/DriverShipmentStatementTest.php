<?php
namespace Tests\Feature\Shipments;

use App\Services\DriverShipmentStatement;
use App\Services\ShipmentCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverShipmentStatementTest extends ShipmentStageOneSafetyTest
{
    private function request(array $filters=[],int $company=1,?int $branch=1): Request
    {
        $r=Request::create('/api/statements/driver-shipments/1','GET',$filters);
        $r->attributes->add(['tenant_company_id'=>$company,'tenant_branch_id'=>$branch,'effective_role_code'=>$branch?'ACCOUNTANT':'COMPANY_ADMIN']);
        return $r;
    }
    private function cost(int $amount=1000,string $paid='UNPAID',int $driver=1,int $branch=1): int
    {
        $id=app(ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>$branch,'shipment_id'=>$branch,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>$amount,'payment_status'=>$paid,'financial_account_id'=>$paid==='PAID'?1:null,'capitalizable'=>false,'payee_type'=>'DRIVER','payee_id'=>$driver,'created_by'=>1,'currency_code'=>'SAR','exchange_rate'=>1]);
        app(ShipmentCostService::class)->postPendingForShipment(1,$branch,1,$branch);
        return $id;
    }
    public function test_original_evidence_not_current_setting_and_no_payment_inference(): void
    {
        $this->cost();$this->cost(300,'PAID');
        DB::table('accounting_settings')->update(['account_id'=>8]);
        DB::table('accounts')->where('id',2)->update(['is_active'=>0]);
        $r=app(DriverShipmentStatement::class)->report($this->request(),1);
        self::assertEquals('1000.000',$r['totals']->original_liabilities);
        $rows=$r['costs']->items();self::assertCount(2,$rows);
        self::assertSame('A_VERIFIED_ORIGINAL',$rows[0]->evidence_status);
        self::assertSame('C_HISTORICAL_PAYMENT_UNKNOWN',$rows[0]->category);
        self::assertNull($rows[0]->remaining_amount);self::assertNull($rows[0]->paid_amount);
        self::assertSame(2,(int)$rows[0]->original_account->id);
        self::assertSame('B_DIRECT_PAID_NOT_PAYABLE',$rows[1]->category);
        self::assertEquals(1000,$r['reconciliation'][0]['closing_payable']);
        self::assertFalse($r['settlement_enabled']);
    }
    public function test_missing_party_wrong_source_reversed_and_duplicate_are_blocked(): void
    {
        $id=$this->cost();$jid=DB::table('shipment_costs')->where('id',$id)->value('journal_entry_id');
        DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->where('credit','>',0)->update(['party_type'=>null,'party_id'=>null]);
        self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request(),1)['totals']->original_liabilities);
        DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->where('credit','>',0)->update(['party_type'=>'DRIVER','party_id'=>1]);
        foreach([['source_type'=>'OTHER'],['source_type'=>'SHIPMENT_COST','reversed_by_id'=>999],['reversed_by_id'=>null,'reversed_at'=>'2026-03-03']] as $changes){DB::table('journal_entries')->where('id',$jid)->update($changes);self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request(),1)['totals']->original_liabilities);}
    }
    public function test_company_wide_driver_does_not_expand_branch_and_foreign_driver_is_denied(): void
    {
        $this->cost(1000,'UNPAID',3);
        self::assertEquals(1000,app(DriverShipmentStatement::class)->report($this->request(),3)['totals']->original_liabilities);
        $ids=array_map(fn($d)=>(int)$d->id,app(DriverShipmentStatement::class)->drivers($this->request()));
        self::assertContains(3,$ids);self::assertNotContains(2,$ids);self::assertNotContains(4,$ids);
        try{app(DriverShipmentStatement::class)->report($this->request(),4);self::fail('foreign driver');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(404,$e->getStatusCode());}
        self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request([],1,2),3)['totals']->original_liabilities);
    }
    public function test_full_totals_pagination_opening_and_unallocated_gl_exception(): void
    {
        for($i=0;$i<26;$i++)$this->cost(1000);
        $r=app(DriverShipmentStatement::class)->report($this->request(['page'=>2,'per_page'=>25]),1);
        self::assertCount(1,$r['costs']->items());self::assertEquals(26,$r['totals']->total);self::assertEquals(26000,$r['totals']->original_liabilities);
        $r=app(DriverShipmentStatement::class)->report($this->request(['from_date'=>'2026-03-03','to_date'=>'2026-03-31']),1);
        self::assertEquals(0,$r['totals']->total);self::assertEquals(26000,$r['reconciliation'][0]['opening_payable']);self::assertEquals(26000,$r['reconciliation'][0]['closing_payable']);
    }
    public function test_supplier_and_advance_do_not_become_shipment_payables(): void
    {
        $this->cost();
        DB::table('shipment_costs')->update(['payee_type'=>'SUPPLIER','payee_id'=>99]);
        self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request(),1)['totals']->total);
        self::assertCount(0,app(DriverShipmentStatement::class)->report($this->request(),1)['reconciliation']);
    }
    public function test_advance_and_unlinked_debit_are_not_allocated_to_cost(): void
    {
        $this->cost();
        foreach([[8,200,'VOUCHER'],[2,400,'MANUAL']] as [$account,$amount,$source]) {
            app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','source_type'=>$source,'source_id'=>77,'description'=>'Isolated evidence test','created_by'=>1,'lines'=>[
                ['account_id'=>$account,'debit'=>$amount,'credit'=>0,'party_type'=>'DRIVER','party_id'=>1],['account_id'=>1,'debit'=>0,'credit'=>$amount,'financial_account_id'=>1]]]);
        }
        $r=app(DriverShipmentStatement::class)->report($this->request(),1);
        self::assertEquals(1000,$r['totals']->original_liabilities);
        self::assertEquals(600,$r['reconciliation'][0]['closing_payable']);
        self::assertEquals(400,$r['reconciliation'][0]['unclassified_period_debit']);
        self::assertSame(1,$r['reconciliation'][0]['unclassified_period_lines']);
        self::assertNull($r['costs']->items()[0]->remaining_amount);
    }
    public function test_real_route_permissions_and_feature_contract(): void
    {
        $r=$this->request();
        $route=new \Illuminate\Routing\Route('GET','api/statements/driver-shipments/{id}',fn()=>null);
        $r->setRouteResolver(fn()=>$route);
        $middleware=app(\App\Http\Middleware\EnsureRoutePermission::class);
        $r->attributes->set('permission_codes',[]);
        self::assertSame(403,$middleware->handle($r,fn()=>response('allowed'))->getStatusCode());
        $r->attributes->set('permission_codes',['statements.view']);
        self::assertSame(200,$middleware->handle($r,fn()=>response('allowed'))->getStatusCode());
        $r->attributes->set('actual_role_code','SUPER_ADMIN');
        self::assertSame(403,$middleware->handle($r,fn()=>response('allowed'))->getStatusCode());
        self::assertSame('reports',app(\App\Services\Entitlement\FeatureCatalogService::class)->forUri($route->uri()));
        $operational=app('router')->getRoutes()->getByAction(\App\Http\Controllers\Api\DriverShipmentStatementController::class.'@show');
        self::assertNotNull($operational);
        foreach(['auth:sanctum','subscription.access','tenant.scope','feature.entitlement','route.permission'] as $name) self::assertContains($name,$operational->gatherMiddleware());
    }
    public function test_subscription_and_reports_entitlement_are_enforced_by_real_middleware(): void
    {
        foreach(['subscriptions','plans','plan_features','subscription_entitlement_snapshots','company_entitlement_overrides'] as $table) \Illuminate\Support\Facades\Schema::dropIfExists($table);
        \Illuminate\Support\Facades\Schema::create('plans',function($t){$t->id();$t->string('plan_name');$t->string('plan_code');foreach(['max_branches','max_users','max_cars','max_invoices'] as $c)$t->integer($c)->nullable();});
        \Illuminate\Support\Facades\Schema::create('subscriptions',function($t){$t->id();$t->integer('company_id');$t->integer('plan_id');$t->string('status');$t->date('start_date');$t->date('end_date');});
        \Illuminate\Support\Facades\Schema::create('plan_features',function($t){$t->id();$t->integer('plan_id');$t->string('feature_code');$t->boolean('is_enabled');$t->integer('limit_value')->nullable();});
        DB::table('plans')->insert(['id'=>1,'plan_name'=>'Isolated','plan_code'=>'TEST']);
        DB::table('subscriptions')->insert(['id'=>1,'company_id'=>1,'plan_id'=>1,'status'=>'ACTIVE','start_date'=>'2020-01-01','end_date'=>'2099-12-31']);
        DB::table('plan_features')->insert(['plan_id'=>1,'feature_code'=>'reports','is_enabled'=>0]);
        $r=$this->request();$route=new \Illuminate\Routing\Route('GET','api/statements/driver-shipments/{id}',fn()=>null);$r->setRouteResolver(fn()=>$route);
        $feature=app(\App\Http\Middleware\EnsureFeatureEntitlement::class);
        self::assertSame(403,$feature->handle($r,fn()=>response('allowed'))->getStatusCode());
        DB::table('plan_features')->update(['is_enabled'=>1]);
        self::assertSame(200,$feature->handle($r,fn()=>response('allowed'))->getStatusCode());
        self::assertSame(200,app(\App\Http\Middleware\EnsureSubscriptionAccess::class)->handle($r,fn()=>response('allowed'))->getStatusCode());
        $r->attributes->set('tenant_company_id',2);
        self::assertSame(403,$feature->handle($r,fn()=>response('allowed'))->getStatusCode());
    }
    public function test_duplicate_source_unbalanced_and_cross_company_lines_are_not_proof(): void
    {
        $id=$this->cost();$jid=DB::table('shipment_costs')->where('id',$id)->value('journal_entry_id');
        DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->where('debit','>',0)->update(['debit'=>999]);
        self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request(),1)['totals']->original_liabilities);
        DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->where('debit','>',0)->update(['debit'=>1000,'company_id'=>2]);
        self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request(),1)['totals']->original_liabilities);
        DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->update(['company_id'=>1]);
        $entry=(array)DB::table('journal_entries')->where('id',$jid)->first();unset($entry['id']);DB::table('journal_entries')->insert($entry);
        self::assertEquals(0,app(DriverShipmentStatement::class)->report($this->request(),1)['totals']->original_liabilities);
    }
    public function test_company_wide_driver_two_real_branches_and_forged_branch_filter(): void
    {
        DB::table('branches')->insert(['id'=>2,'company_id'=>1,'branch_name'=>'Second','is_active'=>1]);
        DB::table('shipments')->insert(['id'=>2,'company_id'=>1,'branch_id'=>2,'shipment_number'=>'SECOND','commercial_status'=>'DRAFT','shipment_type'=>'PURCHASE']);
        $this->cost(1000,'UNPAID',3);$this->cost(2000,'UNPAID',3,2);
        $service=app(DriverShipmentStatement::class);
        self::assertEquals(1000,$service->report($this->request(['branch_id'=>2]),3)['totals']->original_liabilities);
        self::assertEquals(2000,$service->report($this->request([],1,2),3)['totals']->original_liabilities);
        self::assertEquals(3000,$service->report($this->request([],1,null),3)['totals']->original_liabilities);
        self::assertEquals(2000,$service->report($this->request(['branch_id'=>2],1,null),3)['totals']->original_liabilities);
    }
    public function test_real_driver_advance_voucher_remains_outside_payables(): void
    {
        $this->cost();
        $advance=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'ADV','account_name'=>'Driver advance','account_type'=>'ASSET','normal_side'=>'DEBIT','allow_posting'=>1,'is_group'=>0,'is_active'=>1]);
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'DRIVER_ADVANCE_ACCOUNT','account_id'=>$advance]);
        $voucher=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>2,'voucher_number'=>'PAY-UAT-ADV','voucher_date'=>'2026-03-03','reference_type'=>'DRIVER','reference_id'=>1,'amount'=>200,'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>200,'financial_account_id'=>1,'created_by'=>1]);
        $result=app(\App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>1,'voucher_id'=>$voucher,'created_by'=>1]);
        self::assertTrue($result->success,$result->message);
        self::assertEquals(200,DB::table('journal_entry_lines')->where('journal_entry_id',$result->journalEntryId)->where('account_id',$advance)->sum('debit'));
        $r=app(DriverShipmentStatement::class)->report($this->request(),1);
        self::assertEquals(1000,$r['totals']->original_liabilities);self::assertEquals(1000,$r['reconciliation'][0]['closing_payable']);self::assertEquals(1,$r['totals']->total);
    }
    public function test_missing_journal_has_no_invented_journal_date_and_stays_in_review(): void
    {
        $id=$this->cost();DB::table('shipment_costs')->where('id',$id)->update(['journal_entry_id'=>null]);
        $r=app(DriverShipmentStatement::class)->report($this->request(['from_date'=>'2026-03-01','to_date'=>'2026-03-31']),1);
        self::assertSame('D_INCOMPLETE_OR_CONFLICTING',$r['costs']->items()[0]->category);
        self::assertNull($r['costs']->items()[0]->entry_date);self::assertNull($r['costs']->items()[0]->journal_entry_id);
        $this->getJson('/api/statements/driver-shipments/1')->assertUnauthorized();
    }
}
