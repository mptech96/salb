<?php
namespace App\Services\Accounting;

use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;

class ExpensePosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}

    public function post(array $data): PostingResult
    {
        try {
            return DB::transaction(function()use($data){
            $cid=(int)$data['company_id'];$bid=(int)$data['branch_id'];$eid=(int)$data['expense_id'];$amount=round((float)$data['amount'],3);$paid=strtoupper($data['payment_status']??'PAID')==='PAID';
            $expense=DB::table('expenses')->where('company_id',$cid)->where('id',$eid)->lockForUpdate()->first();
            if(!$expense)throw new \RuntimeException('المصروف غير موجود.');
            if($expense->journal_entry_id)return PostingResult::success('المصروف مرحل مسبقًا',(int)$expense->journal_entry_id,$expense->voucher_id?(int)$expense->voucher_id:null);
            $expenseAcc=(int)($data['expense_account_id']??0);if(!$expenseAcc)$expenseAcc=$this->support->setting($cid,'GENERAL_EXPENSE_ACCOUNT');
            $fa=null;$creditAcc=$this->support->setting($cid,'ACCRUED_EXPENSE_ACCOUNT');$currency=null;$rate=null;
            if($paid){$fa=$this->support->financialAccount($cid,$bid,$data['payment_method']??'CASH',isset($data['financial_account_id'])?(int)$data['financial_account_id']:null,'PAYMENT');$creditAcc=(int)$fa->gl_account_id;$currency=strtoupper((string)($data['currency_code']??$fa->currency_code));$rate=(float)($data['exchange_rate']??1);}
            $creditLine=['account_id'=>$creditAcc,'debit'=>0,'credit'=>$amount,'description'=>$paid?'سداد المصروف':'إثبات مصروف مستحق'];
            if($fa){$creditLine['financial_account_id']=(int)$fa->id;$creditLine['currency_code']=$currency;$creditLine['exchange_rate']=$rate;$creditLine['foreign_credit']=(float)($data['foreign_amount']??0);}
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>$bid,'entry_date'=>$data['expense_date'],'source_type'=>'EXPENSE','source_id'=>$eid,'description'=>'مصروف رقم '.$eid,'currency_code'=>$currency,'exchange_rate'=>$rate,'lines'=>[['account_id'=>$expenseAcc,'debit'=>$amount,'credit'=>0,'description'=>'إثبات المصروف'],$creditLine],'is_system_generated'=>1,'created_by'=>$data['created_by']??null]);
            $voucherId=null;
            if($paid){
                $voucherTypeId=DB::table('voucher_types')->where('type_code','PAYMENT')->value('id')?:DB::table('voucher_types')->where('id',2)->value('id');if(!$voucherTypeId)throw new \RuntimeException('نوع سند الصرف غير معرف.');
                $last=DB::table('vouchers')->where('company_id',$cid)->max('id')??0;
                $voucherId=DB::table('vouchers')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'voucher_type_id'=>$voucherTypeId,'voucher_number'=>'PAY-'.date('Y').'-'.str_pad($last+1,5,'0',STR_PAD_LEFT),'voucher_date'=>$data['expense_date'],'reference_type'=>'EXPENSE','reference_id'=>$eid,'amount'=>$amount,'journal_entry_id'=>$jid,'cash_account_id'=>$creditAcc,'financial_account_id'=>(int)$fa->id,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_amount'=>$data['foreign_amount']??null,'payment_method'=>$data['payment_method']??'CASH','notes'=>'سند صرف تلقائي للمصروف','created_by'=>$data['created_by']??null,'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('expenses')->where('company_id',$cid)->where('id',$eid)->update(['voucher_id'=>$voucherId,'journal_entry_id'=>$jid,'financial_account_id'=>$fa?->id,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_amount'=>$data['foreign_amount']??null,'updated_at'=>now()]);
            return PostingResult::success('تم ترحيل المصروف',$jid,$voucherId);
            });
        } catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
