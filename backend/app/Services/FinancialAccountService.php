<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialAccountService
{
    public function list(int $companyId, ?int $branchId = null, array $filters = [])
    {
        $perPage = min(100, max(25, (int)($filters['per_page'] ?? 25)));
        $search = trim((string)($filters['search'] ?? ''));
        return DB::table('financial_accounts as f')
            ->join('accounts as a','a.id','=','f.gl_account_id')
            ->leftJoin('branches as b','b.id','=','f.branch_id')
            ->where('f.company_id',$companyId)
            ->when($branchId !== null, fn($q) => $q->where(function($x) use ($branchId) { $x->where('f.branch_id',$branchId)->orWhereNull('f.branch_id'); }))
            ->when(isset($filters['branch_id']) && $filters['branch_id'] !== '', fn($q) => $q->where('f.branch_id',(int)$filters['branch_id']))
            ->when(($filters['scope']??null)==='CENTRAL',fn($q)=>$q->whereNull('f.branch_id'))
            ->when(isset($filters['account_type']) && $filters['account_type'] !== '', fn($q) => $q->where('f.account_type',strtoupper((string)$filters['account_type'])))
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', fn($q) => $q->where('f.is_active',(int)$filters['is_active']))
            ->when($search !== '', function($q) use($search){$like='%'.$search.'%';$q->where(function($x)use($like){$x->where('f.account_code','like',$like)->orWhere('f.account_name','like',$like)->orWhere('a.account_code','like',$like)->orWhere('a.account_name','like',$like);});})
            ->select('f.*','a.account_code as gl_account_code','a.account_name as gl_account_name','b.branch_name')
            ->orderByRaw('CASE WHEN f.branch_id IS NULL THEN 0 ELSE 1 END')->orderBy('b.branch_name')->orderBy('f.account_type')->orderBy('f.account_name')->orderBy('f.id')
            ->paginate($perPage,['*'],'page',(int)($filters['page']??1));
    }

    public function save(int $companyId, array $data, ?int $id = null): int
    {
        $branchId = isset($data['branch_id']) && (int)$data['branch_id'] > 0 ? (int)$data['branch_id'] : null;
        if ($branchId && !DB::table('branches')->where('company_id',$companyId)->where('id',$branchId)->exists()) throw new \RuntimeException('الفرع المحدد لا يتبع الشركة.');

        $type = strtoupper(trim((string)($data['account_type'] ?? 'CASH')));
        if (!in_array($type,['CASH','BANK','WALLET','PETTY_CASH','OTHER'],true)) throw new \RuntimeException('نوع الحساب المالي غير مدعوم.');

        $gl = DB::table('accounts')->where('company_id',$companyId)->where('id',(int)($data['gl_account_id'] ?? 0))
            ->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->first();
        if (!$gl) throw new \RuntimeException('حساب الأستاذ المرتبط غير صالح للترحيل.');
        if (strtoupper((string)($gl->account_type ?? '')) !== 'ASSET') throw new \RuntimeException('الخزائن والبنوك والمحافظ يجب أن ترتبط بحساب أصل مالي في دفتر الأستاذ.');

        $currency = strtoupper(trim((string)($data['currency_code'] ?? $this->baseCurrency($companyId))));
        if ($currency === '') throw new \RuntimeException('العملة مطلوبة.');
        $this->ensureCompanyCurrency($companyId,$currency);

        $code = strtoupper(trim((string)($data['account_code'] ?? '')));
        if ($code === '') $code = $this->nextCode($companyId,$type,$branchId);
        $name = trim((string)($data['account_name'] ?? ''));
        if (mb_strlen($name) < 2) throw new \RuntimeException('اسم الخزينة/الحساب المالي مطلوب.');

        $duplicate = DB::table('financial_accounts')->where('company_id',$companyId)->where('account_code',$code)
            ->when($id,fn($q)=>$q->where('id','<>',$id))->exists();
        if ($duplicate) throw new \RuntimeException('كود الحساب المالي مستخدم داخل الشركة.');

        return DB::transaction(function() use($companyId,$branchId,$type,$gl,$currency,$code,$name,$data,$id) {
            $payload = [
                'company_id'=>$companyId,'branch_id'=>$branchId,'account_code'=>$code,'account_name'=>$name,'account_type'=>$type,
                'gl_account_id'=>$gl->id,'currency_code'=>$currency,'bank_name'=>$data['bank_name']??null,'account_number'=>$data['account_number']??null,
                'iban'=>$data['iban']??null,'wallet_provider'=>$data['wallet_provider']??null,'is_default_receipt'=>(int)($data['is_default_receipt']??0),
                'is_default_payment'=>(int)($data['is_default_payment']??0),'is_active'=>(int)($data['is_active']??1),'notes'=>$data['notes']??null,'updated_at'=>now()
            ];
            if (!(int)$payload['is_active']) {
                $payload['is_default_receipt']=0;
                $payload['is_default_payment']=0;
            }
            if ($id) {
                $exists = DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$id)->first();
                if (!$exists) throw new \RuntimeException('الحساب المالي غير موجود.');
                $used=DB::table('journal_entry_lines')->where('company_id',$companyId)->where('financial_account_id',$id)->exists();
                if($used&&((int)$exists->gl_account_id!==(int)$gl->id||strtoupper((string)$exists->currency_code)!==$currency||(int)($exists->branch_id??0)!==(int)($branchId??0)))
                    throw new \RuntimeException('لا يمكن تغيير حساب الأستاذ أو العملة أو الفرع بعد وجود حركات تاريخية على الحساب المالي.');
                DB::table('financial_accounts')->where('id',$id)->update($payload); $faId=$id;
            } else { $payload['created_at']=now(); $faId=DB::table('financial_accounts')->insertGetId($payload); }

            if (!(int)$payload['is_active']) {
                foreach(['default_cash_financial_account_id','default_bank_financial_account_id','default_wallet_financial_account_id'] as $column)
                    DB::table('branch_financial_settings')->where('company_id',$companyId)->where($column,$faId)->update([$column=>null,'updated_at'=>now()]);
            }
            if ($branchId && (int)$payload['is_active']) {
                if ((int)$payload['is_default_receipt'] === 1) DB::table('financial_accounts')->where('company_id',$companyId)->where('branch_id',$branchId)->where('id','<>',$faId)->update(['is_default_receipt'=>0,'updated_at'=>now()]);
                if ((int)$payload['is_default_payment'] === 1) DB::table('financial_accounts')->where('company_id',$companyId)->where('branch_id',$branchId)->where('id','<>',$faId)->update(['is_default_payment'=>0,'updated_at'=>now()]);
                $this->syncBranchDefaults($companyId,$branchId,$faId,$type,(bool)$payload['is_default_receipt'],(bool)$payload['is_default_payment']);
            }
            return (int)$faId;
        });
    }

    public function resolve(int $companyId, int $branchId, ?string $method = null, ?int $explicitFinancialAccountId = null, string $direction = 'PAYMENT'): object
    {
        $direction=strtoupper($direction); $method=strtoupper(trim((string)$method));
        if ($explicitFinancialAccountId) {
            $row=DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$explicitFinancialAccountId)->where('is_active',1)
                ->where(function($q)use($branchId){$q->where('branch_id',$branchId)->orWhereNull('branch_id');})->first();
            if (!$row) throw new \RuntimeException('الخزينة/الحساب المالي المحدد غير صالح لهذا الفرع.');
            return $row;
        }

        $wanted = match(true) {
            in_array($method,['BANK','CARD','BANK_TRANSFER','TRANSFER'],true) => 'BANK',
            in_array($method,['WALLET','E_WALLET'],true) => 'WALLET',
            default => 'CASH',
        };

        $settings=DB::table('branch_financial_settings')->where('company_id',$companyId)->where('branch_id',$branchId)->first();
        $specificId = match($wanted) {
            'BANK' => $settings->default_bank_financial_account_id ?? null,
            'WALLET' => $settings->default_wallet_financial_account_id ?? null,
            default => $settings->default_cash_financial_account_id ?? null,
        };
        if ($specificId) {
            $row=DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$specificId)->where('account_type',$wanted)->where('is_active',1)->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$branchId))->first();
            if ($row) return $row;
        }

        $flag = $direction === 'RECEIPT' ? 'is_default_receipt' : 'is_default_payment';
        $row=DB::table('financial_accounts')->where('company_id',$companyId)->where('branch_id',$branchId)->where('account_type',$wanted)->where('is_active',1)
            ->orderByDesc($flag)->orderBy('id')->first();
        // الحسابات المركزية (branch_id = NULL) يمكن أن تخدم أي عدد من الفروع دون تكرار حساب الأستاذ.
        if (!$row) $row=DB::table('financial_accounts')->where('company_id',$companyId)->whereNull('branch_id')->where('account_type',$wanted)->where('is_active',1)->orderByDesc($flag)->orderBy('id')->first();
        if (!$row && $wanted !== 'CASH') $row=DB::table('financial_accounts')->where('company_id',$companyId)->where('branch_id',$branchId)->where('account_type','CASH')->where('is_active',1)->orderByDesc($flag)->orderBy('id')->first();
        if (!$row && $wanted !== 'CASH') $row=DB::table('financial_accounts')->where('company_id',$companyId)->whereNull('branch_id')->where('account_type','CASH')->where('is_active',1)->orderByDesc($flag)->orderBy('id')->first();
        if (!$row) throw new \RuntimeException('لا توجد خزينة/حساب مالي فعال لهذا الفرع. افتح شاشة الخزائن والبنوك واضبط الحساب الافتراضي.');
        return $row;
    }

    private function hasUsage(int $companyId,int $id):bool
    {
        foreach(['journal_entry_lines','vouchers','expenses','opening_balance_lines']as$table)
            if(Schema::hasTable($table)&&Schema::hasColumn($table,'financial_account_id')&&DB::table($table)->where('company_id',$companyId)->where('financial_account_id',$id)->exists())return true;
        return false;
    }

    public function assertCurrency(object $financialAccount, string $currencyCode): void
    {
        $tx=strtoupper(trim($currencyCode)); $fa=strtoupper(trim((string)($financialAccount->currency_code ?? '')));
        if ($tx === '' || $fa === '') throw new \RuntimeException('عملة الحركة أو الحساب المالي غير محددة.');
        if ($tx !== $fa) throw new \RuntimeException('عملة الحركة '.$tx.' لا تطابق عملة الخزينة/البنك '.$fa.'. اختر حسابًا ماليًا بنفس العملة أو غيّر عملة الحركة.');
    }

    public function deactivateOrDelete(int $companyId, int $id): string
    {
        return DB::transaction(function() use($companyId,$id){
            $account=DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$id)->lockForUpdate()->first();
            if(!$account)throw new \RuntimeException('الحساب المالي غير موجود.');
            $used=$this->hasUsage($companyId,$id);
            $columns=['default_cash_financial_account_id','default_bank_financial_account_id','default_wallet_financial_account_id'];
            foreach($columns as$column)DB::table('branch_financial_settings')->where('company_id',$companyId)->where($column,$id)->update([$column=>null,'updated_at'=>now()]);
            if($used){DB::table('financial_accounts')->where('id',$id)->update(['is_active'=>0,'is_default_receipt'=>0,'is_default_payment'=>0,'updated_at'=>now()]);return'الحساب مستخدم تاريخيًا لذلك تم تعطيله بدل حذفه، وإزالة مراجع الافتراض غير الصالحة.';}
            DB::table('financial_accounts')->where('id',$id)->delete();return'تم حذف الحساب المالي وإزالة مراجع الافتراض المرتبطة به.';
        });
    }

    public function ensureDefaultCashForBranch(int $companyId, int $branchId, string $branchName, int $glAccountId, ?int $costCenterId = null): int
    {
        $base=$this->baseCurrency($companyId); $code='CASH-BR-'.$branchId;
        $existing=DB::table('financial_accounts')->where('company_id',$companyId)->where('account_code',$code)->first();
        if ($existing) $id=(int)$existing->id;
        else $id=DB::table('financial_accounts')->insertGetId([
            'company_id'=>$companyId,'branch_id'=>$branchId,'account_code'=>$code,'account_name'=>'صندوق '.$branchName,'account_type'=>'CASH',
            'gl_account_id'=>$glAccountId,'currency_code'=>$base,'is_default_receipt'=>1,'is_default_payment'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()
        ]);
        DB::table('branch_financial_settings')->updateOrInsert(['company_id'=>$companyId,'branch_id'=>$branchId],[
            'default_cash_financial_account_id'=>$id,'default_cost_center_id'=>$costCenterId,'created_at'=>now(),'updated_at'=>now()
        ]);
        return (int)$id;
    }

    public function baseCurrency(int $companyId): string
    {
        $code=DB::table('company_settings')->where('company_id',$companyId)->value('base_currency_code')
            ?: DB::table('company_settings')->where('company_id',$companyId)->value('currency_code');
        return strtoupper(trim((string)($code ?: 'USD')));
    }

    public function rate(int $companyId, string $currency, string $date): float
    {
        $currency=strtoupper(trim($currency));
        if ($currency === $this->baseCurrency($companyId)) return 1.0;
        $rate=DB::table('exchange_rates')->where('company_id',$companyId)->where('currency_code',$currency)->whereDate('valid_from','<=',$date)->orderByDesc('valid_from')->value('rate_to_base');
        if (!$rate || (float)$rate <= 0) throw new \RuntimeException('لا يوجد سعر صرف صالح للعملة '.$currency.' بتاريخ '.$date.'.');
        return (float)$rate;
    }

    private function ensureCompanyCurrency(int $companyId, string $code): void
    {
        $master=DB::table('currencies')->where('currency_code',$code)->where('is_active',1)->exists();$enabled=DB::table('company_currencies')->where('company_id',$companyId)->where('currency_code',$code)->where('is_active',1)->exists();if(!$master||!$enabled)throw new \RuntimeException('العملة غير مفعلة للشركة أو غير موجودة في الدليل النظامي.');
    }
    private function syncBranchDefaults(int $companyId,int $branchId,int $faId,string $type,bool $defaultReceipt=false,bool $defaultPayment=false): void
    {
        $current=DB::table('branch_financial_settings')->where('company_id',$companyId)->where('branch_id',$branchId)->first();
        $data=['updated_at'=>now()]; if(!$current)$data['created_at']=now();
        if ($type==='CASH'||$type==='PETTY_CASH') { if($defaultReceipt||$defaultPayment||empty($current?->default_cash_financial_account_id)) $data['default_cash_financial_account_id']=$faId; }
        if ($type==='BANK') { if($defaultReceipt||$defaultPayment||empty($current?->default_bank_financial_account_id)) $data['default_bank_financial_account_id']=$faId; }
        if ($type==='WALLET') { if($defaultReceipt||$defaultPayment||empty($current?->default_wallet_financial_account_id)) $data['default_wallet_financial_account_id']=$faId; }
        DB::table('branch_financial_settings')->updateOrInsert(['company_id'=>$companyId,'branch_id'=>$branchId],$data);
    }
    private function nextCode(int $companyId,string $type,?int $branchId): string
    {
        $prefix=match($type){'BANK'=>'BANK','WALLET'=>'WAL','PETTY_CASH'=>'PETTY',default=>'CASH'}; $branch=$branchId ?: 0; $n=DB::table('financial_accounts')->where('company_id',$companyId)->where('branch_id',$branchId)->count()+1;
        do{$code=$prefix.'-BR-'.$branch.'-'.str_pad($n,3,'0',STR_PAD_LEFT);$n++;}while(DB::table('financial_accounts')->where('company_id',$companyId)->where('account_code',$code)->exists());
        return $code;
    }
}
