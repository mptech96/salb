<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * Resolves item accounts using the professional inheritance chain:
 * Item -> Category (including parents) -> Group -> Company accounting setting.
 */
class ItemAccountingResolver
{
    public function __construct(private PostingSupport $support) {}

    public function inventory(int $companyId, int $itemId): int
    { return $this->resolve($companyId,$itemId,'inventory_account_id',['INVENTORY_ACCOUNT']); }

    public function sales(int $companyId, int $itemId): int
    { return $this->resolve($companyId,$itemId,'sales_account_id',['SALES_ACCOUNT','SALES_REVENUE_ACCOUNT','REVENUE_ACCOUNT']); }

    public function cogs(int $companyId, int $itemId): int
    { return $this->resolve($companyId,$itemId,'cogs_account_id',['COGS_ACCOUNT','COST_OF_GOODS_SOLD_ACCOUNT','COST_OF_SALES_ACCOUNT']); }

    public function purchaseExpense(int $companyId, int $itemId): int
    { return $this->resolve($companyId,$itemId,'purchase_expense_account_id',['GENERAL_EXPENSE_ACCOUNT','PURCHASE_EXPENSE_ACCOUNT']); }

    public function salesReturn(int $companyId, int $itemId): int
    { return $this->resolve($companyId,$itemId,'sales_return_account_id',['SALES_RETURN_ACCOUNT','SALES_RETURNS_ACCOUNT','SALES_ACCOUNT','SALES_REVENUE_ACCOUNT','REVENUE_ACCOUNT']); }

    public function purchaseReturn(int $companyId, int $itemId): int
    { return $this->resolve($companyId,$itemId,'purchase_return_account_id',['PURCHASE_RETURN_ACCOUNT','PURCHASE_RETURNS_ACCOUNT','GENERAL_EXPENSE_ACCOUNT']); }

    public function receivable(int $companyId, ?int $customerId = null): int
    {
        if ($customerId) {
            $id=(int)(DB::table('customers')->where('company_id',$companyId)->where('id',$customerId)->value('ledger_account_id')?:0);
            if ($id>0) return $this->assertAccount($companyId,$id,'حساب العميل');
        }
        return $this->settingAny($companyId,['CUSTOMER_ACCOUNT','ACCOUNTS_RECEIVABLE_ACCOUNT','AR_ACCOUNT']);
    }

    public function payable(int $companyId, ?int $supplierId = null): int
    {
        if ($supplierId) {
            $id=(int)(DB::table('suppliers')->where('company_id',$companyId)->where('id',$supplierId)->value('ledger_account_id')?:0);
            if ($id>0) return $this->assertAccount($companyId,$id,'حساب المورد');
        }
        return $this->settingAny($companyId,['SUPPLIER_ACCOUNT','ACCOUNTS_PAYABLE_ACCOUNT','AP_ACCOUNT']);
    }

    public function unresolved(int $companyId): array
    {
        $out=[];
        $items=DB::table('items')->where('company_id',$companyId)->where('is_active',1)->get();
        foreach($items as$item){
            try {
                if(strtoupper((string)($item->item_type??'STOCK'))==='SERVICE') {
                    if((int)($item->can_sell??1)===1)$this->sales($companyId,(int)$item->id);
                    if((int)($item->can_purchase??1)===1)$this->purchaseExpense($companyId,(int)$item->id);
                } else {
                    $this->inventory($companyId,(int)$item->id);
                    if((int)($item->can_sell??1)===1){$this->sales($companyId,(int)$item->id);$this->cogs($companyId,(int)$item->id);}
                }
            } catch(\Throwable $e) {
                $out[]=['item_id'=>(int)$item->id,'item_code'=>$item->item_code??null,'item_name'=>$item->item_name??('#'.$item->id),'message'=>$e->getMessage()];
            }
        }
        return $out;
    }

    private function resolve(int $companyId,int $itemId,string $column,array $fallbackKeys): int
    {
        $item=DB::table('items')->where('company_id',$companyId)->where('id',$itemId)->first();
        if(!$item)throw new \RuntimeException('الصنف غير موجود عند حل الحساب المحاسبي.');
        $direct=(int)($item->{$column}??0);
        if($direct>0)return $this->assertAccount($companyId,$direct,'حساب الصنف');

        $categoryId=(int)($item->category_id??0);$visited=[];
        while($categoryId>0&&!isset($visited[$categoryId])){
            $visited[$categoryId]=true;
            $cat=DB::table('item_categories')->where('id',$categoryId)->where(fn($q)=>$q->where('company_id',$companyId)->orWhereNull('company_id'))->first();
            if(!$cat)break;
            $id=(int)($cat->{$column}??0);if($id>0)return $this->assertAccount($companyId,$id,'حساب فئة الصنف');
            $categoryId=(int)($cat->parent_id??0);
        }

        $groupId=(int)($item->group_id??0);
        if($groupId>0){
            $group=DB::table('item_groups')->where('company_id',$companyId)->where('id',$groupId)->first();
            $id=(int)($group?->{$column}??0);if($id>0)return $this->assertAccount($companyId,$id,'حساب مجموعة الصنف');
        }

        return $this->settingAny($companyId,$fallbackKeys);
    }

    public function settingAny(int $companyId,array $keys): int
    {
        foreach($keys as$key){
            $id=(int)(DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key',$key)->value('account_id')?:0);
            if($id>0)return $this->assertAccount($companyId,$id,'إعداد '.$key);
        }
        // Preserve compatibility with the project's PostingSupport error semantics.
        return $this->support->setting($companyId,$keys[0]);
    }

    private function assertAccount(int $companyId,int $accountId,string $label): int
    {
        $q=DB::table('accounts')->where('id',$accountId);
        if(DB::getSchemaBuilder()->hasColumn('accounts','company_id'))$q->where('company_id',$companyId);
        $a=$q->first();
        if(!$a)throw new \RuntimeException($label.' غير موجود في شجرة الحسابات.');
        if(isset($a->is_active)&&(int)$a->is_active!==1)throw new \RuntimeException($label.' غير نشط في شجرة الحسابات.');
        if(isset($a->is_postable)&&(int)$a->is_postable!==1)throw new \RuntimeException($label.' ليس حساب ترحيل.');
        return $accountId;
    }
}
