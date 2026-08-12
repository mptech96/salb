<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Repositories\AccountRepository;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(private AccountRepository $accounts) {}
    public function create(array $data): int
    {
        return DB::transaction(function() use($data){$cid=(int)$data['company_id'];$code=trim((string)$data['account_code']);if($this->accounts->findByCode($cid,$code))throw new \RuntimeException('رقم الحساب مستخدم مسبقًا.');$level=1;if(!empty($data['parent_id'])){$p=$this->accounts->find($cid,(int)$data['parent_id']);if(!$p)throw new \RuntimeException('الحساب الأب غير موجود.');if(!(bool)$p->is_group)throw new \RuntimeException('لا يمكن إضافة حساب أسفل حساب تحليلي.');$level=(int)$p->account_level+1;}$data['account_code']=$code;$data['account_level']=$level;$data['allow_posting']=(int)($data['is_group']??0)===1?0:1;return $this->accounts->create($data);});
    }
    public function tree(int $companyId){return $this->accounts->tree($companyId);} public function postingAccounts(int $companyId){return $this->accounts->movementAccounts($companyId);}
}
