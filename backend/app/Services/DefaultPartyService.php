<?php

namespace App\Services;

use App\Services\Accounting\ItemAccountingResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DefaultPartyService
{
    public function __construct(private ItemAccountingResolver $accounts) {}

    public function ensure(int $companyId, ?int $userId = null): array
    {
        return DB::transaction(function() use($companyId,$userId){
            $settings=DB::table('company_settings')->where('company_id',$companyId)->lockForUpdate()->first();
            if(!$settings)throw new \RuntimeException('إعدادات الشركة غير موجودة. أكمل تهيئة الشركة المالية أولاً.');

            $customerId=(int)($settings->default_customer_id??0);
            if(!$customerId||!DB::table('customers')->where('company_id',$companyId)->where('id',$customerId)->exists()){
                $customerId=$this->findOrCreateParty($companyId,'CUSTOMER','عميل عام - بيع مباشر',$userId);
            }
            $supplierId=(int)($settings->default_supplier_id??0);
            if(!$supplierId||!DB::table('suppliers')->where('company_id',$companyId)->where('id',$supplierId)->exists()){
                $supplierId=$this->findOrCreateParty($companyId,'SUPPLIER','مورد عام - شراء مباشر',$userId);
            }

            $customerAccount=(int)($settings->default_customer_account_id??0);
            if(!$customerAccount){
                $parent=$this->accounts->settingAny($companyId,['CUSTOMER_ACCOUNT','ACCOUNTS_RECEIVABLE_ACCOUNT','AR_ACCOUNT']);
                $customerAccount=$this->createDedicatedChildAccount($companyId,$parent,'عميل عام - بيع مباشر','WALKIN-CUST') ?: $parent;
            }
            $supplierAccount=(int)($settings->default_supplier_account_id??0);
            if(!$supplierAccount){
                $parent=$this->accounts->settingAny($companyId,['SUPPLIER_ACCOUNT','ACCOUNTS_PAYABLE_ACCOUNT','AP_ACCOUNT']);
                $supplierAccount=$this->createDedicatedChildAccount($companyId,$parent,'مورد عام - شراء مباشر','WALKIN-SUPP') ?: $parent;
            }

            if(Schema::hasColumn('customers','ledger_account_id'))DB::table('customers')->where('company_id',$companyId)->where('id',$customerId)->update(['ledger_account_id'=>$customerAccount,'is_system_default'=>1,'updated_at'=>now()]);
            if(Schema::hasColumn('suppliers','ledger_account_id'))DB::table('suppliers')->where('company_id',$companyId)->where('id',$supplierId)->update(['ledger_account_id'=>$supplierAccount,'is_system_default'=>1,'updated_at'=>now()]);

            DB::table('company_settings')->where('company_id',$companyId)->update([
                'default_customer_id'=>$customerId,'default_supplier_id'=>$supplierId,
                'default_customer_account_id'=>$customerAccount,'default_supplier_account_id'=>$supplierAccount,'updated_at'=>now(),
            ]);

            return [
                'default_customer_id'=>$customerId,
                'default_supplier_id'=>$supplierId,
                'default_customer_account_id'=>$customerAccount,
                'default_supplier_account_id'=>$supplierAccount,
                'customer'=>DB::table('customers')->where('company_id',$companyId)->where('id',$customerId)->first(),
                'supplier'=>DB::table('suppliers')->where('company_id',$companyId)->where('id',$supplierId)->first(),
                'customer_account'=>DB::table('accounts')->where('id',$customerAccount)->first(),
                'supplier_account'=>DB::table('accounts')->where('id',$supplierAccount)->first(),
            ];
        });
    }

    private function findOrCreateParty(int $companyId,string $type,string $name,?int $userId): int
    {
        $table=$type==='CUSTOMER'?'customers':'suppliers';$nameCol=$type==='CUSTOMER'?'customer_name':'supplier_name';
        $existing=DB::table($table)->where('company_id',$companyId)->where(function($q)use($nameCol,$name){$q->where('is_system_default',1)->orWhere($nameCol,$name);})->orderByDesc('is_system_default')->first();
        if($existing)return (int)$existing->id;

        $columns=array_flip(Schema::getColumnListing($table));
        $row=[];
        foreach([
            'company_id'=>$companyId,$nameCol=>$name,'is_active'=>1,'is_system_default'=>1,
            'scope_all_branches'=>1,'default_branch_id'=>null,'phone'=>null,'email'=>null,'tax_number'=>null,
            'notes'=>'طرف افتراضي أنشأه SULB ERP للفواتير السريعة. يمكن اختيار طرف حقيقي بدلاً منه في أي فاتورة.',
            'created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
        ] as$k=>$v)if(isset($columns[$k]))$row[$k]=$v;

        try{return (int)DB::table($table)->insertGetId($row);}catch(\Throwable $e){
            throw new \RuntimeException('تعذر إنشاء '.$name.' تلقائيًا بسبب متطلب في جدول '.$table.'. أنشئ الطرف مرة واحدة من شاشة الأطراف ثم أعد تهيئة الافتراضيات. التفاصيل: '.$e->getMessage());
        }
    }

    /**
     * Best effort dedicated subledger leaf under AR/AP. If the current chart schema
     * has custom constraints that prevent safe cloning, the caller deliberately
     * falls back to the existing AR/AP control account; party_id still preserves
     * a clean subledger statement.
     */
    private function createDedicatedChildAccount(int $companyId,int $parentId,string $name,string $suffix): ?int
    {
        try{
            if(!Schema::hasTable('accounts'))return null;
            $parent=DB::table('accounts')->where('id',$parentId)->first();if(!$parent)return null;
            $columns=array_flip(Schema::getColumnListing('accounts'));
            $data=[];
            foreach((array)$parent as$k=>$v)if(isset($columns[$k])&&!in_array($k,['id','created_at','updated_at'],true))$data[$k]=$v;
            foreach(['system_key','uuid','external_id','external_code','slug']as$k)if(isset($columns[$k]))$data[$k]=null;
            if(isset($columns['company_id']))$data['company_id']=$companyId;
            if(isset($columns['parent_id']))$data['parent_id']=$parentId;
            if(isset($columns['account_name']))$data['account_name']=$name;
            if(isset($columns['name']))$data['name']=$name;
            $code=$this->uniqueAccountCode($companyId,$parent,$suffix);
            if(isset($columns['account_code']))$data['account_code']=$code;
            if(isset($columns['account_number']))$data['account_number']=$code;
            if(isset($columns['code']))$data['code']=$code;
            if(isset($columns['is_postable']))$data['is_postable']=1;
            if(isset($columns['is_active']))$data['is_active']=1;
            if(isset($columns['level']))$data['level']=(int)($parent->level??0)+1;
            if(isset($columns['account_level']))$data['account_level']=(int)($parent->account_level??0)+1;
            if(isset($columns['created_at']))$data['created_at']=now();
            if(isset($columns['updated_at']))$data['updated_at']=now();
            return (int)DB::table('accounts')->insertGetId($data);
        }catch(\Throwable $e){return null;}
    }

    private function uniqueAccountCode(int $companyId,object $parent,string $suffix): string
    {
        $base=trim((string)($parent->account_code??$parent->account_number??$parent->code??''));
        $base=$base!==''?$base:'AUTO';
        for($i=1;$i<=999;$i++){
            $code=$base.'-'.$suffix.($i>1?'-'.$i:'');
            $q=DB::table('accounts');if(Schema::hasColumn('accounts','company_id'))$q->where('company_id',$companyId);
            if(Schema::hasColumn('accounts','account_code'))$exists=$q->where('account_code',$code)->exists();
            elseif(Schema::hasColumn('accounts','account_number'))$exists=$q->where('account_number',$code)->exists();
            elseif(Schema::hasColumn('accounts','code'))$exists=$q->where('code',$code)->exists();else return $code;
            if(!$exists)return $code;
        }
        return $base.'-'.$suffix.'-'.time();
    }
}
