<?php
namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use App\Services\FinancialAccountService;

/** Experimental owned-database service. No production routes are registered. */
final class AtomicInvoiceVoucherService
{
    public static function authorize(int $cid,int $bid,int $uid,bool $corrective=false): void
    {
        HistoricalAccountingAuthorization::assert($cid,$bid,$uid,[$corrective?'vouchers.delete':'vouchers.create']);
        $subscription=app(\App\Services\Subscription\SubscriptionLifecycleService::class)->effectiveForCompany($cid);
        $status=strtoupper((string)($subscription->effective_status??$subscription->status??''));
        $allowed=$corrective?['ACTIVE','TRIAL','SUSPENDED','EXPIRED']:['ACTIVE','TRIAL'];
        if(!in_array($status,$allowed,true))throw new \RuntimeException('Subscription does not permit this historical action.');
        if(!$corrective&&!app(\App\Services\Entitlement\EffectiveEntitlementService::class)->allows($cid,'accounting'))throw new \RuntimeException('Accounting entitlement required.');
    }

    public function create(int $cid,int $bid,int $uid,array $input,array $allocations,string $key): array
    {
        self::authorize($cid,$bid,$uid);
        if(!preg_match('/^[A-Za-z0-9._:-]{1,90}$/D',$key))throw new \RuntimeException('Valid idempotency key required.');
        $fields=['voucher_type_id','voucher_date','reference_type','reference_id','amount','financial_account_id','payment_method','currency_code','exchange_rate','foreign_amount','notes'];
        if(array_diff(array_keys($input),$fields))throw new \RuntimeException('Unsupported voucher fields.');
        foreach(['voucher_type_id','voucher_date','reference_type','reference_id','amount','financial_account_id']as$field)if(!isset($input[$field]))throw new \RuntimeException('Required voucher field: '.$field);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$input['voucher_date'])||!in_array($input['reference_type'],['CUSTOMER','SUPPLIER'],true))throw new \RuntimeException('Valid dated party settlement required.');
        ksort($input);ksort($allocations,SORT_NUMERIC);
        $hash=hash('sha256',json_encode([$bid,$input,$allocations],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($cid,$bid,$uid,$input,$allocations,$key,$hash){
            DB::table('companies')->where('id',$cid)->lockForUpdate()->first();
            $type=DB::table('voucher_types')->where('id',$input['voucher_type_id'])->first();
            if(!$type||!in_array($type->type_code,['RECEIPT','PAYMENT'],true))throw new \RuntimeException('Receipt or payment required.');
            $operation='CREATE_'.$type->type_code;
            $allocationKey='atomic:'.hash('sha256',$operation.':'.$key);
            $existing=DB::table('voucher_operation_requests')->where('company_id',$cid)->where('operation',$operation)->where('request_key',$key)->first();
            if($existing){
                $voucher=DB::table('vouchers')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$existing->voucher_id)->first();
                if(!$voucher||$existing->request_hash!==$hash)throw new \RuntimeException('Idempotency payload changed.');
                return ['id'=>(int)$existing->voucher_id,'journal_entry_id'=>(int)$existing->journal_entry_id];
            }
            $money=app(FinancialAccountService::class);
            $fa=$money->resolve($cid,$bid,$input['payment_method']??'CASH',(int)$input['financial_account_id'],$type->type_code);
            $currency=strtoupper($input['currency_code']??$fa->currency_code);$money->assertCurrency($fa,$currency);
            $rate=$money->rate($cid,$currency,$input['voucher_date']);
            if(isset($input['exchange_rate'])&&bccomp((string)$input['exchange_rate'],(string)$rate,10)!==0)throw new \RuntimeException('Unapproved exchange rate.');
            $amount=HistoricalDecimal::format(HistoricalDecimal::units($input['amount']));
            if(HistoricalDecimal::units($amount)<=0)throw new \RuntimeException('Positive voucher amount required.');
            $foreign=bcadd(bcdiv($amount,(string)$rate,12),'0.0005',3);
            if(isset($input['foreign_amount'])&&HistoricalDecimal::units($input['foreign_amount'])!==HistoricalDecimal::units($foreign))throw new \RuntimeException('Foreign amount mismatch.');
            $number=app(\App\Services\SulbDocumentSequenceService::class)->reserveVoucher($cid,$type->type_code,$input['voucher_date']);
            $id=DB::table('vouchers')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'voucher_type_id'=>$type->id,'voucher_number'=>$number,'voucher_date'=>$input['voucher_date'],'reference_type'=>$input['reference_type'],'reference_id'=>$input['reference_id'],'amount'=>$amount,'financial_account_id'=>$fa->id,'cash_account_id'=>$fa->gl_account_id,'payment_method'=>$input['payment_method']??'CASH','currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_amount'=>$foreign,'notes'=>$input['notes']??null,'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);
            $journal=app(InvoiceAllocationService::class)->post($cid,$bid,$id,$allocations,$allocationKey,$uid);
            DB::table('voucher_operation_requests')->insert(['company_id'=>$cid,'branch_id'=>$bid,'operation'=>$operation,'request_key'=>$key,'request_hash'=>$hash,'hash_version'=>1,'voucher_id'=>$id,'journal_entry_id'=>$journal,'actor_id'=>$uid,'actor_name'=>\Illuminate\Support\Facades\Auth::user()->name,'completed_at'=>now()]);
            return ['id'=>$id,'journal_entry_id'=>$journal];
        });
    }

    public function reverse(int $cid,int $bid,int $uid,int $voucher,string $date,string $reason,array $allocationIds=[]): int
    {
        self::authorize($cid,$bid,$uid,true);
        if(mb_strlen(trim($reason))<5||mb_strlen($reason)>500)throw new \RuntimeException('Clear corrective reason required.');
        if($allocationIds!==[])throw new \RuntimeException('Partial allocation reversal is not approved.');
        return DB::transaction(function()use($cid,$bid,$uid,$voucher,$date,$reason){
            DB::table('companies')->where('id',$cid)->lockForUpdate()->first();
            if(DB::table('voucher_invoice_allocations')->where('company_id',$cid)->where('voucher_id',$voucher)->where('entry_kind','REVERSE')->exists())throw new \RuntimeException('Settlement already reversed.');
            $original=DB::table('vouchers')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$voucher)->first();
            if(!$original)throw new \RuntimeException('Voucher outside scope.');
            $inverse=app(InvoiceAllocationService::class)->reverse($cid,$bid,$voucher,$uid,$date,$reason);
            DB::table('voucher_correction_audits')->insert(['company_id'=>$cid,'branch_id'=>$bid,'voucher_id'=>$voucher,'original_voucher_number'=>$original->voucher_number,'original_journal_id'=>$original->journal_entry_id,'inverse_journal_id'=>$inverse,'actor_id'=>$uid,'actor_name'=>\Illuminate\Support\Facades\Auth::user()->name,'reason'=>trim($reason),'effective_date'=>$date,'occurred_at'=>now(),'action'=>'FULL_REVERSE']);
            return $inverse;
        });
    }
}
