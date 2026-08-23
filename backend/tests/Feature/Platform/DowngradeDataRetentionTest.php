<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class DowngradeDataRetentionTest extends PlatformControlPlaneTestCase
{
    public function test_downgrade_has_an_effective_date_and_is_audited(): void
    {
        $this->pendingDefect('DEF-DOWN-001', 'Plan downgrade must be scheduled/effective-dated and retain an auditable entitlement snapshot.');
    }

    public function test_downgrade_never_deletes_or_rewrites_existing_module_data(): void
    {
        $this->pendingDefect('DEF-DOWN-001', 'Disabling a module blocks future use while preserving historical tenant data.');
    }

    public function test_downgrade_below_current_usage_blocks_new_growth_not_existing_rows(): void
    {
        $this->pendingDefect('DEF-DOWN-002', 'Over-limit retained data must remain readable while new capacity-consuming writes are denied.');
    }
}

