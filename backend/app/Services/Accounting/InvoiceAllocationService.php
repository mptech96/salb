<?php
namespace App\Services\Accounting;

use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;

/** Isolated opt-in only; no existing voucher route is changed. */
final class InvoiceAllocationService
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}

    public function outstanding(object $s): float
    {
        return (float)$this->outstandingDecimal($s);
    }
    public function outstandingDecimal(object $s): string
    {
        HistoricalAccountingAuthorization::assert((int)$s->company_id,(int)$s->branch_id,(int)\Illuminate\Support\Facades\Auth::id(),[$s->invoice_type==='SALE'?'sales.view':'purchases.view','vouchers.create','vouchers.delete','returns.post']);
        $s=DB::table('invoice_accounting_snapshots')->where('company_id',$s->company_id)->where('branch_id',$s->branch_id)->where('id',$s->id)->first();
        if(!$s)throw new \RuntimeException('Authoritative snapshot required for outstanding.');
        $paid=DB::table('voucher_invoice_allocations')->where('company_id',$s->company_id)->where('snapshot_id',$s->id)->selectRaw("COALESCE(SUM(CASE WHEN entry_kind='ALLOCATE' THEN base_amount ELSE -base_amount END),0) AS paid")->value('paid');
        $returns=DB::table('commercial_returns')->where('company_id',$s->company_id)->where('source_invoice_id',$s->sales_invoice_id??$s->purchase_invoice_id)->where('return_type',$s->invoice_type==='SALE'?'SALES_RETURN':'PURCHASE_RETURN')->where('document_status','POSTED')->sum('base_total_amount');
        return HistoricalDecimal::format(HistoricalDecimal::units($s->base_total)-HistoricalDecimal::units($returns)-HistoricalDecimal::units($paid));
    }

    public function post(int $cid,int $bid,int $voucherId,array $allocations,string $key,int $uid): int
    {
        IsolatedHistoricalAccountingGate::assert();
        HistoricalAccountingAuthorization::assert($cid,$bid,$uid,['vouchers.create']);
        if(!$allocations||strlen($key)>100||trim($key)==='')throw new \RuntimeException('Allocation and idempotency key required.');
        foreach($allocations as&$amount){$units=HistoricalDecimal::units($amount);if($units<=0)throw new \RuntimeException('Positive allocation required.');$amount=HistoricalDecimal::format($units);}unset($amount);
        ksort($allocations,SORT_NUMERIC);$hash=hash('sha256',json_encode([$bid,$voucherId,$allocations]));
        return DB::transaction(function()use($cid,$bid,$voucherId,$allocations,$key,$uid,$hash){
            DB::table('companies')->where('id',$cid)->lockForUpdate()->first();
            $v=DB::table('vouchers as v')->join('voucher_types as t','t.id','=','v.voucher_type_id')->where('v.company_id',$cid)->where('v.branch_id',$bid)->where('v.id',$voucherId)->select('v.*','t.type_code')->lockForUpdate()->first();
            if(!$v)throw new \RuntimeException('Voucher outside scope.');
            $old=DB::table('voucher_invoice_allocations')->where('company_id',$cid)->where('idempotency_key',$key)->get();
            if($old->isNotEmpty()){if($old->count()!==count($allocations)||$old->contains(fn($a)=>$a->request_hash!==$hash))throw new \RuntimeException('Idempotency payload changed.');return (int)$old->first()->journal_entry_id;}
            if($v->journal_entry_id)throw new \RuntimeException('Existing posted voucher cannot be automatically allocated.');
            $receipt=$v->type_code==='RECEIPT';$party=$receipt?'CUSTOMER':'SUPPLIER';
            if(($receipt&&$v->reference_type!=='CUSTOMER')||(!$receipt&&($v->type_code!=='PAYMENT'||$v->reference_type!=='SUPPLIER')))throw new \RuntimeException('Incompatible voucher direction/party.');
            app(\App\Services\PartyBranchScopeService::class)->assertAccessible($cid,$party,(int)$v->reference_id,$bid);
            $fa=$this->support->financialAccount($cid,$bid,$v->payment_method,(int)$v->financial_account_id,$receipt?'RECEIPT':'PAYMENT');
            $partyLines=[];$totalUnits=0;$sources=[];
            foreach($allocations as$sid=>$amount){
                $amountUnits=HistoricalDecimal::units($amount);if($amountUnits<=0)throw new \RuntimeException('Invalid allocation amount.');
                $s=DB::table('invoice_accounting_snapshots')->where('company_id',$cid)->where('branch_id',$bid)->where('id',(int)$sid)->lockForUpdate()->first();
                if(!$s||$s->evidence_type!=='LIVE_CAPTURE'||$s->invoice_type!==($receipt?'SALE':'PURCHASE')||(int)($receipt?$s->customer_id:$s->supplier_id)!==(int)$v->reference_id)throw new \RuntimeException('Incompatible/legacy invoice allocation.');
                $table=$receipt?'sales_invoices':'purchase_invoices';$invoiceId=(int)($receipt?$s->sales_invoice_id:$s->purchase_invoice_id);
                $invoice=DB::table($table)->where('company_id',$cid)->where('id',$invoiceId)->lockForUpdate()->first();
                if(!$invoice||$v->voucher_date<$invoice->invoice_date)throw new \RuntimeException('Settlement cannot precede original invoice.');
                $gl=app(HistoricalInvoiceAccountingService::class)->partyAccount($cid,$bid,$s->invoice_type,$invoiceId);
                if($gl!==(int)$s->party_account_id)throw new \RuntimeException('Snapshot/source GL drift.');
                if($s->currency_code!==$v->currency_code||$fa->currency_code!==$v->currency_code||abs((float)$s->exchange_rate-(float)$v->exchange_rate)>0.0000000001)throw new \RuntimeException('Cross-currency/FX realization requires approved policy.');
                if($amountUnits>HistoricalDecimal::units($this->outstandingDecimal($s)))throw new \RuntimeException('Allocation exceeds outstanding.');
                $partyLines[]=['account_id'=>$gl,'debit'=>$receipt?0:$amount,'credit'=>$receipt?$amount:0,'party_type'=>$party,'party_id'=>(int)$v->reference_id,'description'=>'تخصيص فاتورة أصلي','currency_code'=>$v->currency_code,'exchange_rate'=>$v->exchange_rate,'foreign_debit'=>$receipt?0:round($amount/(float)$v->exchange_rate,3),'foreign_credit'=>$receipt?round($amount/(float)$v->exchange_rate,3):0];
                $totalUnits+=$amountUnits;$sources[]=$s;
            }
            $total=HistoricalDecimal::format($totalUnits);
            if($totalUnits!==HistoricalDecimal::units($v->amount)||abs(round((float)$total/(float)$v->exchange_rate,3)-(float)$v->foreign_amount)>0.001)throw new \RuntimeException('Allocations must reconcile to entire voucher; no implicit remainder.');
            $partyLines[]=['account_id'=>(int)$fa->gl_account_id,'financial_account_id'=>(int)$fa->id,'debit'=>$receipt?$total:0,'credit'=>$receipt?0:$total,'currency_code'=>$v->currency_code,'exchange_rate'=>$v->exchange_rate,'foreign_debit'=>$receipt?(float)$v->foreign_amount:0,'foreign_credit'=>$receipt?0:(float)$v->foreign_amount,'description'=>'تسوية سند مرتبط'];
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>$bid,'entry_date'=>$v->voucher_date,'source_type'=>'VOUCHER','source_id'=>$voucherId,'description'=>'سند مرتبط '.$v->voucher_number,'currency_code'=>$v->currency_code,'exchange_rate'=>$v->exchange_rate,'lines'=>$partyLines,'created_by'=>$uid,'is_system_generated'=>1]);
            foreach($sources as$s){$amount=round((float)$allocations[$s->id],3);DB::table('voucher_invoice_allocations')->insert(['company_id'=>$cid,'branch_id'=>$bid,'voucher_id'=>$voucherId,'snapshot_id'=>$s->id,'journal_entry_id'=>$jid,'entry_kind'=>'ALLOCATE','reverses_allocation_id'=>null,'currency_code'=>$v->currency_code,'exchange_rate'=>$v->exchange_rate,'base_amount'=>$amount,'foreign_amount'=>round($amount/(float)$v->exchange_rate,3),'idempotency_key'=>$key,'request_hash'=>$hash,'created_by'=>$uid,'created_at'=>now()]);}
            DB::table('vouchers')->where('company_id',$cid)->where('id',$voucherId)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);
            foreach($sources as$s)$this->project($s);
            return $jid;
        });
    }

    public function reverse(int $cid,int $bid,int $voucherId,int $uid,string $date,string $reason='عكس تخصيص اختبار معزول'): int
    {
        IsolatedHistoricalAccountingGate::assert();
        HistoricalAccountingAuthorization::assert($cid,$bid,$uid,['vouchers.delete']);
        return DB::transaction(function()use($cid,$bid,$voucherId,$uid,$date,$reason){
            DB::table('companies')->where('id',$cid)->lockForUpdate()->first();
            $v=DB::table('vouchers')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$voucherId)->lockForUpdate()->first();if(!$v)throw new \RuntimeException('Voucher outside scope.');
            $rows=DB::table('voucher_invoice_allocations')->where('company_id',$cid)->where('voucher_id',$voucherId)->where('entry_kind','ALLOCATE')->orderBy('snapshot_id')->lockForUpdate()->get();if($rows->isEmpty())throw new \RuntimeException('No allocations to reverse.');
            $old=DB::table('voucher_invoice_allocations')->where('company_id',$cid)->where('voucher_id',$voucherId)->where('entry_kind','REVERSE')->get();if($old->count()===$rows->count())return(int)$old->first()->journal_entry_id;if($old->isNotEmpty())throw new \RuntimeException('Partial reversal requires review.');
            // Only a complete source-linked settlement can be reversed. Never
            // reverse an unrelated/partially allocated historical cash voucher.
            if(HistoricalDecimal::units($rows->sum('base_amount'))!==HistoricalDecimal::units($v->amount)
                ||$rows->contains(fn($r)=>(int)$r->journal_entry_id!==(int)$v->journal_entry_id))throw new \RuntimeException('Full original settlement evidence required.');
            $journal=DB::table('journal_entries')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$v->journal_entry_id)->first();
            if(!$journal||$journal->source_type!=='VOUCHER'||(int)$journal->source_id!==$voucherId||$journal->status!=='POSTED')throw new \RuntimeException('Original posted voucher journal required.');
            $expected=[];$receipt=$v->reference_type==='CUSTOMER';
            foreach($rows as$row){$snap=DB::table('invoice_accounting_snapshots')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$row->snapshot_id)->first();if(!$snap)throw new \RuntimeException('Historical evidence outside scope.');$expected[$snap->party_account_id]=($expected[$snap->party_account_id]??0)+HistoricalDecimal::units($row->base_amount);}
            $actual=[];$cashUnits=0;
            foreach(DB::table('journal_entry_lines')->where('journal_entry_id',$journal->id)->get()as$line){
                if($line->financial_account_id){if((int)$line->financial_account_id!==(int)$v->financial_account_id)throw new \RuntimeException('Cash evidence mismatch.');$cashUnits+=HistoricalDecimal::units($receipt?$line->debit:$line->credit);if(HistoricalDecimal::units($receipt?$line->credit:$line->debit)!==0)throw new \RuntimeException('Cash direction mismatch.');}
                else{if($line->party_type!==$v->reference_type||(int)$line->party_id!==(int)$v->reference_id||HistoricalDecimal::units($receipt?$line->debit:$line->credit)!==0)throw new \RuntimeException('Party evidence mismatch.');$actual[$line->account_id]=($actual[$line->account_id]??0)+HistoricalDecimal::units($receipt?$line->credit:$line->debit);}
            }
            ksort($expected);ksort($actual);if($actual!==$expected||$cashUnits!==HistoricalDecimal::units($v->amount))throw new \RuntimeException('Journal/allocation evidence mismatch.');
            $jid=$this->journals->reverse($cid,(int)$v->journal_entry_id,['reason'=>$reason,'entry_date'=>$date,'source_type'=>'VOUCHER_REVERSAL','created_by'=>$uid]);
            foreach($rows as$r){$s=DB::table('invoice_accounting_snapshots')->where('company_id',$cid)->where('id',$r->snapshot_id)->lockForUpdate()->first();$copy=(array)$r;unset($copy['id']);$copy['journal_entry_id']=$jid;$copy['entry_kind']='REVERSE';$copy['reverses_allocation_id']=$r->id;$copy['idempotency_key']='reverse-'.$voucherId;$copy['created_at']=now();$copy['created_by']=$uid;DB::table('voucher_invoice_allocations')->insert($copy);$this->project($s);}
            return$jid;
        });
    }

    public function project(object $s): void
    {
        IsolatedHistoricalAccountingGate::assert();
        HistoricalAccountingAuthorization::assert((int)$s->company_id,(int)$s->branch_id,(int)\Illuminate\Support\Facades\Auth::id(),['vouchers.create','vouchers.delete','returns.post']);
        $s=DB::table('invoice_accounting_snapshots')->where('company_id',$s->company_id)->where('branch_id',$s->branch_id)->where('id',$s->id)->first();
        if(!$s)throw new \RuntimeException('Authoritative snapshot required for status projection.');
        $remaining=HistoricalDecimal::units($this->outstandingDecimal($s));$status=$remaining<=0?'PAID':($remaining<HistoricalDecimal::units($s->base_total)?'PARTIAL':'UNPAID');
        DB::table($s->invoice_type==='SALE'?'sales_invoices':'purchase_invoices')->where('company_id',$s->company_id)->where('id',$s->sales_invoice_id??$s->purchase_invoice_id)->update(['payment_status'=>$status]);
    }
}
