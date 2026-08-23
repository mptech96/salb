<?php
namespace App\Services\Accounting;

use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;

class VoucherPosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}

    public function post(array $data): PostingResult
    {
        try {
            $cid=(int)$data['company_id'];$id=(int)$data['voucher_id'];
            $v=DB::table('vouchers as v')->leftJoin('voucher_types as t','t.id','=','v.voucher_type_id')->where('v.company_id',$cid)->where('v.id',$id)->select('v.*','t.type_code')->first();
            if(!$v)throw new \RuntimeException('السند غير موجود.');
            if($v->journal_entry_id)return PostingResult::success('السند مرحل مسبقًا',(int)$v->journal_entry_id,$id);

            $amount=round((float)$v->amount,3);$bid=(int)$v->branch_id;$isReceipt=strtoupper((string)$v->type_code)==='RECEIPT'||(int)$v->voucher_type_id===1;
            $fa=$this->support->financialAccount($cid,$bid,$v->payment_method,$v->financial_account_id?(int)$v->financial_account_id:null,$isReceipt?'RECEIPT':'PAYMENT');
            $cash=(int)$fa->gl_account_id;$ref=strtoupper((string)$v->reference_type);$partyType=null;$partyId=null;$counter=null;
            if($ref==='CUSTOMER'){$counter=$this->support->setting($cid,'CUSTOMER_ACCOUNT');$partyType='CUSTOMER';$partyId=(int)$v->reference_id;}
            elseif($ref==='SUPPLIER'){$counter=$this->support->setting($cid,'SUPPLIER_ACCOUNT');$partyType='SUPPLIER';$partyId=(int)$v->reference_id;}
            elseif($ref==='WORKER'){$counter=$this->support->setting($cid,$isReceipt?'WORKER_LOAN_ACCOUNT':'WORKER_PAYABLE_ACCOUNT');$partyType='WORKER';$partyId=(int)$v->reference_id;}
            elseif($ref==='DRIVER'){$counter=$this->support->setting($cid,'DRIVER_ADVANCE_ACCOUNT');$partyType='DRIVER';$partyId=(int)$v->reference_id;}
            elseif($ref==='ACCOUNT'){$counter=(int)$v->reference_id;$ok=DB::table('accounts')->where('company_id',$cid)->where('id',$counter)->where('is_group',0)->where('allow_posting',1)->where('is_active',1)->exists();if(!$ok)throw new \RuntimeException('الحساب المقابل للسند غير صالح.');}
            else throw new \RuntimeException('نوع الجهة في السند غير مدعوم محاسبيًا.');

            $currency=strtoupper((string)($v->currency_code?:$fa->currency_code));$rate=(float)($v->exchange_rate?:1);$foreign=(float)($v->foreign_amount?:0);
            $cashDim=['financial_account_id'=>(int)$fa->id,'currency_code'=>$currency,'exchange_rate'=>$rate];
            if($isReceipt)$lines=[[...$cashDim,'account_id'=>$cash,'debit'=>$amount,'credit'=>0,'foreign_debit'=>$foreign,'foreign_credit'=>0,'description'=>'قبض '.$v->voucher_number],['account_id'=>$counter,'debit'=>0,'credit'=>$amount,'party_type'=>$partyType,'party_id'=>$partyId,'description'=>'الطرف المقابل لسند القبض']];
            else $lines=[['account_id'=>$counter,'debit'=>$amount,'credit'=>0,'party_type'=>$partyType,'party_id'=>$partyId,'description'=>'الطرف المقابل لسند الصرف'],[...$cashDim,'account_id'=>$cash,'debit'=>0,'credit'=>$amount,'foreign_debit'=>0,'foreign_credit'=>$foreign,'description'=>'صرف '.$v->voucher_number]];

            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>$bid,'entry_date'=>$v->voucher_date,'source_type'=>'VOUCHER','source_id'=>$id,'description'=>($isReceipt?'سند قبض ':'سند صرف ').$v->voucher_number,'currency_code'=>$currency,'exchange_rate'=>$rate,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$data['created_by']??$v->created_by]);
            DB::table('vouchers')->where('id',$id)->update(['journal_entry_id'=>$jid,'cash_account_id'=>$cash,'financial_account_id'=>(int)$fa->id,'currency_code'=>$currency,'exchange_rate'=>$rate,'updated_at'=>now()]);
            return PostingResult::success('تم ترحيل السند',$jid,$id);
        } catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
