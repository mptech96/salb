<?php

namespace Tests\Feature\Platform;

use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use Tests\Support\Wave1SubscriptionTestCase;

class DowngradeDataRetentionTest extends Wave1SubscriptionTestCase
{
    public function test_downgrade_has_an_effective_date_and_is_audited(): void
    {
        $this->pendingDefect('DEF-DOWN-001', 'Plan downgrade must be scheduled/effective-dated and retain an auditable entitlement snapshot.');
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
        $this->pendingDefect('DEF-DOWN-002', 'Over-limit retained data must remain readable while new capacity-consuming writes are denied.');
    }
}
