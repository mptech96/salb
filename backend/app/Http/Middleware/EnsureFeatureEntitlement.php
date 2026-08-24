<?php

namespace App\Http\Middleware;

use App\Services\Entitlement\EffectiveEntitlementService;
use App\Services\Entitlement\FeatureCatalogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFeatureEntitlement
{
    public function __construct(private FeatureCatalogService $catalog, private EffectiveEntitlementService $entitlements) {}
    public function handle(Request $request, Closure $next): Response
    {
        $companyId=(int)$request->attributes->get('tenant_company_id',0);
        $feature=$this->catalog->forUri((string)optional($request->route())->uri());
        if (!$feature || !$companyId) return $next($request);
        if (!$this->entitlements->allows($companyId,$feature)) return response()->json(['status'=>false,'code'=>'FEATURE_NOT_ENTITLED','message'=>'الميزة غير متاحة ضمن اشتراك الشركة.','required_feature'=>$feature],403);
        $request->attributes->set('effective_entitlements',$this->entitlements->resolve($companyId));
        return $next($request);
    }
}
