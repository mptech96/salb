<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\Auth\SessionContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Wave1SubscriptionTestCase;

class PlatformRoleIsolationTest extends Wave1SubscriptionTestCase
{
    public function test_company_owner_is_denied_platform_api():void{$this->assertTenantRoleDenied('COMPANY_OWNER');}
    public function test_company_admin_is_denied_platform_api():void{$this->assertTenantRoleDenied('COMPANY_ADMIN');}
    public function test_manager_is_denied_platform_api():void{$this->assertTenantRoleDenied('MANAGER');}

    public function test_every_company_permission_does_not_grant_platform_authority():void
    {
        $x=$this->tenantUser('COMPANY_OWNER');$roleId=$x['roleId'];
        foreach([['company.read','COMPANY'],['platform.audit.read','PLATFORM'],['platform.plans.write','PLATFORM']]as[$code,$scope]){$p=DB::table('permissions')->insertGetId(['permission_code'=>$code,'permission_scope'=>$scope]);DB::table('role_permissions')->insert(['role_id'=>$roleId,'permission_id'=>$p,'company_id'=>$x['companyId'],'is_active'=>1]);}
        Sanctum::actingAs(User::findOrFail($x['userId']),['session']);$this->getJson('/api/system-admin/features')->assertForbidden();
        self::assertSame(['company.read'],app(SessionContextService::class)->permissionsForUser($x['userId'],$x['companyId'])->all());
    }

    public function test_genuine_platform_admin_is_allowed():void
    {
        $user=$this->platformUser();Sanctum::actingAs($user,['session','platform-admin']);$this->getJson('/api/system-admin/features')->assertOk();
    }

    public function test_super_admin_linked_to_company_is_not_platform_authority():void
    {
        $x=$this->tenantUser('SUPER_ADMIN');Sanctum::actingAs(User::findOrFail($x['userId']),['session','platform-admin']);$this->getJson('/api/system-admin/features')->assertForbidden();
    }

    public function test_platform_permissions_never_appear_in_tenant_session_context():void
    {
        $x=$this->tenantUser('MANAGER');DB::table('permissions')->insert([['permission_code'=>'items.view','permission_scope'=>'COMPANY'],['permission_code'=>'platform.audit.read','permission_scope'=>'PLATFORM']]);
        self::assertSame(['items.view'],app(SessionContextService::class)->permissionsForUser($x['userId'],$x['companyId'])->all());
    }

    private function assertTenantRoleDenied(string $role):void{$x=$this->tenantUser($role);Sanctum::actingAs(User::findOrFail($x['userId']),['session']);$this->getJson('/api/system-admin/features')->assertForbidden();}
    private function tenantUser(string $roleCode):array{$companyId=DB::table('companies')->insertGetId(['company_name'=>'T','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);$roleId=DB::table('roles')->insertGetId(['role_name'=>$roleCode,'role_code'=>$roleCode,'is_active'=>1]);$userId=DB::table('users')->insertGetId(['company_id'=>$companyId,'name'=>$roleCode,'username'=>strtolower($roleCode).$companyId,'password'=>Hash::make('password'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);DB::table('user_roles')->insert(['company_id'=>$companyId,'user_id'=>$userId,'role_id'=>$roleId,'is_active'=>1]);return compact('companyId','roleId','userId');}
    private function platformUser():User{$role=DB::table('roles')->insertGetId(['role_name'=>'Platform','role_code'=>'SUPER_ADMIN','is_active'=>1]);$id=DB::table('users')->insertGetId(['company_id'=>null,'name'=>'Platform','username'=>'platform','password'=>Hash::make('password'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);DB::table('user_roles')->insert(['company_id'=>null,'user_id'=>$id,'role_id'=>$role,'is_active'=>1]);return User::findOrFail($id);}
}
