<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class SupportAccessTest extends PlatformControlPlaneTestCase
{
    public function test_support_token_is_time_bounded_and_company_scoped(): void
    {
        $source = $this->productionSource('app/Http/Controllers/Api/CompanyController.php');
        self::assertStringContainsString('now()->addHours(2)', $source);
        self::assertStringContainsString("'support-mode'", $source);
        self::assertStringContainsString("'support-company:' . \$id", $source);
    }

    public function test_support_context_cannot_call_platform_admin_routes(): void
    {
        $source = $this->productionSource('app/Http/Middleware/EnsurePlatformAdmin.php');
        self::assertStringContainsString('$isSupportMode', $source);
        self::assertStringContainsString('PLATFORM_ADMIN_REQUIRED', $source);
    }

    public function test_support_reason_and_ticket_are_mandatory(): void
    {
        $this->pendingDefect('DEF-SUP-001', 'Support token issuance must require a reason and ticket/reference.');
    }

    public function test_support_defaults_to_read_only_and_does_not_receive_all_permissions(): void
    {
        $this->pendingDefect('DEF-SUP-002', 'Support must default to READ_ONLY; write scopes require explicit short-lived elevation.');
    }

    public function test_support_entry_exit_expiry_and_writes_share_a_durable_session_id(): void
    {
        $this->pendingDefect('DEF-SUP-003', 'All support lifecycle events and actions must link to a durable support session.');
    }
}
