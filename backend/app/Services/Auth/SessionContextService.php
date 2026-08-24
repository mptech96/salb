<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use App\Services\Entitlement\EffectiveEntitlementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SessionContextService
{
    private const COMPANY_MANAGER_ROLES=['MANAGER','COMPANY_MANAGER','COMPANY_ADMIN','COMPANY_OWNER','ADMIN'];

    public function roleForUser(int $userId): ?object
    {
        return DB::table('user_roles as ur')->join('users as u','u.id','=','ur.user_id')->join('roles as r','r.id','=','ur.role_id')
            ->where('ur.user_id',$userId)->where(fn($q)=>$q->whereNull('ur.company_id')->orWhereColumn('ur.company_id','u.company_id'))->where('ur.is_active',1)->where('r.is_active',1)
            ->select('r.id','r.role_name','r.role_code')->orderByDesc('ur.id')->first();
    }

    public function permissionsForUser(int $userId,?int $companyId): Collection
    {
        $role=$this->roleForUser($userId);
        if($role&&in_array(strtoupper((string)$role->role_code),self::COMPANY_MANAGER_ROLES,true))return $this->allPermissions();

        $baseline=DB::table('user_roles as ur')->join('users as u','u.id','=','ur.user_id')->join('role_permissions as rp','rp.role_id','=','ur.role_id')->join('permissions as p','p.id','=','rp.permission_id')
            ->where('ur.user_id',$userId)->where('ur.is_active',1)->where(fn($q)=>$q->whereNull('ur.company_id')->orWhereColumn('ur.company_id','u.company_id'))->where('rp.is_active',1)
            ->when($companyId,fn($q)=>$q->where(fn($x)=>$x->whereNull('rp.company_id')->orWhere('rp.company_id',$companyId)),fn($q)=>$q->whereNull('rp.company_id'))
            ->pluck('p.permission_code')->unique()->values();

        if(!$companyId||!Schema::hasTable('user_permission_overrides'))return $baseline;
        $overrides=DB::table('user_permission_overrides as o')->join('permissions as p','p.id','=','o.permission_id')->where('o.company_id',$companyId)->where('o.user_id',$userId)->get(['p.permission_code','o.effect']);
        $set=array_fill_keys($baseline->all(),true);
        foreach($overrides as$o){if(strtoupper((string)$o->effect)==='DENY')unset($set[$o->permission_code]);else$set[$o->permission_code]=true;}
        return collect(array_keys($set))->values();
    }

    public function __construct(
        private SubscriptionLifecycleService $subscriptions,
        private SubscriptionAccessModeResolver $accessModes,
        private EffectiveEntitlementService $entitlements,
    ) {
    }

    public function allPermissions(): Collection{return DB::table('permissions')->orderBy('id')->pluck('permission_code')->unique()->values();}
    public function effectiveSubscription(int $companyId): ?object{return $this->subscriptions->effectiveForCompany($companyId);}
    public function latestSubscription(int $companyId): ?object{return $this->effectiveSubscription($companyId);}

    public function userPayload(User $user): array
    {
        $role=$this->roleForUser((int)$user->id);$roleCode=strtoupper((string)($role->role_code??''));$isPlatformAdmin=$roleCode==='SUPER_ADMIN';
        $companyId=$isPlatformAdmin?null:($user->company_id?(int)$user->company_id:null);$branchId=$isPlatformAdmin?null:($user->branch_id?(int)$user->branch_id:null);
        $company=$companyId?DB::table('companies')->where('id',$companyId)->first():null;$branch=$branchId?DB::table('branches')->where('id',$branchId)->where('company_id',$companyId)->first():null;
        $permissions=$role?$this->permissionsForUser((int)$user->id,$companyId):collect();
        return ['id'=>(int)$user->id,'company_id'=>$companyId,'branch_id'=>$branchId,'name'=>$user->name,'username'=>$user->username,'email'=>$user->email,'phone'=>$user->phone,'company_name'=>$isPlatformAdmin?'إدارة منصة صلب':($company->company_name??null),'branch_name'=>$isPlatformAdmin?'مركز التحكم':($branch->branch_name??null),'role'=>$role?['id'=>(int)$role->id,'role_name'=>$role->role_name,'role_code'=>$role->role_code]:null,'permissions'=>$permissions->all(),'is_support_mode'=>false,'platform_admin_id'=>$isPlatformAdmin?(int)$user->id:null];
    }

    public function supportPayload(User $platformAdmin,int $companyId,?int $branchId): array
    {
        $company=DB::table('companies')->where('id',$companyId)->first();$q=DB::table('branches')->where('company_id',$companyId);if($branchId)$q->where('id',$branchId);else$q->orderByDesc('is_active')->orderBy('id');$branch=$q->first();
        return ['id'=>(int)$platformAdmin->id,'company_id'=>$companyId,'branch_id'=>$branch?->id?(int)$branch->id:null,'name'=>$platformAdmin->name,'username'=>$platformAdmin->username,'email'=>$platformAdmin->email,'phone'=>$platformAdmin->phone,'company_name'=>$company->company_name??'شركة غير معروفة','branch_name'=>$branch->branch_name??'بدون فرع محدد','role'=>['id'=>null,'role_name'=>'دعم فني للمنصة','role_code'=>'MANAGER'],'permissions'=>$this->allPermissions()->all(),'is_support_mode'=>true,'actual_role_code'=>'SUPER_ADMIN','platform_admin_id'=>(int)$platformAdmin->id,'support_company_id'=>$companyId];
    }

    public function subscriptionPayload(?object $s): ?array
    {if(!$s)return null;$effective=isset($s->company_id)?$this->entitlements->resolve((int)$s->company_id):null;return ['id'=>isset($s->id)?(int)$s->id:null,'plan_name'=>$s->plan_name??null,'plan_code'=>$s->plan_code??null,'start_date'=>$s->start_date??null,'end_date'=>$s->end_date??null,'max_branches'=>isset($s->max_branches)?(int)$s->max_branches:null,'max_users'=>isset($s->max_users)?(int)$s->max_users:null,'max_cars'=>isset($s->max_cars)?(int)$s->max_cars:null,'max_invoices'=>isset($s->max_invoices)?(int)$s->max_invoices:null,'status'=>$s->effective_status??$s->status??null,'stored_status'=>$s->stored_status??$s->status??null,'access_mode'=>$this->accessModes->resolve($s),'effective_entitlements'=>$effective];}
}
