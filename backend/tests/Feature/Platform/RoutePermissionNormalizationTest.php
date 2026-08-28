<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Http\Middleware\EnsureRoutePermission;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RoutePermissionNormalizationTest extends TestCase
{
    #[DataProvider('authorizedRouteProvider')]
    public function test_branch_manager_permission_mapping_accepts_supported_route_uri_forms(
        string $uri,
        string $permission,
        string $method = 'GET',
        string $name = 'test.route',
    ): void {
        $response = $this->runMiddleware($uri, [$permission], $method, $name);

        self::assertSame(204, $response->getStatusCode(), $uri);
    }

    public static function authorizedRouteProvider(): array
    {
        return [
            'dashboard without prefix' => ['dashboard', 'dashboard.view'],
            'dashboard with prefix' => ['api/dashboard', 'dashboard.view'],
            'dashboard with leading slash' => ['/api/dashboard', 'dashboard.view'],
            'inventory with prefix' => ['api/inventory', 'inventory.view'],
            'weighbridge dynamic parameter' => ['api/weighbridge/cards/{id}', 'weighbridge.view'],
            'reports catalog' => ['api/reports/catalog', 'reports.view'],
            'data exchange catalog' => ['api/imports/catalog', 'imports.view'],
            'data exchange export' => ['api/imports/export/inventory-balances', 'imports.export'],
            'named resource route remains supported' => ['api/shipments', 'shipments.view', 'GET', 'shipments.index'],
        ];
    }

    public function test_missing_permission_is_denied_after_normalization(): void
    {
        $response = $this->runMiddleware('api/inventory', []);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $response->getData(true)['code']);
        self::assertSame('inventory.view', $response->getData(true)['required_permission']);
    }

    public function test_genuinely_unknown_route_remains_fail_closed(): void
    {
        $response = $this->runMiddleware('api/not-a-real-company-route', ['dashboard.view']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_NOT_MAPPED', $response->getData(true)['code']);
    }

    public function test_most_specific_overlapping_mapping_still_wins(): void
    {
        $denied = $this->runMiddleware('api/reports/export/profit', ['reports.view']);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('reports.export', $denied->getData(true)['required_permission']);

        self::assertSame(
            204,
            $this->runMiddleware('api/reports/export/profit', ['reports.export'])->getStatusCode(),
        );
    }

    public function test_platform_admin_outside_support_is_still_separated_from_company_routes(): void
    {
        $companyRoute = $this->runMiddleware('api/dashboard', [], 'GET', 'test.route', 'SUPER_ADMIN', 'SUPER_ADMIN');
        self::assertSame(403, $companyRoute->getStatusCode());
        self::assertSame('PLATFORM_COMPANY_CONTEXT_REQUIRED', $companyRoute->getData(true)['code']);

        self::assertSame(
            204,
            $this->runMiddleware('api/system-admin/dashboard', [], 'GET', 'test.route', 'SUPER_ADMIN', 'SUPER_ADMIN')->getStatusCode(),
        );
    }

    public function test_company_role_does_not_gain_platform_route_access(): void
    {
        $response = $this->runMiddleware('api/system-admin/dashboard', ['dashboard.view']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_NOT_MAPPED', $response->getData(true)['code']);
    }

    public function test_support_behavior_is_not_changed_by_uri_normalization(): void
    {
        $response = $this->runMiddleware(
            'api/dashboard',
            [],
            'GET',
            'test.route',
            'SUPPORT',
            'SUPER_ADMIN',
            true,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function test_normalization_does_not_strip_arbitrary_path_segments(): void
    {
        $response = $this->runMiddleware('gateway/api/dashboard', ['dashboard.view']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_NOT_MAPPED', $response->getData(true)['code']);
    }

    private function runMiddleware(
        string $uri,
        array $permissions,
        string $method = 'GET',
        string $name = 'test.route',
        string $effectiveRole = 'BRANCH_MANAGER',
        string $actualRole = 'BRANCH_MANAGER',
        bool $support = false,
    ): Response {
        $request = Request::create('/'.ltrim($uri, '/'), $method);
        $route = (new Route([$method], ltrim($uri, '/'), static fn () => null))->name($name);
        $request->setRouteResolver(static fn () => $route);
        $request->attributes->set('effective_role_code', $effectiveRole);
        $request->attributes->set('actual_role_code', $actualRole);
        $request->attributes->set('is_support_mode', $support);
        $request->attributes->set('permission_codes', $permissions);

        return app(EnsureRoutePermission::class)->handle($request, static fn () => response()->noContent());
    }
}
