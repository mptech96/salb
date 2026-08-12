<?php
namespace App\Services\Accounting;
use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;
class ExpensePosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}
    public function post(array $data): PostingResult
    {
        try{$cid=(int)$data['company_id'];$bid=(int)$data['branch_id'];$eid=(int)$data['expense_id'];$amount=round((float)$data['amount'],3);$paid=strtoupper($data['payment_status']??'PAID')==='PAID';
            $expenseAcc=(int)($data['expense_account_id']??0);if(!$expenseAcc)$expenseAcc=$this->support->setting($cid,'GENERAL_EXPENSE_ACCOUNT');$creditAcc=$paid?$this->support->cashAccount($cid,$data['payment_method']??'CASH'): $this->support->setting($cid,'ACCRUED_EXPENSE_ACCOUNT');
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>$bid,'entry_date'=>$data['expense_date'],'source_type'=>'EXPENSE','source_id'=>$eid,'description'=>'مصروف رقم '.$eid,'lines'=>[['account_id'=>$expenseAcc,'debit'=>$amount,'credit'=>0,'description'=>'إثبات المصروف'],['account_id'=>$creditAcc,'debit'=>0,'credit'=>$amount,'description'=>$paid?'سداد المصروف':'إثبات مصروف مستحق']],'is_system_generated'=>1,'created_by'=>$data['created_by']??null]);
            $voucherId=null;if($paid){$last=DB::table('vouchers')->where('company_id',$cid)->max('id')??0;$voucherId=DB::table('vouchers')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'voucher_type_id'=>2,'voucher_number'=>'PAY-'.date('Y').'-'.str_pad($last+1,5,'0',STR_PAD_LEFT),'voucher_date'=>$data['expense_date'],'reference_type'=>'EXPENSE','reference_id'=>$eid,'amount'=>$amount,'journal_entry_id'=>$jid,'cash_account_id'=>$creditAcc,'payment_method'=>$data['payment_method']??'CASH','notes'=>'سند صرف تلقائي للمصروف','created_by'=>$data['created_by']??null,'created_at'=>now(),'updated_at'=>now()]);}
            DB::table('expenses')->where('company_id',$cid)->where('id',$eid)->update(['voucher_id'=>$voucherId,'journal_entry_id'=>$jid,'updated_at'=>now()]);return PostingResult::success('تم ترحيل المصروف',$jid,$voucherId);
        }catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
