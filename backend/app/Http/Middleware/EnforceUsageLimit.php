<?php

namespace App\Http\Middleware;

use App\Services\Entitlement\UsageLimitService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnforceUsageLimit
{
    private const ROUTES=['users.store'=>'max_users','branches.store'=>'max_branches','cars.store'=>'max_vehicles'];
    public function __construct(private UsageLimitService $limits) {}
    public function handle(Request $request, Closure $next): Response
    {
        $metric=self::ROUTES[(string)optional($request->route())->getName()]??$this->documentMetric($request);
        $companyId=(int)$request->attributes->get('tenant_company_id',0);
        if (!$metric||!$companyId) return $next($request);
        return DB::transaction(function()use($request,$next,$companyId,$metric){$this->limits->assertCanGrow($companyId,$metric);return $next($request);},3);
    }
    private function documentMetric(Request $request): ?string
    {
        if (strtoupper($request->method())!=='POST') return null;
        $uri=(string)optional($request->route())->uri();
        return in_array($uri,['purchase-invoices','sales-invoices','official-documents'],true)?'max_documents':null;
    }
}
