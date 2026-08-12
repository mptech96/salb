<?php

use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureRoutePermission;
use App\Http\Middleware\EnforceTenantScope;
use App\Http\Middleware\ResolveAuthenticatedContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * واجهات API يجب ألا تتحول إلى Route باسم login عند غياب المصادقة.
         * إعادة null تجعل Laravel يرمي AuthenticationException،
         * ثم تُرجع طبقة الاستثناءات استجابة JSON برمز 401.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : '/login'
        );

        $middleware->alias([
            'auth.context' => ResolveAuthenticatedContext::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'company.context' => EnsureCompanyContext::class,
            'route.permission' => EnsureRoutePermission::class,
            'tenant.scope' => EnforceTenantScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->create();