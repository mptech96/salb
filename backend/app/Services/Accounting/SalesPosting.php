<?php
namespace App\Services\Accounting;
use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;
class SalesPosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}
    public function post(array $data): PostingResult
    {
        try{$cid=(int)$data['company_id'];$id=(int)$data['invoice_id'];$inv=DB::table('sales_invoices')->where('company_id',$cid)->where('id',$id)->first();if(!$inv)throw new \RuntimeException('فاتورة البيع غير موجودة.');if($inv->journal_entry_id)return PostingResult::success('الفاتورة مرحلة مسبقًا',(int)$inv->journal_entry_id);
            $vat=round((float)($inv->vat_amount??0),3);$total=round((float)$inv->total_amount,3);$revenue=round(max(0,$total-$vat),3);$cogs=round((float)DB::table('stock_movements')->where('company_id',$cid)->where('source_type','SALE')->where('source_id',$id)->sum('total_cost'),3);
            $lines=[['account_id'=>$this->support->setting($cid,'CUSTOMER_ACCOUNT'),'debit'=>$total,'credit'=>0,'party_type'=>'CUSTOMER','party_id'=>$inv->customer_id,'description'=>'ذمة العميل - فاتورة '.$inv->invoice_number],['account_id'=>$this->support->setting($cid,'SALES_ACCOUNT'),'debit'=>0,'credit'=>$revenue,'description'=>'إيراد فاتورة البيع']];
            if($vat>0)$lines[]=['account_id'=>$this->support->setting($cid,'VAT_OUTPUT_ACCOUNT'),'debit'=>0,'credit'=>$vat,'description'=>'ضريبة مخرجات'];
            if($cogs>0){$lines[]=['account_id'=>$this->support->setting($cid,'COGS_ACCOUNT'),'debit'=>$cogs,'credit'=>0,'description'=>'تكلفة البضاعة المباعة'];$lines[]=['account_id'=>$this->support->setting($cid,'INVENTORY_ACCOUNT'),'debit'=>0,'credit'=>$cogs,'description'=>'إخراج تكلفة المخزون'];}
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>(int)$inv->branch_id,'entry_date'=>$inv->invoice_date,'source_type'=>'SALE','source_id'=>$id,'description'=>'فاتورة بيع '.$inv->invoice_number,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$data['created_by']??$inv->created_by]);
            DB::table('sales_invoices')->where('id',$id)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);DB::table('stock_movements')->where('company_id',$cid)->where('source_type','SALE')->where('source_id',$id)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);return PostingResult::success('تم ترحيل فاتورة البيع',$jid);
        }catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
