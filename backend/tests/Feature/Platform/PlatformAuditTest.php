<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\Platform\PrivilegedAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\Wave1SubscriptionTestCase;

class PlatformAuditTest extends Wave1SubscriptionTestCase
{
    public function test_privileged_mutation_and_success_audit_are_atomic_and_structured():void
    {
        $request=$this->platformRequest();$company=DB::table('companies')->insertGetId(['company_name'=>'Target','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $id=app(PrivilegedAuditService::class)->transactional($request,['target_company_id'=>$company,'resource'=>'Plan','action'=>'PLAN_UPDATE','reason'=>'Approved','ticket_reference'=>'CHG-1','before'=>['name'=>'Old'],'after'=>['name'=>'New']],fn()=>DB::table('plans')->insertGetId(['plan_name'=>'New','plan_code'=>'NEW']));
        self::assertGreaterThan(0,$id);$audit=DB::table('audit_logs')->where('action_type','PLAN_UPDATE')->first();self::assertSame('SUCCESS',$audit->result);self::assertSame('SUPER_ADMIN',$audit->actor_role_code);self::assertSame('CHG-1',$audit->ticket_reference);self::assertStringContainsString('Old',$audit->before_json);self::assertStringContainsString('New',$audit->after_json);
    }

    public function test_audit_persistence_failure_rolls_back_privileged_mutation():void
    {
        $request=$this->platformRequest();Schema::drop('audit_logs');try{app(PrivilegedAuditService::class)->transactional($request,['resource'=>'Company','action'=>'CREATE'],fn()=>DB::table('companies')->insertGetId(['company_name'=>'Must Roll Back','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]));self::fail('Audit failure must throw.');}catch(\Throwable){self::assertSame(0,DB::table('companies')->where('company_name','Must Roll Back')->count());}
    }

    public function test_denied_platform_attempt_is_audited_where_possible():void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');Sanctum::actingAs(User::findOrFail($x['userId']),['session']);$this->getJson('/api/system-admin/features')->assertForbidden();self::assertDatabaseHas('audit_logs',['result'=>'DENIED','action_type'=>'PLATFORM_ACCESS','company_id'=>$x['companyId']]);
    }

    public function test_sensitive_values_are_redacted():void
    {
        app(PrivilegedAuditService::class)->record($this->platformRequest(),['resource'=>'Security','action'=>'TEST','scope'=>['password'=>'secret','authorization'=>'Bearer token','safe'=>'value']]);$json=DB::table('audit_logs')->value('scope_json');self::assertStringNotContainsString('secret',$json);self::assertStringNotContainsString('Bearer token',$json);self::assertStringContainsString('[REDACTED]',$json);
    }

    public function test_tenant_reads_only_company_audit_and_cannot_mutate_history():void
    {
        $x=$this->companyUserWithSubscription('ACTIVE');DB::table('audit_logs')->insert([['company_id'=>null,'module_name'=>'Platform','action_type'=>'GLOBAL','result'=>'SUCCESS'],['company_id'=>$x['companyId'],'module_name'=>'Tenant','action_type'=>'LOCAL','result'=>'SUCCESS']]);Sanctum::actingAs(User::findOrFail($x['userId']),['session']);$response=$this->getJson('/api/audit-logs')->assertOk();self::assertCount(1,$response->json('data'));self::assertSame('LOCAL',$response->json('data.0.action_type'));$this->putJson('/api/audit-logs/1',[])->assertNotFound();
    }

    private function platformRequest():Request
    {
        $user=$this->platformUser();$request=Request::create('/api/system-admin/test','POST');$request->setUserResolver(fn()=>$user);$request->attributes->set('actual_role_code','SUPER_ADMIN');return $request;
    }
    private function platformUser():User{$existing=DB::table('users')->whereNull('company_id')->first();if($existing)return User::findOrFail($existing->id);$role=DB::table('roles')->insertGetId(['role_name'=>'Platform','role_code'=>'SUPER_ADMIN','is_active'=>1]);$id=DB::table('users')->insertGetId(['company_id'=>null,'name'=>'Platform','username'=>'audit-platform','password'=>Hash::make('password'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);DB::table('user_roles')->insert(['user_id'=>$id,'role_id'=>$role,'company_id'=>null,'is_active'=>1]);return User::findOrFail($id);}
}
