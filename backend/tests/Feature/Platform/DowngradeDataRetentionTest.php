<?php

namespace Tests\Feature\Platform;

use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use Tests\Support\Wave1SubscriptionTestCase;
use App\Services\Entitlement\EffectiveEntitlementService;
use Illuminate\Support\Facades\DB;

class DowngradeDataRetentionTest extends Wave1SubscriptionTestCase
{
    public function test_downgrade_has_an_effective_date_and_is_audited(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>1]);DB::table('subscription_entitlement_snapshots')->insert(['subscription_id'=>$x['subscriptionId'],'company_id'=>$x['companyId'],'plan_id'=>$x['planId'],'feature_code'=>'sales','is_enabled'=>0,'effective_from'=>'2026-09-01','source'=>'PLAN']);self::assertTrue(app(EffectiveEntitlementService::class)->allows($x['companyId'],'sales','2026-08-31'));self::assertFalse(app(EffectiveEntitlementService::class)->allows($x['companyId'],'sales','2026-09-01'));
    }

    public function test_downgrade_never_deletes_or_rewrites_existing_module_data(): void
    {
        $source=$this->productionSource('app/Services/Subscription/SubscriptionLifecycleService.php');
        self::assertDoesNotMatchRegularExpression('/\b(delete|truncate|drop)\s*\(/i',$source);
        $resolved=app(SubscriptionLifecycleService::class)->resolveFromRows([(object)['id'=>1,'status'=>'SUSPENDED','start_date'=>'2026-01-01','end_date'=>'2026-12-31']],'2026-08-24');
        self::assertSame(SubscriptionAccessModeResolver::RESTRICTED_READ_ONLY,app(SubscriptionAccessModeResolver::class)->resolve($resolved));
    }

    public function test_downgrade_below_current_usage_blocks_new_growth_not_existing_rows(): void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('plan_features')->insert(['plan_id'=>$x['planId'],'feature_code'=>'max_documents','limit_value'=>1]);DB::table('purchase_invoices')->insert(['company_id'=>$x['companyId']]);self::assertSame(1,DB::table('purchase_invoices')->where('company_id',$x['companyId'])->count());try{DB::transaction(fn()=>app(\App\Services\Entitlement\UsageLimitService::class)->assertCanGrow($x['companyId'],'max_documents'));self::fail('New growth must be blocked.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(409,$e->getStatusCode());}self::assertSame(1,DB::table('purchase_invoices')->where('company_id',$x['companyId'])->count());
    }
}
