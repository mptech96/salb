<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
final class UomActorFixture
{
    public static function login():void
    {
        foreach(['items.view','items.update'] as $code)DB::table('permissions')->insertOrIgnore(['permission_code'=>$code,'permission_name'=>$code]);
        $role=DB::table('roles')->where('role_code','MANAGER')->value('id')??DB::table('roles')->insertGetId(['role_name'=>'Test manager','role_code'=>'MANAGER']);
        $id=DB::table('users')->insertGetId(['company_id'=>1,'branch_id'=>1,'name'=>'Synthetic UOM','username'=>'uom-test','password'=>'not-a-real-password']);
        DB::table('user_roles')->insert(['company_id'=>1,'user_id'=>$id,'role_id'=>$role]);Auth::login(\App\Models\User::findOrFail($id));
    }
}
