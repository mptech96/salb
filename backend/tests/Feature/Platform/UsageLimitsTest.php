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
}
