<?php

namespace App\Services\Support;

use App\Models\User;
use App\Services\Platform\PrivilegedAuditService;
use App\Services\Platform\PlatformAuthorityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SupportSessionService
{
    public function __construct(private PrivilegedAuditService $audit,private PlatformAuthorityService $authority){}

    public function create(Request $request,User $actor,array $data): array
    {
        if(!$this->authority->allows($request))throw new HttpException(403,'PLATFORM_ADMIN_REQUIRED');
        return DB::transaction(function()use($request,$actor,$data){
            $company=DB::table('companies')->where('id',$data['company_id'])->lockForUpdate()->first();
            if(!$company)throw new HttpException(404,'Target company not found.');
            $branchId=$data['branch_id']??DB::table('branches')->where('company_id',$company->id)->orderByDesc('is_active')->orderBy('id')->value('id');
            if($branchId&&!DB::table('branches')->where('id',$branchId)->where('company_id',$company->id)->exists())throw new HttpException(422,'Support branch is outside target company.');
            $level=strtoupper((string)($data['access_level']??'READ_ONLY'));$capabilities=array_values(array_unique($data['capabilities']??[]));
            if($level==='WRITE'&&$capabilities===[])throw new HttpException(422,'WRITE support requires explicit capabilities.');
            if($level==='READ_ONLY')$capabilities=[];
            $sessionId=(string)Str::uuid();$expires=CarbonImmutable::parse($data['expires_at']);
            if($expires->lessThanOrEqualTo(now()))throw new HttpException(422,'Support expiry must be in the future.');
            $abilities=['session','support-mode','support-session:'.$sessionId,'support-company:'.$company->id];if($branchId)$abilities[]='support-branch:'.$branchId;
            // Durable state is authoritative; transport remains valid briefly so the resolver can record EXPIRED.
            $newToken=$actor->createToken('support:'.$sessionId,$abilities,$expires->addDay());$tokenId=(int)$newToken->accessToken->id;
            DB::table('support_sessions')->insert(['support_session_id'=>$sessionId,'platform_user_id'=>$actor->id,'company_id'=>$company->id,'branch_id'=>$branchId,
                'personal_access_token_id'=>$tokenId,'access_level'=>$level,'capabilities_json'=>json_encode($capabilities,JSON_THROW_ON_ERROR),
                'reason'=>$data['reason'],'ticket_reference'=>$data['ticket_reference'],'status'=>'ACTIVE','started_at'=>now(),'expires_at'=>$expires,
                'created_at'=>now(),'updated_at'=>now()]);
            $event=['actor_type'=>'PLATFORM_ADMIN','target_company_id'=>(int)$company->id,'branch_id'=>$branchId,'support_session_id'=>$sessionId,
                'ticket_reference'=>$data['ticket_reference'],'reason'=>$data['reason'],'resource'=>'SupportSession','resource_id'=>null,
                'scope'=>['access_level'=>$level,'capabilities'=>$capabilities,'expires_at'=>$expires->toISOString()]];
            $this->audit->record($request,[...$event,'action'=>'SUPPORT_CREATE','result'=>'SUCCESS']);
            $this->audit->record($request,[...$event,'action'=>'SUPPORT_ENTRY','result'=>'SUCCESS']);
            return ['plain_text_token'=>$newToken->plainTextToken,'session'=>$this->byId($sessionId)];
        });
    }

    public function resolve(Request $request): object
    {
        $token=$request->user()?->currentAccessToken();$id=$this->abilityValue((array)($token?->abilities??[]),'support-session:');
        $session=$id?DB::table('support_sessions')->where('support_session_id',$id)->first():null;
        if(!$session||(int)$session->platform_user_id!==(int)$request->user()?->id||(int)$session->personal_access_token_id!==(int)$token?->id)throw new HttpException(403,'SUPPORT_SESSION_INVALID');
        $abilityCompany=(int)($this->abilityValue((array)$token->abilities,'support-company:')??0);
        if($abilityCompany!==(int)$session->company_id)throw new HttpException(403,'SUPPORT_SCOPE_MISMATCH');
        $abilityBranch=$this->abilityValue((array)$token->abilities,'support-branch:');
        $durableBranch=$session->branch_id?(int)$session->branch_id:null;
        if(($abilityBranch!==null?(int)$abilityBranch:null)!==$durableBranch)throw new HttpException(403,'SUPPORT_BRANCH_SCOPE_MISMATCH');
        if($session->status!=='ACTIVE')throw new HttpException(403,'SUPPORT_SESSION_CLOSED');
        if(CarbonImmutable::parse($session->expires_at)->isPast()){$this->expire($request,$session);throw new HttpException(403,'SUPPORT_SESSION_EXPIRED');}
        return $session;
    }

    public function authorize(object $session,Request $request): bool
    {
        if(in_array(strtoupper($request->method()),['GET','HEAD','OPTIONS'],true))return true;
        if($session->access_level!=='WRITE')return false;
        $capability=strtoupper($request->method()).':'.((string)optional($request->route())->getName()?:optional($request->route())->uri());
        return in_array($capability,json_decode($session->capabilities_json?:'[]',true),true);
    }

    public function exit(Request $request,object $session): void {$this->close($request,$session,'EXITED','ended_at','SUPPORT_EXIT');}
    public function revoke(Request $request,object $session): void {$this->close($request,$session,'REVOKED','revoked_at','SUPPORT_REVOKE');}
    public function expire(Request $request,object $session): void {$this->close($request,$session,'EXPIRED','ended_at','SUPPORT_EXPIRE');}

    private function close(Request $request,object $session,string $status,string $timestamp,string $action): void
    {
        DB::transaction(function()use($request,$session,$status,$timestamp,$action){
            $current=DB::table('support_sessions')->where('id',$session->id)->lockForUpdate()->first();
            if(!$current||$current->status!=='ACTIVE')return;
            DB::table('support_sessions')->where('id',$current->id)->update(['status'=>$status,$timestamp=>now(),'updated_at'=>now()]);
            if($current->personal_access_token_id)DB::table('personal_access_tokens')->where('id',$current->personal_access_token_id)->delete();
            $this->audit->record($request,['actor_type'=>'PLATFORM_ADMIN','target_company_id'=>(int)$session->company_id,'branch_id'=>$session->branch_id,
                'support_session_id'=>$session->support_session_id,'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,
                'resource'=>'SupportSession','action'=>$action,'result'=>'SUCCESS','scope'=>['status'=>$status]]);
        });
    }
    private function byId(string $id): object{return DB::table('support_sessions')->where('support_session_id',$id)->firstOrFail();}
    private function abilityValue(array $abilities,string $prefix): ?string {foreach($abilities as$a)if(is_string($a)&&str_starts_with($a,$prefix))return substr($a,strlen($prefix));return null;}
}
