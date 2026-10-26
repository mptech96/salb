<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
final class UomSupportActor
{
    public static function install():void
    {
        UomStageAProof::guard();DB::table('users')->insert(['id'=>99,'name'=>'QA adapter support','username'=>'qa-adapter-support','password'=>'synthetic','is_active'=>1]);
        DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'QA platform']);DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'is_active'=>1]);
        DB::table('plans')->insert(['id'=>99,'plan_code'=>'ADAPTER-QA','plan_name'=>'QA']);
        foreach([1,2] as $company)DB::table('subscriptions')->insert(['company_id'=>$company,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach(['purchases','sales','inventory','accounting'] as $feature)DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
    }
    public static function token(array $capabilities,string $access='WRITE',int $company=1):string
    {
        $session=(string)\Illuminate\Support\Str::uuid();$token=\App\Models\User::findOrFail(99)->createToken('QA adapter',['session','support-mode','support-session:'.$session,'support-company:'.$company,'support-branch:'.$company],now()->addHour());
        DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>$company,'branch_id'=>$company,'personal_access_token_id'=>$token->accessToken->id,'access_level'=>$access,'capabilities_json'=>json_encode($capabilities),'reason'=>'QA isolated adapter acceptance','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);
        return $token->plainTextToken;
    }
    public static function forgetAuth():void {\Illuminate\Support\Facades\Auth::guard('web')->logout();\Illuminate\Support\Facades\Auth::forgetGuards();}
}
