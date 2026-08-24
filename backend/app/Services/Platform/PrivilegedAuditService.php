<?php

namespace App\Services\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PrivilegedAuditService
{
    private const SENSITIVE=['password','password_confirmation','temporary_password','token','access_token','authorization','secret','client_secret'];

    public function record(Request $request,array $event): int
    {
        return (int)DB::table('audit_logs')->insertGetId([
            'company_id'=>$event['target_company_id']??null,'branch_id'=>$event['branch_id']??null,
            'user_id'=>$request->user()?->id,'actor_type'=>$event['actor_type']??'PLATFORM_ADMIN',
            'actor_role_code'=>$request->attributes->get('actual_role_code'),'support_session_id'=>$event['support_session_id']??null,
            'ticket_reference'=>$event['ticket_reference']??null,'reason'=>$event['reason']??null,
            'module_name'=>$event['resource']??'Platform','action_type'=>strtoupper((string)$event['action']),
            'record_id'=>$event['resource_id']??null,'description'=>$event['description']??null,
            'scope_json'=>$this->json($event['scope']??null),'before_json'=>$this->json($event['before']??null),
            'after_json'=>$this->json($event['after']??null),'result'=>strtoupper((string)($event['result']??'SUCCESS')),
            'request_id'=>$request->attributes->get('request_id')??(string)Str::uuid(),
            'ip_address'=>$request->ip(),'user_agent'=>$request->userAgent(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function transactional(Request $request,array $event,callable $mutation): mixed
    {
        try {
            return DB::transaction(function()use($request,$event,$mutation){$result=$mutation();$this->record($request,[...$event,'result'=>'SUCCESS']);return $result;});
        } catch(Throwable $e) {
            try{$this->record($request,[...$event,'result'=>'FAILED','description'=>$e->getMessage()]);}catch(Throwable){}
            throw $e;
        }
    }

    public function denied(Request $request,array $event): void
    {
        try{$this->record($request,[...$event,'result'=>'DENIED']);}catch(Throwable){}
    }

    private function json(mixed $value): ?string
    {
        if($value===null)return null;
        return json_encode($this->sanitize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
    private function sanitize(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        $out=[];foreach($value as$key=>$item){$normalized=strtolower((string)$key);$out[$key]=in_array($normalized,self::SENSITIVE,true)?'[REDACTED]':$this->sanitize($item);}return $out;
    }
}
