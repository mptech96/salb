<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAuthorityService;
use App\Services\Platform\PrivilegedAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function __construct(private PlatformAuthorityService $authority,private PrivilegedAuditService $audit){}
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->authority->allows($request)) {
            $this->audit->denied($request,['actor_type'=>'AUTHENTICATED','target_company_id'=>$request->attributes->get('tenant_company_id'),'resource'=>'PlatformRoute','action'=>'PLATFORM_ACCESS','scope'=>['uri'=>optional($request->route())->uri()]]);
            return response()->json([
                'status' => false,
                'code' => 'PLATFORM_ADMIN_REQUIRED',
                'message' => 'هذه العملية متاحة لمدير منصة صلب فقط.',
            ], 403);
        }

        return $next($request);
    }
}
