<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class ExpiredSubscriptionReadOnlyTest extends PlatformControlPlaneTestCase
{
    public function test_expired_company_can_login_for_restricted_read_only_access(): void
    {
        $this->pendingDefect('DEF-SUB-005', 'EXPIRED tenants must retain restricted read-only, billing, renewal, and export access.');
    }

    public function test_expired_company_cannot_create_modify_post_void_or_import(): void
    {
        $this->pendingDefect('DEF-SUB-005', 'Restricted mode must deny all business writes even when the user retains a company permission.');
    }

    public function test_subscription_expiry_never_deletes_tenant_data(): void
    {
        $this->pendingDefect('DEF-RET-001', 'Expiry must preserve every document, lot, journal entry, attachment, and audit record.');
    }
}

