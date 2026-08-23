<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class TenantIsolationTest extends PlatformControlPlaneTestCase
{
    public function test_tenant_middleware_overwrites_client_supplied_company_id(): void
    {
        $source = $this->productionSource('app/Http/Middleware/EnforceTenantScope.php');
        self::assertStringContainsString('$request->merge([\'company_id\' => $companyId])', $source);
        self::assertStringContainsString('->where(\'company_id\', $companyId)', $source);
    }

    public function test_company_portal_uses_company_and_tenant_middleware(): void
    {
        $routes = $this->productionSource('routes/api.php');
        self::assertStringContainsString("['company.context', 'tenant.scope', 'route.permission']", $routes);
    }

    public function test_every_company_resource_and_foreign_key_has_central_or_policy_scope_coverage(): void
    {
        $this->pendingDefect('DEF-TEN-001', 'Returns, inventory operations, shipment costs, weighbridge, openings, attachments, and overrides need complete scope tests.');
    }

    public function test_support_bypass_is_intentional_scoped_and_audited(): void
    {
        $this->pendingDefect('DEF-TEN-002', 'Support must never become an implicit tenant-isolation bypass outside its token-bound target and access level.');
    }
}
