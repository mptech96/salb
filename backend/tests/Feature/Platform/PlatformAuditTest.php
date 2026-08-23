<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class PlatformAuditTest extends PlatformControlPlaneTestCase
{
    public function test_platform_dashboard_currently_reads_cross_company_audit_activity(): void
    {
        $source = $this->productionSource('app/Http/Controllers/Api/SystemAdminDashboardController.php');
        self::assertStringContainsString("DB::table('audit_logs as a')", $source);
        self::assertStringContainsString("->leftJoin('companies as c'", $source);
    }

    public function test_privileged_platform_and_support_audit_is_fail_closed(): void
    {
        $this->pendingDefect('DEF-AUD-001', 'A privileged mutation or support-token issue must fail when its required audit record cannot be persisted.');
    }

    public function test_platform_audit_records_actor_target_scope_reason_before_after_and_result(): void
    {
        $this->pendingDefect('DEF-AUD-002', 'Platform audit must structurally identify actor, target company, support session, request, reason, diff, and result.');
    }
}

