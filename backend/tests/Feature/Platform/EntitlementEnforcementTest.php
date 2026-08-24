<?php

namespace Tests\Feature\Platform;

use App\Services\Entitlement\EffectiveEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
}
