<?php
namespace App\Services\Accounting;
use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;
class PurchasePosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}
    public function post(array $data): PostingResult
    {
        try{$cid=(int)$data['company_id'];$id=(int)$data['invoice_id'];$inv=DB::table('purchase_invoices')->where('company_id',$cid)->where('id',$id)->first();if(!$inv)throw new \RuntimeException('فاتورة الشراء غير موجودة.');if($inv->journal_entry_id)return PostingResult::success('الفاتورة مرحلة مسبقًا',(int)$inv->journal_entry_id);
            $vat=round((float)($inv->vat_amount??0),3);$total=round((float)$inv->total_amount,3);$inventory=round(max(0,$total-$vat),3);
            $lines=[['account_id'=>$this->support->setting($cid,'INVENTORY_ACCOUNT'),'debit'=>$inventory,'credit'=>0,'description'=>'إثبات مخزون فاتورة الشراء']];if($vat>0)$lines[]=['account_id'=>$this->support->setting($cid,'VAT_INPUT_ACCOUNT'),'debit'=>$vat,'credit'=>0,'description'=>'ضريبة مدخلات'];
            $lines[]=['account_id'=>$this->support->setting($cid,'SUPPLIER_ACCOUNT'),'debit'=>0,'credit'=>$total,'party_type'=>'SUPPLIER','party_id'=>$inv->supplier_id,'description'=>'ذمة المورد - فاتورة '.$inv->invoice_number];
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>(int)$inv->branch_id,'entry_date'=>$inv->invoice_date,'source_type'=>'PURCHASE','source_id'=>$id,'description'=>'فاتورة شراء '.$inv->invoice_number,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$data['created_by']??$inv->created_by]);
            DB::table('purchase_invoices')->where('id',$id)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);DB::table('stock_movements')->where('company_id',$cid)->where('source_type','PURCHASE')->where('source_id',$id)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);return PostingResult::success('تم ترحيل فاتورة الشراء',$jid);
        }catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
