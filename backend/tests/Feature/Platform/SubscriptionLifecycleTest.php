<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Wave1SubscriptionTestCase;

class SubscriptionLifecycleTest extends Wave1SubscriptionTestCase
{
    public function test_platform_exposes_explicit_subscription_lifecycle_actions(): void
    {
        $routes=$this->productionSource('routes/api.php');
        foreach(['/renew','/plan','/status','/extend'] as $action) self::assertStringContainsString($action,$routes);
    }

    public function test_trial_login_is_allowed_while_dates_are_valid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$this->companyUserWithSubscription('TRIAL');
        $this->postJson('/api/login',['username'=>'manager-TRIAL-1','password'=>'password123'])->assertOk()->assertJsonPath('subscription.status','TRIAL')->assertJsonPath('subscription.access_mode','FULL');
    }

    public function test_active_login_is_allowed_while_dates_are_valid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$this->companyUserWithSubscription('ACTIVE');
        $this->postJson('/api/login',['username'=>'manager-ACTIVE-1','password'=>'password123'])->assertOk()->assertJsonPath('subscription.status','ACTIVE')->assertJsonPath('subscription.access_mode','FULL');
    }

    public function test_authentication_detects_expiry_without_mutating_subscription_state(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$ids=$this->companyUserWithSubscription('ACTIVE','2026-01-01','2026-08-23');
        $this->postJson('/api/login',['username'=>'manager-ACTIVE-1','password'=>'password123'])->assertOk()->assertJsonPath('subscription.status','EXPIRED')->assertJsonPath('subscription.access_mode','RESTRICTED_READ_ONLY');
        self::assertSame('ACTIVE',DB::table('subscriptions')->where('id',$ids['subscriptionId'])->value('status'));
    }

    public function test_pending_is_supported_but_blocked_from_normal_operations(): void
    {
        $resolved=app(SubscriptionLifecycleService::class)->resolveFromRows([(object)['id'=>1,'status'=>'PENDING','start_date'=>'2026-08-24','end_date'=>'2026-09-24']],'2026-08-24');
        self::assertSame('PENDING',$resolved->effective_status);self::assertSame('BLOCKED',app(SubscriptionAccessModeResolver::class)->resolve($resolved));
    }

    public function test_overlapping_subscriptions_resolve_deterministically(): void
    {
        $resolved=app(SubscriptionLifecycleService::class)->resolveFromRows([(object)['id'=>50,'status'=>'TRIAL','start_date'=>'2026-08-01','end_date'=>'2026-09-01'],(object)['id'=>10,'status'=>'ACTIVE','start_date'=>'2026-07-01','end_date'=>'2026-12-31'],(object)['id'=>99,'status'=>'ACTIVE','start_date'=>'2026-09-01','end_date'=>'2027-01-01']],'2026-08-24');
        self::assertSame(10,$resolved->id);self::assertSame('ACTIVE',$resolved->effective_status);
    }

    public function test_future_subscription_does_not_override_current_valid_subscription(): void
    {
        $resolved=app(SubscriptionLifecycleService::class)->resolveFromRows([(object)['id'=>1,'status'=>'ACTIVE','start_date'=>'2026-01-01','end_date'=>'2026-08-31'],(object)['id'=>2,'status'=>'ACTIVE','start_date'=>'2026-09-01','end_date'=>'2027-08-31']],'2026-08-24');
        self::assertSame(1,$resolved->id);
    }

    public function test_subscription_transition_is_audited_when_supported(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$ids=$this->companyUserWithSubscription('TRIAL');
        app(SubscriptionLifecycleService::class)->transition($ids['subscriptionId'],'ACTIVE');
        self::assertDatabaseHas('audit_logs',['module_name'=>'Subscriptions','action_type'=>'STATUS_TRANSITION','record_id'=>$ids['subscriptionId']]);
    }
}
