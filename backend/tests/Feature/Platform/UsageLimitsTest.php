<?php

namespace Tests\Feature\Platform;

use App\Services\Entitlement\UsageLimitService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Wave1SubscriptionTestCase;

class UsageLimitsTest extends Wave1SubscriptionTestCase
{
    public function test_user_limit_is_enforced_transactionally(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'max_users','limit_value'=>1]);$this->expectException(HttpException::class);DB::transaction(fn()=>app(UsageLimitService::class)->assertCanGrow($x['companyId'],'max_users'));
    }

    public function test_branch_car_and_document_limits_are_enforced_transactionally(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');foreach(['max_branches','max_vehicles','max_stores','max_documents'] as$m)DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>$m,'limit_value'=>1]);DB::table('cars')->insert(['company_id'=>$x['companyId']]);DB::table('stores')->insert(['company_id'=>$x['companyId']]);DB::table('purchase_invoices')->insert(['company_id'=>$x['companyId']]);foreach(['max_branches','max_vehicles','max_stores','max_documents'] as$m){try{DB::transaction(fn()=>app(UsageLimitService::class)->assertCanGrow($x['companyId'],$m));self::fail($m.' should be blocked');}catch(HttpException $e){self::assertSame(409,$e->getStatusCode());}}
    }

    public function test_inactive_or_archived_resource_counting_is_defined_by_metric(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('cars')->insert(['company_id'=>$x['companyId'],'is_active'=>0]);self::assertSame(1,app(UsageLimitService::class)->usage($x['companyId'],'max_vehicles'));
    }

    public function test_growth_check_uses_company_row_lock_and_transactional_middleware(): void
    {
        self::assertStringContainsString('lockForUpdate()', $this->productionSource('app/Services/Entitlement/UsageLimitService.php'));self::assertStringContainsString('DB::transaction', $this->productionSource('app/Http/Middleware/EnforceUsageLimit.php'));
    }

    public function test_zero_document_limit_blocks_prefixed_unnamed_and_named_creation_only(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');
        DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'max_documents','limit_value'=>0]);
        foreach(['sales-invoices','purchase-invoices','official-documents'] as $uri){
            foreach(['','api/'] as $prefix)self::assertSame(409,$this->limitStatus($x['companyId'],'POST',$prefix.$uri));
        }
        self::assertSame(409,$this->limitStatus($x['companyId'],'POST','custom/mount','sales-invoices.store'));
        foreach([['POST','api/sales-invoices/1/post'],['POST','api/purchase-invoices/1/void'],['POST','api/official-documents/1/attachments'],['POST','api/vouchers'],['PUT','api/sales-invoices/1'],['GET','api/sales-invoices']] as [$method,$uri])self::assertSame(201,$this->limitStatus($x['companyId'],$method,$uri));
    }

    public function test_document_limits_preserve_zero_finite_null_and_company_scope(): void
    {
        foreach([0,1,3,null] as $limit){
            $x=$this->companyUserWithSubscription('ACTIVE');
            DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'max_documents','limit_value'=>$limit]);
            DB::table('purchase_invoices')->insert(['company_id'=>999999]);
            self::assertSame($limit===0?409:201,$this->limitStatus($x['companyId'],'POST','api/sales-invoices'));
            if($limit!==null&&$limit>0){for($i=0;$i<$limit;$i++)DB::table('purchase_invoices')->insert(['company_id'=>$x['companyId']]);self::assertSame(409,$this->limitStatus($x['companyId'],'POST','api/sales-invoices'));}
        }
    }

    private function limitStatus(int $company,string $method,string $uri,?string $name=null): int
    {
        $request=\Illuminate\Http\Request::create('/'.$uri,$method);
        $route=new \Illuminate\Routing\Route($method,$uri,fn()=>null);if($name)$route->name($name);
        $request->setRouteResolver(fn()=>$route);$request->attributes->set('tenant_company_id',$company);
        try{return app(\App\Http\Middleware\EnforceUsageLimit::class)->handle($request,fn()=>response()->json([],201))->getStatusCode();}catch(HttpException $e){return $e->getStatusCode();}
    }
}
