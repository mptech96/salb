<?php

namespace App\Http\Middleware;

use App\Services\Platform\PrivilegedAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuditPrivilegedMutation
{
    public function __construct(private PrivilegedAuditService $audit){}
    public function handle(Request $request,Closure $next): Response
    {
        if(in_array(strtoupper($request->method()),['GET','HEAD','OPTIONS'],true))return $next($request);
        try{return DB::transaction(function()use($request,$next){
            $response=$next($request);$status=$response->getStatusCode();
            $this->audit->record($request,['actor_type'=>'PLATFORM_ADMIN','target_company_id'=>$this->targetCompany($request),
                'resource'=>'PlatformMutation','resource_id'=>$request->route('id')??$request->route('companyId'),
                'action'=>'PLATFORM_MUTATION','result'=>$status<400?'SUCCESS':($status===403?'DENIED':'FAILED'),
                'reason'=>$request->input('reason'),'ticket_reference'=>$request->input('ticket_reference'),
                'scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()],
                'before'=>null,'after'=>['http_status'=>$status]]);
            return $response;
        });}catch(Throwable $e){try{$this->audit->record($request,['actor_type'=>'PLATFORM_ADMIN','target_company_id'=>$this->targetCompany($request),'resource'=>'PlatformMutation','action'=>'PLATFORM_MUTATION','result'=>'FAILED','description'=>$e->getMessage(),'scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);}catch(Throwable){}throw $e;}
    }
    private function targetCompany(Request $request): ?int
    {
        foreach(['companyId','id']as$key){$value=$request->route($key);if(is_numeric($value)&&str_contains((string)optional($request->route())->uri(),'compan'))return(int)$value;}
        return $request->filled('company_id')?$request->integer('company_id'):null;
    }
}
