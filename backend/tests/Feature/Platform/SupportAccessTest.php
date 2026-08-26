<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Wave1SubscriptionTestCase;

class SupportAccessTest extends Wave1SubscriptionTestCase
{
    public function test_reason_ticket_and_expiry_are_required():void
    {
        [$admin,$company]=$this->platformAndCompany();Sanctum::actingAs($admin,['session','platform-admin']);
        $this->postJson("/api/companies/$company/support-access",['ticket_reference'=>'T','expires_at'=>now()->addHour()])->assertJsonValidationErrors('reason');
        $this->postJson("/api/companies/$company/support-access",['reason'=>'R','expires_at'=>now()->addHour()])->assertJsonValidationErrors('ticket_reference');
        $this->postJson("/api/companies/$company/support-access",['reason'=>'R','ticket_reference'=>'T'])->assertJsonValidationErrors('expires_at');
    }

    public function test_read_only_is_default_and_durable_company_scoped_session_is_created():void
    {
        $created=$this->createSupport();$row=DB::table('support_sessions')->where('support_session_id',$created['support_session_id'])->first();
        self::assertNotNull($row);self::assertSame('READ_ONLY',$row->access_level);self::assertSame('ACTIVE',$row->status);self::assertSame('TICKET-1',$row->ticket_reference);
        $this->withToken($created['token'])->getJson('/api/me')->assertOk()->assertJsonPath('user.support_session_id',$row->support_session_id)->assertJsonPath('user.permissions',[]);
    }

