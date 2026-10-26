<?php
namespace App\Services\Payroll;

use App\Services\Accounting\HistoricalDecimal as Money;
use App\Services\Accounting\IsolatedHistoricalAccountingGate;
use Illuminate\Support\Facades\DB;

/** Explicit allocation prototype, no automatic matching, no active route. */
final class IsolatedWorkerLoanSettlement
{
    public function record(int $loanId, string $kind, int $sourceId, string $amount): int
    {
        IsolatedHistoricalAccountingGate::assert();
        if(DB::transactionLevel()<1)throw new \RuntimeException('Source posting and allocation require one outer transaction.');
        $company=app(WorkerAccountingSupport::class)->authorize();
        $units=Money::units($amount);
        if($units<=0||!in_array($kind,['RECEIPT','PAYROLL'],true))throw new \RuntimeException('Invalid settlement.');
        return DB::transaction(function()use($loanId,$kind,$sourceId,$amount,$units,$company){
            app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany($company);
            $loan=DB::table('worker_loans')->where('company_id',$company)->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan || $loan->salary_run_id!==null)throw new \RuntimeException('Loan missing or requires historical review.');
            $worker=app(WorkerAccountingSupport::class)->worker((int)$loan->worker_id);
            $branch=app(WorkerAccountingSupport::class)->branch($worker);
            if((int)$loan->branch_id!==$branch)throw new \RuntimeException('Loan branch mismatch.');
            if(!DB::table('worker_operation_requests')->where('company_id',$company)->where('operation','CREATE_LOAN')->where('loan_id',$loanId)->exists())
                throw new \RuntimeException('Legacy loan requires explicit accounting review; no inferred balance.');
            $original=DB::table('journal_entries')->where('id',$loan->journal_entry_id)->where('company_id',$company)->where('branch_id',$branch)
                ->where('source_type','WORKER_LOAN')->where('source_id',$loan->id)->where('status','POSTED')->whereNull('reversed_at')->first();
            $debits=$original?DB::table('journal_entry_lines')->where('journal_entry_id',$original->id)->where('party_type','WORKER')
                ->where('party_id',$worker->id)->where('debit','>',0)->get():collect();
            if($debits->count()!==1||Money::units($debits[0]->debit)!==Money::units($loan->amount))throw new \RuntimeException('Historical advance GL not proved.');
            $baseCurrency=app(\App\Services\FinancialAccountService::class)->baseCurrency($company);
            $originVoucher=DB::table('vouchers')->where('id',$loan->voucher_id)->where('company_id',$company)->where('branch_id',$branch)
                ->where('reference_type','WORKER_LOAN')->where('reference_id',$loanId)->where('journal_entry_id',$original->id)->first();
            if(!$originVoucher || $originVoucher->currency_code!==$baseCurrency || (float)$originVoucher->exchange_rate!==1.0
                || ($original->currency_code!==null && $original->currency_code!==$baseCurrency)
                || ($original->exchange_rate!==null && (float)$original->exchange_rate!==1.0)
                || !DB::table('accounts')->where('id',$debits[0]->account_id)->where('company_id',$company)->where('account_type','ASSET')->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->exists())
                throw new \RuntimeException('Historical account or FX requires accounting approval.');
            if($kind==='RECEIPT'){
                $source=DB::table('vouchers as v')->join('voucher_types as t','t.id','=','v.voucher_type_id')->where('v.id',$sourceId)
                    ->where('v.company_id',$company)->where('v.branch_id',$branch)->where('v.reference_type','WORKER')->where('v.reference_id',$worker->id)
                    ->where('t.type_code','RECEIPT')->select('v.*')->first();
            }else{
                $source=DB::table('worker_salary_lines as s')->join('worker_salary_runs as r','r.id','=','s.salary_run_id')->where('s.id',$sourceId)
                    ->where('s.company_id',$company)->where('s.worker_id',$worker->id)->where('r.company_id',$company)->where('r.branch_id',$branch)
                    ->whereIn('r.status',['APPROVED','PAID'])->select('r.journal_entry_id','r.id as source_document_id')->first();
            }
            $journal=$source?DB::table('journal_entries')->where('id',$source->journal_entry_id)->where('company_id',$company)->where('branch_id',$branch)
                ->where('status','POSTED')->whereNull('reversed_at')->first():null;
            if(!$journal || $journal->currency_code!==$baseCurrency || (float)$journal->exchange_rate!==1.0
                || $journal->source_type!==($kind==='RECEIPT'?'VOUCHER':'PAYROLL')
                || (int)$journal->source_id!==($kind==='RECEIPT'?$sourceId:(int)$source->source_document_id))
                throw new \RuntimeException('Settlement journal lineage or currency mismatch.');
            $credits=$journal?DB::table('journal_entry_lines')->where('journal_entry_id',$journal->id)->where('account_id',$debits[0]->account_id)
                ->where('party_type','WORKER')->where('party_id',$worker->id)->where('credit','>',0)->get():collect();
            if($credits->count()!==1)throw new \RuntimeException('Settlement source or historical GL mismatch.');
            $base=DB::table('worker_loan_settlements')->where('company_id',$company);
            $prior=(clone $base)->where('loan_id',$loanId)->where('source_kind',$kind)->where('source_id',$sourceId)->lockForUpdate()->first();
            if($prior){if(Money::units($prior->amount)!==$units)throw new \RuntimeException('Changed allocation replay.');return (int)$prior->id;}
            // Current locking reads, not an earlier REPEATABLE READ snapshot established
            // by source validation before the shared company lock was acquired.
            $loanRows=(clone $base)->where('loan_id',$loanId)->orderBy('id')->lockForUpdate()->get();
            $sourceRows=(clone $base)->where('source_kind',$kind)->where('source_id',$sourceId)->orderBy('id')->lockForUpdate()->get();
            $settled=fn($rows)=>$rows->reduce(fn($sum,$row)=>$sum+Money::units($row->amount),0);
            $remaining=Money::units($loan->amount)-$settled($loanRows);
            $sourceRemaining=Money::units($credits[0]->credit)-$settled($sourceRows);
            if($units>$remaining||$units>$sourceRemaining)throw new \RuntimeException('Allocation exceeds outstanding loan or source credit.');
            return DB::table('worker_loan_settlements')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'worker_id'=>$worker->id,'loan_id'=>$loanId,
                'receipt_voucher_id'=>$kind==='RECEIPT'?$sourceId:null,'salary_line_id'=>$kind==='PAYROLL'?$sourceId:null,
                'journal_entry_id'=>$journal->id,'journal_line_id'=>$credits[0]->id,'amount'=>Money::format($units),
                'actor_id'=>auth()->id(),'actor_name'=>auth()->user()->name,'created_at'=>now()]);
        });
    }
}
