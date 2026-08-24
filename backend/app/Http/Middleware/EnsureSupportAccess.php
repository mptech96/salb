<?php

namespace App\Http\Middleware;

use App\Services\Platform\PrivilegedAuditService;
use App\Services\Support\SupportSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSupportAccess
{
    public function __construct(private SupportSessionService $sessions,private PrivilegedAuditService $audit){}
    public function handle(Request $request,Closure $next): Response
    {
        if(!(bool)$request->attributes->get('is_support_mode',false))return $next($request);
        $session=$request->attributes->get('support_session')??$this->sessions->resolve($request);
        if(!$this->sessions->authorize($session,$request)){
            $this->audit->denied($request,['actor_type'=>'SUPPORT','target_company_id'=>(int)$session->company_id,'support_session_id'=>$session->support_session_id,
                'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,'resource'=>'SupportWrite','action'=>'SUPPORT_WRITE','scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);
            return response()->json(['status'=>false,'code'=>'SUPPORT_WRITE_DENIED','message'=>'جلسة الدعم للقراءة فقط أو لا تملك capability صريحة.'],403);
        }
        if(in_array(strtoupper($request->method()),['GET','HEAD','OPTIONS'],true))return $next($request);
        return DB::transaction(function()use($request,$next,$session){$response=$next($request);$this->audit->record($request,['actor_type'=>'SUPPORT','target_company_id'=>(int)$session->company_id,
            'support_session_id'=>$session->support_session_id,'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,'resource'=>'SupportWrite',
            'action'=>'SUPPORT_WRITE','result'=>$response->getStatusCode()<400?'SUCCESS':'FAILED','scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);return $response;});
    }
}
