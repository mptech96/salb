<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class EntitlementEnforcementTest extends PlatformControlPlaneTestCase
{
    public function test_permission_without_module_entitlement_is_denied_by_backend(): void
    {
        $this->pendingDefect('DEF-ENT-001', 'A user with sales.post must still be denied when the company lacks the Sales entitlement.');
    }

    public function test_entitlement_without_user_permission_is_denied_by_backend(): void
    {
        $this->pendingDefect('DEF-ENT-002', 'An entitled module must not bypass the company user action permission.');
    }

    public function test_effective_entitlements_include_plan_snapshot_and_company_overrides(): void
    {
        $this->pendingDefect('DEF-ENT-003', 'Backend must resolve plan snapshot, overrides, subscription access mode, and deny-by-default precedence.');
    }
}

