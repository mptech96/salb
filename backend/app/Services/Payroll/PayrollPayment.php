<?php
namespace App\Services\Payroll;

use App\Services\Accounting\PostingSupport;
use App\Services\AccountingService;
use App\Services\FinancialAccountService;
use Illuminate\Support\Facades\DB;

class PayrollPayment
{
    public function __construct(private PostingSupport $support, private FinancialAccountService $money){}

    public function pay(int $salaryRunId,string $paymentMethod,?int $financialAccountId=null): array
    {
        return DB::transaction(function()use($salaryRunId,$paymentMethod,$financialAccountId){
            $companyId=app(WorkerAccountingSupport::class)->authorize();$requestBranch=\App\Support\TenantScope::branchId();
            app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany($companyId);
            $run=DB::table('worker_salary_runs')->where('company_id',$companyId)->where('id',$salaryRunId)->when($requestBranch!==null,fn($q)=>$q->where('branch_id',$requestBranch))->lockForUpdate()->first();
            if(!$run)throw new \Exception('مسير الرواتب غير موجود.');if($run->status!=='APPROVED')throw new \Exception('يجب اعتماد المسير أولاً.');if($run->paid_at)throw new \Exception('تم صرف هذا المسير مسبقاً.');
            $branchId=(int)($requestBranch?:$run->branch_id);if(!$branchId)throw new \Exception('فرع مسير الرواتب غير محدد.');
            $payableAccount=$this->support->setting($companyId,'WORKER_PAYABLE_ACCOUNT');
            $original=DB::table('journal_entries')->where('id',$run->journal_entry_id)->where('company_id',$companyId)->where('branch_id',$branchId)->where('source_type','PAYROLL')->where('source_id',$salaryRunId)->where('status','POSTED')->whereNull('reversed_at')->first();
            if(!$original)throw new \RuntimeException('قيد استحقاق المسير الأصلي غير مثبت.');
            $originalPayables=DB::table('journal_entry_lines as l')->join('accounts as a','a.id','=','l.account_id')->where('l.journal_entry_id',$original->id)->where('a.account_type','LIABILITY')->pluck('l.account_id')->unique();
            if($originalPayables->contains(fn($id)=>(int)$id!==$payableAccount))throw new \RuntimeException('تغير حساب استحقاق المسير؛ يلزم مراجعة محاسبية قبل الدفع.');
            $fa=$this->support->financialAccount($companyId,$branchId,$paymentMethod,$financialAccountId,'PAYMENT');$cashAccount=(int)$fa->gl_account_id;
            $baseCurrency=$this->money->baseCurrency($companyId);$currency=strtoupper((string)$fa->currency_code);$payDate=date('Y-m-d');$rate=$this->money->rate($companyId,$currency,$payDate);$baseAmount=round((float)$run->total_amount,3);$foreignAmount=$currency!==$baseCurrency?round($baseAmount/$rate,3):0;
            $salaryLines=DB::table('worker_salary_lines')->where('company_id',$companyId)->where('salary_run_id',$salaryRunId)->get();
            PayrollJournalCapacity::assert(1 + $salaryLines->filter(fn($line) => (float)$line->net_salary > 0)->count());
            $commissions=DB::table('worker_commissions')->where('company_id',$companyId)->where('branch_id',$branchId)->where('status','IN_PAYROLL')->whereIn('worker_id',$salaryLines->pluck('worker_id')->all())
                ->whereBetween('commission_date',[date('Y-m-01',strtotime($run->salary_month)),date('Y-m-t',strtotime($run->salary_month))])->get();
            foreach($commissions as $commission)if(app(WorkerAccountingSupport::class)->payable($commission)!==$payableAccount)throw new \RuntimeException('تغير حساب استحقاق العمولة داخل المسير؛ يلزم مراجعة محاسبية.');
            if(round((float)$salaryLines->sum('net_salary'),3)!==$baseAmount)throw new \RuntimeException('تفاصيل المسير لا تطابق المبلغ المستحق.');
            $partyLines=[];foreach($salaryLines as $line){if((float)$line->net_salary>0)$partyLines[]=['account_id'=>$payableAccount,'party_type'=>'WORKER','party_id'=>(int)$line->worker_id,'debit'=>round((float)$line->net_salary,3),'credit'=>0,'description'=>'إقفال مستحقات العامل'];}
            $journalId=app(AccountingService::class)->post(['company_id'=>$companyId,'branch_id'=>$branchId,'entry_date'=>$payDate,'source_type'=>'PAYROLL_PAYMENT','source_id'=>$salaryRunId,'description'=>'صرف مسير رواتب '.$run->run_number,'currency_code'=>$currency,'exchange_rate'=>$rate,'lines'=>[
                ...$partyLines,
                ['account_id'=>$cashAccount,'financial_account_id'=>(int)$fa->id,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>0,'foreign_credit'=>$foreignAmount,'debit'=>0,'credit'=>$baseAmount,'description'=>'صرف رواتب من '.$fa->account_name],
            ],'is_system_generated'=>1,'created_by'=>auth()->id()]);
            DB::table('worker_salary_runs')->where('id',$salaryRunId)->update(['status'=>'PAID','paid_amount'=>$run->total_amount,'paid_at'=>now(),'updated_at'=>now()]);
            DB::table('worker_salary_lines')->where('salary_run_id',$salaryRunId)->update(['payment_status'=>'PAID','payment_method'=>$paymentMethod,'journal_entry_id'=>$journalId,'paid_at'=>now(),'updated_at'=>now()]);
            return ['salary_run'=>$salaryRunId,'journal_entry_id'=>$journalId,'financial_account_id'=>(int)$fa->id,'financial_account_name'=>$fa->account_name,'paid_amount'=>$run->total_amount,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_amount'=>$foreignAmount];
        });
    }
}
