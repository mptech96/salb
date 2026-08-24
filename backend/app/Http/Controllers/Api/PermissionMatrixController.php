<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\Auth\SessionContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

class PermissionMatrixController extends Controller
{
    private const MANAGERS=['MANAGER','COMPANY_MANAGER','COMPANY_ADMIN','COMPANY_OWNER','ADMIN'];

    public function index(Request $r,AccountingContext $ctx,SessionContextService $sessions)
    {
        $this->assertManager($r);$cid=$ctx->companyId($r);
        $users=DB::table('users as u')->leftJoin('user_roles as ur',fn($j)=>$j->on('ur.user_id','=','u.id')->where('ur.is_active','=',1))->leftJoin('roles as ro','ro.id','=','ur.role_id')->leftJoin('branches as b','b.id','=','u.branch_id')->where('u.company_id',$cid)->select('u.id','u.name','u.username','u.branch_id','u.is_active','b.branch_name','ro.role_code','ro.role_name')->orderBy('u.name')->get();
        $permissions=DB::table('permissions')->where('permission_scope','COMPANY')->select('id','permission_name','permission_code','module_name')->orderBy('module_name')->orderBy('permission_name')->get();
        foreach($users as$u)$u->effective_permissions=$sessions->permissionsForUser((int)$u->id,$cid)->all();
        return response()->json(['status'=>true,'data'=>['users'=>$users,'permissions'=>$permissions]]);
    }

    public function show(Request $r,int $userId,AccountingContext $ctx,SessionContextService $sessions)
    {
        $this->assertManager($r);$cid=$ctx->companyId($r);$user=DB::table('users')->where('company_id',$cid)->where('id',$userId)->first();if(!$user)return response()->json(['status'=>false,'message'=>'المستخدم غير موجود داخل الشركة.'],404);
        $role=$sessions->roleForUser($userId);$base=$this->basePermissions($userId,$cid);$over=DB::table('user_permission_overrides as o')->join('permissions as p','p.id','=','o.permission_id')->where('o.company_id',$cid)->where('o.user_id',$userId)->where('p.permission_scope','COMPANY')->get(['p.permission_code','o.effect']);
        return response()->json(['status'=>true,'data'=>['user'=>$user,'role'=>$role,'base_permissions'=>$base,'effective_permissions'=>$sessions->permissionsForUser($userId,$cid)->all(),'overrides'=>$over]]);
    }

    public function update(Request $r,int $userId,AccountingContext $ctx)
    {
        $this->assertManager($r);$v=$r->validate(['overrides'=>'required|array|max:500','overrides.*'=>'nullable|in:ALLOW,DENY,INHERIT']);$cid=$ctx->companyId($r);if(!DB::table('users')->where('company_id',$cid)->where('id',$userId)->exists())return response()->json(['status'=>false,'message'=>'المستخدم غير موجود داخل الشركة.'],404);
        if($userId===$ctx->userId($r))return response()->json(['status'=>false,'message'=>'لا تعدّل صلاحيات حساب المدير الحالي من نفس الجلسة حفاظًا على الوصول الإداري.'],422);
        $actorId=$ctx->userId($r);DB::transaction(function()use($v,$cid,$userId,$actorId){foreach($v['overrides']as$code=>$effect){$pid=DB::table('permissions')->where('permission_scope','COMPANY')->where('permission_code',$code)->value('id');if(!$pid)continue;$effect=strtoupper((string)($effect?:'INHERIT'));if($effect==='INHERIT'){DB::table('user_permission_overrides')->where('company_id',$cid)->where('user_id',$userId)->where('permission_id',$pid)->delete();continue;}DB::table('user_permission_overrides')->updateOrInsert(['company_id'=>$cid,'user_id'=>$userId,'permission_id'=>$pid],['effect'=>$effect,'granted_by'=>$actorId,'updated_at'=>now(),'created_at'=>now()]);}});
        return response()->json(['status'=>true,'message'=>'تم تحديث صلاحيات الإجراءات للمستخدم.']);
    }

    private function basePermissions(int$userId,int$cid): array{return DB::table('user_roles as ur')->join('role_permissions as rp','rp.role_id','=','ur.role_id')->join('permissions as p','p.id','=','rp.permission_id')->where('ur.user_id',$userId)->where('ur.is_active',1)->where('rp.is_active',1)->where('p.permission_scope','COMPANY')->where(fn($q)=>$q->whereNull('rp.company_id')->orWhere('rp.company_id',$cid))->pluck('p.permission_code')->unique()->values()->all();}
    private function assertManager(Request$r): void{$role=strtoupper((string)$r->attributes->get('effective_role_code',''));$support=(bool)$r->attributes->get('is_support_mode',false);if(!$support&&!in_array($role,self::MANAGERS,true))throw new HttpResponseException(response()->json(['status'=>false,'message'=>'إدارة الصلاحيات التفصيلية متاحة للمستخدم الرئيسي/مدير الشركة فقط.'],403));}
}