    public function test_payload_or_headers_cannot_switch_support_company():void
    {
        $created=$this->createSupport();$other=DB::table('companies')->insertGetId(['company_name'=>'Other','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $this->withToken($created['token'])->withHeader('X-Company-ID',(string)$other)->getJson('/api/me')->assertJsonPath('user.company_id',$created['company_id']);
    }

    public function test_support_context_is_denied_platform_routes():void
    {
        $created=$this->createSupport();$this->withToken($created['token'])->getJson('/api/system-admin/features')->assertForbidden();
    }

    public function test_read_only_blocks_post_put_patch_and_delete():void
    {
        $created=$this->createSupport();foreach([['postJson','/api/workers'],['putJson','/api/workers/999'],['patchJson','/api/workers/999'],['deleteJson','/api/workers/999']]as[$method,$uri]){$response=$this->withToken($created['token'])->{$method}($uri,[]);$response->assertForbidden()->assertJsonPath('code','SUPPORT_WRITE_DENIED');}
    }

    public function test_read_only_support_can_read_only_its_durable_target_branch():void
    {
        Schema::create('branch_financial_settings',fn(Blueprint $table)=>[$table->id(),$table->unsignedBigInteger('company_id'),$table->unsignedBigInteger('branch_id'),$table->unsignedBigInteger('default_cost_center_id')->nullable(),$table->unsignedBigInteger('default_cash_financial_account_id')->nullable()]);
        Schema::create('cost_centers',fn(Blueprint $table)=>[$table->id(),$table->string('cost_center_code')->nullable(),$table->string('cost_center_name')->nullable()]);
        Schema::create('financial_accounts',fn(Blueprint $table)=>[$table->id(),$table->string('account_name')->nullable()]);
        Schema::create('entity_addresses',fn(Blueprint $table)=>[$table->id(),$table->unsignedBigInteger('company_id'),$table->string('entity_type'),$table->unsignedBigInteger('entity_id'),$table->boolean('is_active')->default(true),$table->boolean('is_default')->default(true)]);

        [$admin,$company]=$this->platformAndCompany();
        $target=DB::table('branches')->insertGetId(['company_id'=>$company,'branch_name'=>'AUTHORIZED_TEST_BRANCH','is_active'=>1]);
        $other=DB::table('branches')->insertGetId(['company_id'=>$company,'branch_name'=>'OTHER_TEST_BRANCH','is_active'=>1]);
        $foreignCompany=DB::table('companies')->insertGetId(['company_name'=>'FOREIGN_TEST_COMPANY','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $foreign=DB::table('branches')->insertGetId(['company_id'=>$foreignCompany,'branch_name'=>'FOREIGN_TEST_BRANCH','is_active'=>1]);

        Sanctum::actingAs($admin,['session','platform-admin']);
        $created=$this->postJson("/api/companies/$company/support-access",['reason'=>'Scoped read-only branch UAT','ticket_reference'=>'UAT-BRANCH-READ','branch_id'=>$target,'expires_at'=>now()->addHour()->toISOString()])->assertOk()->json();
        $this->app['auth']->forgetGuards();

        $this->withToken($created['token'])->getJson('/api/branches')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$target);
        $this->withToken($created['token'])->getJson("/api/branches/$target")->assertOk()->assertJsonPath('data.id',$target);
        $this->withToken($created['token'])->getJson("/api/branches/$other")->assertNotFound();
        $this->withToken($created['token'])->getJson("/api/branches/$foreign")->assertNotFound();
        $this->withToken($created['token'])->postJson('/api/branches',['branch_name'=>'SHOULD_NOT_EXIST'])->assertForbidden()->assertJsonPath('code','SUPPORT_WRITE_DENIED');
        self::assertDatabaseMissing('branches',['branch_name'=>'SHOULD_NOT_EXIST']);
        self::assertDatabaseHas('audit_logs',['support_session_id'=>$created['support_session_id'],'action_type'=>'SUPPORT_WRITE','result'=>'DENIED']);
    }

    public function test_write_requires_capability_and_authorized_attempt_is_audited():void
    {
        $without=$this->createSupport(['access_level'=>'WRITE','capabilities'=>['POST:company-settings']]);
        $this->withToken($without['token'])->postJson('/api/workers',[])->assertForbidden()->assertJsonPath('code','SUPPORT_WRITE_DENIED');
        $this->withToken($without['token'])->postJson('/api/company-settings',['print_company_name'=>'Support Test']);
        self::assertDatabaseHas('audit_logs',['support_session_id'=>$without['support_session_id'],'action_type'=>'SUPPORT_WRITE','ticket_reference'=>'TICKET-1']);
    }

    public function test_expired_revoked_and_exited_sessions_are_immediately_denied():void
    {
        $expired=$this->createSupport();DB::table('support_sessions')->where('support_session_id',$expired['support_session_id'])->update(['expires_at'=>now()->subMinute()]);$this->withToken($expired['token'])->getJson('/api/me')->assertForbidden();self::assertDatabaseHas('support_sessions',['support_session_id'=>$expired['support_session_id'],'status'=>'EXPIRED']);
        $exited=$this->createSupport();$this->withToken($exited['token'])->postJson('/api/support/exit')->assertOk();self::assertDatabaseHas('support_sessions',['support_session_id'=>$exited['support_session_id'],'status'=>'EXITED']);$this->withToken($exited['token'])->getJson('/api/me')->assertForbidden();
        $revoked=$this->createSupport();$platform=$this->platformUserById($revoked['platform_user_id']);Sanctum::actingAs($platform,['session','platform-admin']);$this->postJson('/api/system-admin/support-sessions/'.$revoked['support_session_id'].'/revoke')->assertOk();self::assertDatabaseHas('support_sessions',['support_session_id'=>$revoked['support_session_id'],'status'=>'REVOKED']);$this->app['auth']->forgetGuards();$this->withToken($revoked['token'])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_entry_write_and_exit_audits_share_one_session_id():void
    {
        $created=$this->createSupport();$this->withToken($created['token'])->postJson('/api/workers',[]);$this->withToken($created['token'])->postJson('/api/support/exit');
        $actions=DB::table('audit_logs')->where('support_session_id',$created['support_session_id'])->pluck('action_type');self::assertTrue($actions->contains('SUPPORT_ENTRY'));self::assertTrue($actions->contains('SUPPORT_WRITE'));self::assertTrue($actions->contains('SUPPORT_EXIT'));
    }

    private function createSupport(array $overrides=[]):array
    {
        [$admin,$company]=$this->platformAndCompany();Sanctum::actingAs($admin,['session','platform-admin']);$payload=[...['reason'=>'Diagnostic support','ticket_reference'=>'TICKET-1','expires_at'=>now()->addHour()->toISOString()],...$overrides];
        $data=$this->postJson("/api/companies/$company/support-access",$payload)->assertOk()->json();$this->app['auth']->forgetGuards();return ['token'=>$data['token'],'support_session_id'=>$data['support_session_id'],'company_id'=>$company,'platform_user_id'=>$admin->id];
    }
    private function platformAndCompany():array{$admin=$this->newPlatformUser();$company=DB::table('companies')->insertGetId(['company_name'=>'Tenant','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);return[$admin,$company];}
    private function newPlatformUser():User{$role=DB::table('roles')->insertGetId(['role_name'=>'Platform','role_code'=>'SUPER_ADMIN','is_active'=>1]);$id=DB::table('users')->insertGetId(['company_id'=>null,'name'=>'Platform','username'=>'platform-'.uniqid(),'password'=>Hash::make('password'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);DB::table('user_roles')->insert(['user_id'=>$id,'role_id'=>$role,'company_id'=>null,'is_active'=>1]);return User::findOrFail($id);}
    private function platformUserById(int $id):User{return User::findOrFail($id);}
}
