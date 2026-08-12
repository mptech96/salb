<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $roleCode = strtoupper((string) $request->attributes->get('actual_role_code', ''));
        $isSupportMode = (bool) $request->attributes->get('is_support_mode', false);

        if ($roleCode !== 'SUPER_ADMIN' || $isSupportMode) {
            return response()->json([
                'status' => false,
                'code' => 'PLATFORM_ADMIN_REQUIRED',
                'message' => 'هذه العملية متاحة لمدير منصة صلب فقط.',
            ], 403);
        }

        return $next($request);
    }
}
