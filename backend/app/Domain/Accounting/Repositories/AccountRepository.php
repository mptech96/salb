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
        return DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->orderBy('account_code')->get();
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
