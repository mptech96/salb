<?php

namespace App\Services\Accounting;

use App\Services\FinancialAccountService;
use Illuminate\Support\Facades\DB;

class PostingSupport
{
    public function __construct(private FinancialAccountService $financialAccounts) {}

    public function setting(int $companyId, string $key): int
    {
        $id=DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key',$key)->value('account_id');
        if(!$id)throw new \RuntimeException('الحساب الافتراضي غير مضبوط: '.$key);
        return(int)$id;
    }

    public function financialAccount(int $companyId,int $branchId,?string $method=null,?int $explicitFinancialAccountId=null,string $direction='PAYMENT'): object
    {
        return $this->financialAccounts->resolve($companyId,$branchId,$method,$explicitFinancialAccountId,$direction);
    }

    public function cashAccount(int $companyId, ?string $method, ?int $explicitId = null, ?int $branchId = null): int
    {
        if($explicitId){
            $fa=DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$explicitId)->where('is_active',1)->first();
            if($fa)return(int)$fa->gl_account_id;
            $ok=DB::table('accounts')->where('company_id',$companyId)->where('id',$explicitId)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->exists();
            if(!$ok)throw new \RuntimeException('حساب النقد/البنك المحدد غير صالح.');
            return$explicitId;
        }
        if($branchId){
            try{return(int)$this->financialAccounts->resolve($companyId,$branchId,$method,null,'PAYMENT')->gl_account_id;}catch(\Throwable){}
        }
        $method=strtoupper((string)$method);
        return $this->setting($companyId,in_array($method,['BANK','CARD','BANK_TRANSFER','TRANSFER'],true)?'BANK_ACCOUNT':'CASH_ACCOUNT');
    }

    public function branchCostCenter(int $companyId, ?int $branchId): ?int
    {
        if(!$branchId)return null;
        $id=DB::table('branch_financial_settings')->where('company_id',$companyId)->where('branch_id',$branchId)->value('default_cost_center_id');
        if(!$id)$id=DB::table('cost_centers')->where('company_id',$companyId)->where('branch_id',$branchId)->where('is_active',1)->orderBy('id')->value('id');
        return$id?(int)$id:null;
    }
}
