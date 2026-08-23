<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PartyBranchScopeService
{
    public function sync(int $companyId,string $kind,int $partyId,bool $allBranches,array $branchIds=[],?int $defaultBranchId=null): void
    {
        $kind=strtoupper($kind); [$table,$link,$fk]=$kind==='SUPPLIER'?['suppliers','supplier_branches','supplier_id']:['customers','customer_branches','customer_id'];
        $branchIds=array_values(array_unique(array_filter(array_map('intval',$branchIds))));
        $valid=DB::table('branches')->where('company_id',$companyId)->whereIn('id',$branchIds)->pluck('id')->map(fn($x)=>(int)$x)->all();
        if($defaultBranchId && !DB::table('branches')->where('company_id',$companyId)->where('id',$defaultBranchId)->exists())throw new \RuntimeException('الفرع الافتراضي لا يتبع الشركة.');
        if(!$allBranches && !$valid && !$defaultBranchId)throw new \RuntimeException('حدد فرعًا واحدًا على الأقل أو اختر جميع الفروع.');
        if($defaultBranchId && !$allBranches && !in_array($defaultBranchId,$valid,true))$valid[]=$defaultBranchId;

        DB::table($table)->where('company_id',$companyId)->where('id',$partyId)->update(['scope_all_branches'=>$allBranches?1:0,'default_branch_id'=>$defaultBranchId,'branch_id'=>$defaultBranchId ?: ($valid[0]??null),'updated_at'=>now()]);
        DB::table($link)->where('company_id',$companyId)->where($fk,$partyId)->delete();
        if(!$allBranches)foreach(array_unique($valid) as $bid)DB::table($link)->insert(['company_id'=>$companyId,$fk=>$partyId,'branch_id'=>$bid,'is_default'=>$defaultBranchId===$bid?1:0,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
    }

    public function assertAccessible(int $companyId,string $kind,int $partyId,int $branchId): object
    {
        $kind=strtoupper($kind); [$table,$link,$fk]=$kind==='SUPPLIER'?['suppliers','supplier_branches','supplier_id']:['customers','customer_branches','customer_id'];
        $party=DB::table($table)->where('company_id',$companyId)->where('id',$partyId)->where('is_active',1)->first();
        if(!$party)throw new \RuntimeException(($kind==='SUPPLIER'?'المورد':'العميل').' غير موجود أو غير نشط.');
        if((int)($party->scope_all_branches??0)===1)return $party;
        $ok=DB::table($link)->where('company_id',$companyId)->where($fk,$partyId)->where('branch_id',$branchId)->where('is_active',1)->exists();
        if(!$ok)throw new \RuntimeException(($kind==='SUPPLIER'?'المورد':'العميل').' غير مفعّل لهذا الفرع.');
        return $party;
    }

    public function scopeQuery($query,int $companyId,string $kind,?int $branchId)
    {
        if($branchId===null)return $query;
        $kind=strtoupper($kind); [$alias,$link,$fk]=$kind==='SUPPLIER'?['s','supplier_branches','supplier_id']:['s','customer_branches','customer_id'];
        return $query->where(function($q)use($companyId,$alias,$link,$fk,$branchId){
            $q->where($alias.'.scope_all_branches',1)->orWhereExists(function($x)use($companyId,$alias,$link,$fk,$branchId){
                $x->selectRaw('1')->from($link.' as pb')->whereColumn('pb.'.$fk,$alias.'.id')->where('pb.company_id',$companyId)->where('pb.branch_id',$branchId)->where('pb.is_active',1);
            });
        });
    }

    public function branchIds(int $companyId,string $kind,int $partyId): array
    {
        $kind=strtoupper($kind); [$link,$fk]=$kind==='SUPPLIER'?['supplier_branches','supplier_id']:['customer_branches','customer_id'];
        return DB::table($link)->where('company_id',$companyId)->where($fk,$partyId)->where('is_active',1)->pluck('branch_id')->map(fn($x)=>(int)$x)->all();
    }
}
