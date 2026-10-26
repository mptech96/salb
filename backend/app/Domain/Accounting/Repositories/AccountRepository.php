<?php

namespace App\Domain\Accounting\Repositories;

use Illuminate\Support\Facades\DB;

class AccountRepository
{
    public function find(int $companyId, int $accountId)
    {
        return DB::table('accounts')->where('company_id',$companyId)->where('id',$accountId)->first();
    }

    public function findByCode(int $companyId, string $code)
    {
        return DB::table('accounts')->where('company_id',$companyId)->where('account_code',$code)->first();
    }

    public function movementAccounts(int $companyId)
    {
        return DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->orderBy('account_code')->get();
    }

    public function tree(int $companyId)
    {
        $rows = DB::table('accounts')->where('company_id',$companyId)->orderBy('account_code')->get();
        $map = $rows->keyBy('id');
        foreach ($rows as $row) {
            $parts=[]; $seen=[]; $current=$row;
            while ($current && !isset($seen[$current->id])) {
                $seen[$current->id]=true;
                array_unshift($parts, $current->account_code.' — '.$current->account_name);
                $current=$current->parent_id ? $map->get($current->parent_id) : null;
            }
            $row->account_path=implode(' / ', $parts);
        }
        return $rows;
    }

    public function descendantIds(int $companyId, int $root): array
    {
        $children=[];
        foreach (DB::table('accounts')->where('company_id',$companyId)->get(['id','parent_id']) as $row) $children[(int)$row->parent_id][]=(int)$row->id;
        $pending=[$root]; $seen=[];
        while ($pending) {
            $id=array_pop($pending);
            if(isset($seen[$id])) continue;
            $seen[$id]=true;
            foreach ($children[$id]??[] as $child) $pending[]=$child;
        }
        return array_keys($seen);
    }

    public function selectionPage(int $companyId, array $filters = [])
    {
        $query=DB::table('accounts')->where('company_id',$companyId);
        if (!empty($filters['account_type'])) $query->where('account_type',$filters['account_type']);
        if (($filters['mode']??'POSTING')==='POSTING') $query->where('is_active',1)->where('is_group',0)->where('allow_posting',1);
        if (!empty($filters['selected_id'])) $query->where('id',(int)$filters['selected_id']);
        $search=trim((string)($filters['search']??''));
        if ($search!=='') {
            // Search ancestors too, without hydrating the complete company chart.
            $pending=DB::table('accounts')->where('company_id',$companyId)
                ->where(fn($q)=>$q->where('account_code','like','%'.$search.'%')->orWhere('account_name','like','%'.$search.'%'))
                ->pluck('id')->all();
            $seen=[];
            while ($pending) {
                $fresh=array_values(array_filter($pending,fn($id)=>!isset($seen[$id])));
                if (!$fresh) break;
                foreach ($fresh as $id) $seen[$id]=true;
                $pending=[];
                foreach (array_chunk($fresh,400) as $chunk) {
                    $pending=array_merge($pending,DB::table('accounts')->where('company_id',$companyId)->whereIn('parent_id',$chunk)->pluck('id')->all());
                }
            }
            $query->whereIn('id',array_keys($seen));
        }
        $result=$query->orderBy('account_code')->paginate(min(100,max(1,(int)($filters['per_page']??25))),['*'],'page',max(1,(int)($filters['page']??1)));
        $map=$result->getCollection()->keyBy('id');
        $pending=$result->getCollection()->pluck('parent_id')->filter()->unique()->values()->all();
        while ($pending) {
            $missing=array_values(array_filter($pending,fn($id)=>!$map->has($id)));
            if (!$missing) break;
            $parents=DB::table('accounts')->where('company_id',$companyId)->whereIn('id',$missing)->get();
            if ($parents->isEmpty()) break;
            foreach ($parents as $parent) $map->put($parent->id,$parent);
            $pending=$parents->pluck('parent_id')->filter()->unique()->values()->all();
        }
        foreach ($result->getCollection() as $row) {
            $parts=[]; $visited=[]; $current=$row;
            while ($current && !isset($visited[$current->id])) {
                $visited[$current->id]=true;
                array_unshift($parts,$current->account_code.' — '.$current->account_name);
                $current=$current->parent_id ? $map->get($current->parent_id) : null;
            }
            $row->account_path=implode(' / ',$parts);
        }
        return $result;
    }

    public function create(array $d): int
    {
        return DB::table('accounts')->insertGetId([
            'company_id'=>$d['company_id'],'parent_id'=>$d['parent_id']??null,'account_code'=>trim($d['account_code']),
            'account_name'=>trim($d['account_name']),'account_type'=>$d['account_type'],'normal_side'=>$d['normal_side'],
            'account_level'=>$d['account_level']??1,'is_group'=>(int)($d['is_group']??0),
            'allow_posting'=>(int)($d['allow_posting']??((int)($d['is_group']??0)===1?0:1)),
            'allow_cost_center'=>(int)($d['allow_cost_center']??0),'is_active'=>(int)($d['is_active']??1),
            'notes'=>$d['notes']??null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
