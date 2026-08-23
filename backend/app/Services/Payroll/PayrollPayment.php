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
            $companyId=(int)request()->header('X-Company-ID');$requestBranch=request()->header('X-Branch-ID');
            $run=DB::table('worker_salary_runs')->where('company_id',$companyId)->where('id',$salaryRunId)->lockForUpdate()->first();
            if(!$run)throw new \Exception('مسير الرواتب غير موجود.');if($run->status!=='APPROVED')throw new \Exception('يجب اعتماد المسير أولاً.');if($run->paid_at)throw new \Exception('تم صرف هذا المسير مسبقاً.');
            $branchId=(int)($requestBranch?:$run->branch_id);if(!$branchId)throw new \Exception('فرع مسير الرواتب غير محدد.');
            $payableAccount=$this->support->setting($companyId,'WORKER_PAYABLE_ACCOUNT');
            $fa=$this->support->financialAccount($companyId,$branchId,$paymentMethod,$financialAccountId,'PAYMENT');$cashAccount=(int)$fa->gl_account_id;
            $baseCurrency=$this->money->baseCurrency($companyId);$currency=strtoupper((string)$fa->currency_code);$payDate=date('Y-m-d');$rate=$this->money->rate($companyId,$currency,$payDate);$baseAmount=round((float)$run->total_amount,3);$foreignAmount=$currency!==$baseCurrency?round($baseAmount/$rate,3):0;
            $journalId=app(AccountingService::class)->post(['company_id'=>$companyId,'branch_id'=>$branchId,'entry_date'=>$payDate,'source_type'=>'PAYROLL_PAYMENT','source_id'=>$salaryRunId,'description'=>'صرف مسير رواتب '.$run->run_number,'currency_code'=>$currency,'exchange_rate'=>$rate,'lines'=>[
                ['account_id'=>$payableAccount,'debit'=>$baseAmount,'credit'=>0,'description'=>'إقفال مستحقات الرواتب'],
                ['account_id'=>$cashAccount,'financial_account_id'=>(int)$fa->id,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>0,'foreign_credit'=>$foreignAmount,'debit'=>0,'credit'=>$baseAmount,'description'=>'صرف رواتب من '.$fa->account_name],
            ],'is_system_generated'=>1]);
            DB::table('worker_salary_runs')->where('id',$salaryRunId)->update(['status'=>'PAID','paid_amount'=>$run->total_amount,'paid_at'=>now(),'updated_at'=>now()]);
            DB::table('worker_salary_lines')->where('salary_run_id',$salaryRunId)->update(['payment_status'=>'PAID','payment_method'=>$paymentMethod,'journal_entry_id'=>$journalId,'paid_at'=>now(),'updated_at'=>now()]);
            return ['salary_run'=>$salaryRunId,'journal_entry_id'=>$journalId,'financial_account_id'=>(int)$fa->id,'financial_account_name'=>$fa->account_name,'paid_amount'=>$run->total_amount,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_amount'=>$foreignAmount];
        });
    }
}
