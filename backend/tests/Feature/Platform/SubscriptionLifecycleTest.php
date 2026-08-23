<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class SubscriptionLifecycleTest extends PlatformControlPlaneTestCase
{
    public function test_platform_exposes_explicit_subscription_lifecycle_actions(): void
    {
        $routes = $this->productionSource('routes/api.php');
        foreach (['/renew', '/plan', '/status', '/extend'] as $action) {
            self::assertStringContainsString($action, $routes);
        }
    }

    public function test_trial_is_an_operationally_valid_subscription_state(): void
    {
        $this->pendingDefect('DEF-SUB-001', 'TRIAL must pass authentication and company access while its period is effective.');
    }

    public function test_authentication_never_mutates_subscription_lifecycle_state(): void
    {
        $this->pendingDefect('DEF-SUB-002', 'Login must be read-only with respect to subscription state; expiry belongs to a lifecycle service/job.');
    }

    public function test_pending_is_a_supported_and_consistent_state(): void
    {
        $this->pendingDefect('DEF-SUB-003', 'PENDING created by onboarding must be accepted by the central state machine and admin workflows.');
    }

    public function test_effective_subscription_is_resolved_by_dates_and_state_not_latest_id(): void
    {
        $this->pendingDefect('DEF-SUB-004', 'Effective subscription resolution must reject overlaps and respect start/end dates and state.');
    }
}

