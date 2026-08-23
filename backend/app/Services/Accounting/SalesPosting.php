<?php
namespace App\Services\Accounting;

use App\Domain\Accounting\Services\JournalService;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\DB;

class SalesPosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support,private TaxEngineService $taxes){}
    public function post(array $data): PostingResult
    {
        try{$cid=(int)$data['company_id'];$id=(int)$data['invoice_id'];$inv=DB::table('sales_invoices')->where('company_id',$cid)->where('id',$id)->first();if(!$inv)throw new \RuntimeException('فاتورة البيع غير موجودة.');if($inv->journal_entry_id)return PostingResult::success('الفاتورة مرحلة مسبقًا',(int)$inv->journal_entry_id);
            $total=round((float)($inv->base_total_amount??$inv->total_amount),3);$revenue=round((float)($inv->base_total_before_vat??($total-(float)$inv->vat_amount)),3);$vatTotal=round((float)($inv->base_vat_amount??$inv->vat_amount),3);$cogs=round((float)DB::table('stock_movements')->where('company_id',$cid)->where('source_type','SALE')->where('source_id',$id)->sum('total_cost'),3);
            $lines=[['account_id'=>$this->support->setting($cid,'CUSTOMER_ACCOUNT'),'debit'=>$total,'credit'=>0,'party_type'=>'CUSTOMER','party_id'=>$inv->customer_id,'description'=>'ذمة العميل - فاتورة '.$inv->invoice_number],['account_id'=>$this->support->setting($cid,'SALES_ACCOUNT'),'debit'=>0,'credit'=>$revenue,'description'=>'إيراد فاتورة البيع']];
            $taxRows=DB::table('sales_invoice_lines')->where('company_id',$cid)->where('sales_invoice_id',$id)->select('tax_code_id',DB::raw('SUM(COALESCE(base_vat_amount,vat_amount)) tax'))->groupBy('tax_code_id')->get();$postedTax=0.0;
            foreach($taxRows as$t){$amt=round((float)$t->tax,3);if($amt<=0)continue;$acc=$this->taxes->taxAccount($cid,$t->tax_code_id?(int)$t->tax_code_id:null,'SALES');$lines[]=['account_id'=>$acc,'debit'=>0,'credit'=>$amt,'description'=>'ضريبة مخرجات'];$postedTax+=$amt;}
            $taxDiff=round($vatTotal-$postedTax,3);if(abs($taxDiff)>0.0001){$acc=$this->support->setting($cid,'VAT_OUTPUT_ACCOUNT');$lines[]=['account_id'=>$acc,'debit'=>$taxDiff<0?abs($taxDiff):0,'credit'=>$taxDiff>0?$taxDiff:0,'description'=>'تسوية تقريب ضريبة المخرجات'];}
            if($cogs>0){$lines[]=['account_id'=>$this->support->setting($cid,'COGS_ACCOUNT'),'debit'=>$cogs,'credit'=>0,'description'=>'تكلفة البضاعة المباعة'];$lines[]=['account_id'=>$this->support->setting($cid,'INVENTORY_ACCOUNT'),'debit'=>0,'credit'=>$cogs,'description'=>'إخراج تكلفة المخزون'];}
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>(int)$inv->branch_id,'entry_date'=>$inv->invoice_date,'source_type'=>'SALE','source_id'=>$id,'description'=>'فاتورة بيع '.$inv->invoice_number,'currency_code'=>$inv->currency_code??null,'exchange_rate'=>$inv->exchange_rate??null,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$data['created_by']??$inv->created_by]);
            DB::table('sales_invoices')->where('id',$id)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);DB::table('stock_movements')->where('company_id',$cid)->where('source_type','SALE')->where('source_id',$id)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);return PostingResult::success('تم ترحيل فاتورة البيع',$jid);
        }catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
