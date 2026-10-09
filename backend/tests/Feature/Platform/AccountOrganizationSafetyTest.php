<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Wave1SubscriptionTestCase;

class AccountOrganizationSafetyTest extends Wave1SubscriptionTestCase
{
    private function platformUser(): int
    {
        $role = DB::table('roles')->insertGetId(['role_name'=>'Platform','role_code'=>'SUPER_ADMIN','is_active'=>1]);
        $id = DB::table('users')->insertGetId(['name'=>'Test platform','username'=>'test-platform','password'=>Hash::make('old-password'),'is_active'=>1]);
        DB::table('user_roles')->insert(['user_id'=>$id,'role_id'=>$role,'company_id'=>null,'is_active'=>1]);
        Sanctum::actingAs(User::findOrFail($id), ['session']);
        return $id;
    }

    public function test_platform_password_change_needs_no_company_and_ignores_organizational_fields(): void
    {
        $tenant = $this->companyUserWithSubscription('ACTIVE');
        $id = $this->platformUser();
        $roles = DB::table('user_roles')->get()->toJson();
        $tenantBefore = (array) DB::table('users')->where('id',$tenant['userId'])->first();
        $this->postJson('/api/profile/password', ['current_password'=>'old-password','password'=>'new-password','password_confirmation'=>'new-password','company_id'=>$tenant['companyId'],'branch_id'=>$tenant['branchId'],'role_id'=>$tenant['roleId']])->assertOk();
        $user = DB::table('users')->where('id',$id)->first();
        self::assertTrue(Hash::check('new-password',$user->password));
        self::assertNull($user->company_id);
        self::assertNull($user->branch_id);
        self::assertSame($roles, DB::table('user_roles')->get()->toJson());
        self::assertSame($tenantBefore,(array) DB::table('users')->where('id',$tenant['userId'])->first());
    }

    public function test_company_password_change_preserves_company_branch_and_roles(): void
    {
        $tenant = $this->companyUserWithSubscription('ACTIVE');
        Sanctum::actingAs(User::findOrFail($tenant['userId']), ['session']);
        $roles = DB::table('user_roles')->get()->toJson();
        $this->postJson('/api/profile/password', ['current_password'=>'password123','password'=>'new-password','password_confirmation'=>'new-password','company_id'=>999,'branch_id'=>999])->assertOk();
        self::assertDatabaseHas('users',['id'=>$tenant['userId'],'company_id'=>$tenant['companyId'],'branch_id'=>$tenant['branchId']]);
        self::assertSame($roles, DB::table('user_roles')->get()->toJson());
    }

    public function test_wrong_current_password_cannot_change_password_or_organization(): void
    {
        $id = $this->platformUser();
        $before = (array) DB::table('users')->where('id',$id)->first();
        $this->postJson('/api/profile/password', ['current_password'=>'incorrect-password','password'=>'new-password','password_confirmation'=>'new-password','company_id'=>999,'branch_id'=>999])->assertStatus(422)->assertJsonPath('code','CURRENT_PASSWORD_INVALID');
        self::assertSame($before,(array) DB::table('users')->where('id',$id)->first());
    }

    public function test_platform_listing_is_not_filtered_or_mutated_by_client_company_switch(): void
    {
        $tenant = $this->companyUserWithSubscription('ACTIVE');
        $id = $this->platformUser();
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        foreach ([1,999] as $company) {
            $response=$this->withHeaders(['X-Company-ID'=>(string)$company,'X-Branch-ID'=>'999'])->getJson('/api/users?company_id='.$company)->assertOk();
            self::assertContains($tenant['userId'],array_column($response->json('data'),'id'));
            self::assertContains($id,array_column($response->json('data'),'id'));
        }
        self::assertSame($before,DB::table('users')->orderBy('id')->get()->toJson());
    }

    public function test_platform_edit_cannot_transfer_company_even_when_password_is_present(): void
    {
        $tenant = $this->companyUserWithSubscription('ACTIVE');
        $other=DB::table('companies')->insertGetId(['company_name'=>'Other','is_active'=>1]);
        $branch=DB::table('branches')->insertGetId(['company_id'=>$other,'branch_name'=>'Other','is_active'=>1]);
        $this->platformUser();
        $before=(array) DB::table('users')->where('id',$tenant['userId'])->first();
        $this->putJson('/api/users/'.$tenant['userId'],['company_id'=>$other,'branch_id'=>$branch,'password'=>'new-password'])->assertStatus(422)->assertJsonPath('code','USER_COMPANY_CHANGE_BLOCKED');
        self::assertSame($before,(array) DB::table('users')->where('id',$tenant['userId'])->first());
    }

    public function test_platform_admin_cannot_attach_own_account_to_company_with_manual_api_request(): void
    {
        $tenant = $this->companyUserWithSubscription('ACTIVE');
        $id = $this->platformUser();
        $before = (array) DB::table('users')->where('id',$id)->first();
        $roles = DB::table('user_roles')->orderBy('id')->get()->toJson();
        $this->putJson('/api/users/'.$id, [
            'company_id'=>$tenant['companyId'], 'branch_id'=>$tenant['branchId'],
            'role_id'=>$tenant['roleId'], 'name'=>'Test platform', 'username'=>'test-platform',
            'password'=>'new-password', 'is_active'=>1,
        ])->assertStatus(422)->assertJsonPath('code','USER_COMPANY_CHANGE_BLOCKED');
        self::assertSame($before,(array) DB::table('users')->where('id',$id)->first());
        self::assertSame($roles,DB::table('user_roles')->orderBy('id')->get()->toJson());
    }
}
