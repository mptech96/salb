<?php

namespace Tests\Feature\Platform;

use App\Services\Entitlement\EffectiveEntitlementService;
use App\Services\Entitlement\FeatureCatalogService;
use App\Http\Middleware\EnsureFeatureEntitlement;
use App\Http\Middleware\EnsureRoutePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Wave1SubscriptionTestCase;

class EntitlementEnforcementTest extends Wave1SubscriptionTestCase
{
    public function test_permission_without_module_entitlement_is_denied_by_backend(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>0]);self::assertFalse(app(EffectiveEntitlementService::class)->allows($x['companyId'],'sales','2026-08-24'));
    }

    public function test_entitlement_without_user_permission_is_denied_by_backend(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>1]);self::assertTrue(app(EffectiveEntitlementService::class)->allows($x['companyId'],'sales','2026-08-24'));self::assertFalse(app(\App\Services\Auth\SessionContextService::class)->permissionsForUser($x['userId'],$x['companyId'])->contains('sales.post'));
    }

    public function test_effective_entitlements_include_plan_snapshot_and_company_overrides(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>1]);DB::table('subscription_entitlement_snapshots')->insert(['subscription_id'=>$x['subscriptionId'],'company_id'=>$x['companyId'],'plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>0,'effective_from'=>'2026-01-01','effective_to'=>'2026-12-31','source'=>'PLAN']);DB::table('company_entitlement_overrides')->insert(['company_id'=>$x['companyId'],'feature_code'=>'sales','is_enabled'=>1,'effective_from'=>'2026-08-01']);$r=app(EffectiveEntitlementService::class)->resolve($x['companyId'],'2026-08-24');self::assertTrue($r['features']['sales']);self::assertSame('company_override',$r['source']);
    }

    public function test_company_override_can_explicitly_disable_a_plan_feature(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>1]);DB::table('company_entitlement_overrides')->insert(['company_id'=>$x['companyId'],'feature_code'=>'sales','is_enabled'=>0,'effective_from'=>'2026-08-01']);self::assertFalse(app(EffectiveEntitlementService::class)->allows($x['companyId'],'sales','2026-08-24'));
    }

    public function test_scheduled_upgrade_and_tenant_overrides_are_effective_dated_and_isolated(): void
    {
        $a=$this->companyUserWithSubscription('ACTIVE');$b=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert([['plan_id'=>$a['planId'],'feature_code'=>'sales','is_enabled'=>0],['plan_id'=>$b['planId'],'feature_code'=>'sales','is_enabled'=>0]]);DB::table('company_entitlement_overrides')->insert(['company_id'=>$a['companyId'],'feature_code'=>'sales','is_enabled'=>1,'effective_from'=>'2026-09-01']);$service=app(EffectiveEntitlementService::class);self::assertFalse($service->allows($a['companyId'],'sales','2026-08-31'));self::assertTrue($service->allows($a['companyId'],'sales','2026-09-01'));self::assertFalse($service->allows($b['companyId'],'sales','2026-09-01'));
    }

    public function test_support_mode_has_no_entitlement_bypass_in_backend_middleware(): void
    {
        $source=$this->productionSource('app/Http/Middleware/EnsureFeatureEntitlement.php');self::assertStringNotContainsString('is_support_mode',$source);self::assertStringContainsString('FEATURE_NOT_ENTITLED',$source);
    }

    public function test_every_configured_feature_prefix_maps_with_all_supported_api_forms(): void
    {
        $catalog=app(FeatureCatalogService::class);

        foreach(config('sulb_features.route_prefixes') as$prefix=>$feature){
            self::assertSame($feature,$catalog->forUri($prefix),$prefix);
            self::assertSame($feature,$catalog->forUri('api/'.$prefix),'api/'.$prefix);
            self::assertSame($feature,$catalog->forUri('/api/'.$prefix),'/api/'.$prefix);
            self::assertSame($feature,$catalog->forUri('/api/'.$prefix.'/example'),'/api/'.$prefix.'/example');
        }

        self::assertNull($catalog->forUri('/api/unrelated-route'));
        self::assertNull($catalog->forUri('/api/system-admin/plans'));
        self::assertNull($catalog->forUri('/other/api/sales-invoices'));
    }

    public function test_most_specific_configured_prefix_wins(): void
    {
        config()->set('sulb_features.route_prefixes',['fixed'=>'generic','fixed/assets'=>'specific']);

        self::assertSame('specific',app(FeatureCatalogService::class)->forUri('/api/fixed/assets/1'));
    }

    public function test_disabled_features_deny_real_api_prefixed_routes(): void
    {
        foreach([
            'api/sales-invoices'=>'sales',
            'api/purchase-invoices'=>'purchases',
            'api/inventory'=>'inventory',
            'api/weighbridge/cards'=>'weighbridge',
            'api/accounting/overview'=>'accounting',
        ]as$uri=>$feature){
            $x=$this->tenantWithFeature($feature,false);
            $response=$this->entitlementMiddleware($this->routeRequest($uri,$x['companyId'],'MANAGER'));
            self::assertSame(403,$response->getStatusCode(),$uri);
            self::assertSame('FEATURE_NOT_ENTITLED',json_decode($response->getContent(),true)['code']);
        }
    }

    public function test_enabled_feature_and_manager_permission_layer_pass(): void
    {
        $x=$this->tenantWithFeature('sales',true);
        $request=$this->routeRequest('api/sales-invoices',$x['companyId'],'MANAGER','sales-invoices.index');

        $response=$this->entitlementMiddleware($request,fn(Request$r):Response=>app(EnsureRoutePermission::class)->handle($r,fn()=>response()->noContent()));

        self::assertSame(204,$response->getStatusCode());
    }

    public function test_enabled_feature_with_missing_user_permission_is_denied_by_permission_layer(): void
    {
        $x=$this->tenantWithFeature('sales',true);
        $request=$this->routeRequest('api/sales-invoices',$x['companyId'],'BRANCH_MANAGER','sales-invoices.index');
        $request->attributes->set('permission_codes',[]);

        $response=$this->entitlementMiddleware($request,fn(Request$r):Response=>app(EnsureRoutePermission::class)->handle($r,fn()=>response()->noContent()));

        self::assertSame(403,$response->getStatusCode());
        self::assertSame('PERMISSION_DENIED',json_decode($response->getContent(),true)['code']);
    }

    public function test_support_mode_cannot_bypass_disabled_feature(): void
    {
        $x=$this->tenantWithFeature('sales',false);
        $request=$this->routeRequest('api/sales-invoices',$x['companyId'],'SUPPORT');
        $request->attributes->set('is_support_mode',true);

        $response=$this->entitlementMiddleware($request);

        self::assertSame(403,$response->getStatusCode());
        self::assertSame('FEATURE_NOT_ENTITLED',json_decode($response->getContent(),true)['code']);
    }

    public function test_platform_routes_never_map_to_tenant_features(): void
    {
        self::assertNull(app(FeatureCatalogService::class)->forUri('api/system-admin/companies/8/entitlements'));
        self::assertNull(app(FeatureCatalogService::class)->forUri('/api/companies/8/support-access'));
    }

    private function tenantWithFeature(string$feature,bool$enabled): array
    {
        $x=$this->companyUserWithSubscription('ACTIVE');
        DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>$feature,'is_enabled'=>(int)$enabled]);
        return$x;
    }

    private function routeRequest(string$uri,int$companyId,string$role,string$name='test.route'): Request
    {
        $request=Request::create('/'.$uri,'GET');
        $route=(new Route('GET',$uri,fn()=>null))->name($name);
        $request->setRouteResolver(static fn()=>$route);
        $request->attributes->set('tenant_company_id',$companyId);
        $request->attributes->set('effective_role_code',$role);
        $request->attributes->set('actual_role_code',$role);
        return$request;
    }

    private function entitlementMiddleware(Request$request,?callable$next=null): Response
    {
        return app(EnsureFeatureEntitlement::class)->handle($request,$next??fn()=>response()->noContent());
    }
}
