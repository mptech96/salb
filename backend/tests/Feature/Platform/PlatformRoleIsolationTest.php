<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class PlatformRoleIsolationTest extends PlatformControlPlaneTestCase
{
    public function test_platform_routes_are_grouped_behind_platform_admin_middleware(): void
    {
        $routes = $this->productionSource('routes/api.php');
        self::assertStringContainsString("Route::middleware('platform.admin')->group", $routes);
        self::assertStringContainsString("'/system-admin/plans'", $routes);
        self::assertStringContainsString("'/system-admin/subscriptions'", $routes);
    }

    public function test_platform_middleware_rejects_non_super_admin_and_support_context(): void
    {
        $middleware = $this->productionSource('app/Http/Middleware/EnsurePlatformAdmin.php');
        self::assertStringContainsString('$roleCode !== \'SUPER_ADMIN\' || $isSupportMode', $middleware);
        self::assertStringContainsString('PLATFORM_ADMIN_REQUIRED', $middleware);
    }

    public function test_company_managers_never_inherit_platform_permissions(): void
    {
        $this->pendingDefect(
            'DEF-PLAT-001',
            'Platform permissions must use a separate namespace/catalog and must never be returned by allPermissions() to company-manager roles.'
        );
    }
}
