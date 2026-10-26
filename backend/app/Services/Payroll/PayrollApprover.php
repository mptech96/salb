<?php

namespace App\Services\Payroll;

use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;

class PayrollApprover
{
    public function approve(int $salaryRunId): array
    {
        return DB::transaction(function () use ($salaryRunId) {

            $companyId = app(WorkerAccountingSupport::class)->authorize();
            $branchId = \App\Support\TenantScope::branchId();
            app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany($companyId);

            $run = DB::table('worker_salary_runs')
                ->where('company_id', $companyId)
                ->where('id', $salaryRunId)
                ->when($branchId,fn($q)=>$q->where('branch_id',$branchId))
                ->lockForUpdate()
                ->first();

            if (!$run) {
                throw new \Exception('مسير الرواتب غير موجود.');
            }

            if ($run->status !== 'DRAFT') {
                throw new \Exception('لا يمكن اعتماد مسير رواتب غير مسودة.');
            }

            $lines = DB::table('worker_salary_lines')
                ->where('company_id', $companyId)
                ->where('salary_run_id', $salaryRunId)
                ->get();

            if ($lines->count() === 0) {
                throw new \Exception('لا توجد تفاصيل رواتب لاعتماد المسير.');
            }

            $totalBasic = (float) $lines->sum('basic_amount');
            $totalOvertime = (float) $lines->sum('overtime_amount');
            $totalAllowance = (float) $lines->sum('allowance_amount');
            $totalBonus = (float) $lines->sum('bonus_amount');
            $totalCommission = (float) $lines->sum('commission_amount');
            $totalLoans = (float) $lines->sum('loan_deduction');
            $totalNet = (float) $lines->sum('net_salary');

            $plannedLines = ($totalBasic + $totalOvertime + $totalAllowance + $totalBonus > 0 ? 1 : 0);
            foreach ($lines as $line) {
                $plannedLines += (float) $line->loan_deduction > 0 ? 1 : 0;
                $plannedLines += round((float) $line->net_salary - (float) $line->commission_amount, 3) != 0 ? 1 : 0;
            }
            PayrollJournalCapacity::assert($plannedLines);

            $salaryExpenseAccount = $this->settingAccount($companyId, 'SALARY_EXPENSE_ACCOUNT');
            $workerPayableAccount = $this->settingAccount($companyId, 'WORKER_PAYABLE_ACCOUNT');
            $workerLoanAccount = $this->settingAccount($companyId, 'WORKER_LOAN_ACCOUNT');
            foreach($lines as $line){
                if(!DB::table('workers')->where('company_id',$companyId)->where('branch_id',$run->branch_id)->where('id',$line->worker_id)->exists())throw new \RuntimeException('عامل في المسير خارج نطاق الفرع.');
                $loans=DB::table('worker_loans')->where('company_id',$companyId)->where('branch_id',$run->branch_id)->where('worker_id',$line->worker_id)->whereNull('salary_run_id')->lockForUpdate()->get();
                if($loans->isNotEmpty()&&DB::table('vouchers as v')->join('voucher_types as t','t.id','=','v.voucher_type_id')->join('journal_entries as j','j.id','=','v.journal_entry_id')
                    ->join('journal_entry_lines as l','l.journal_entry_id','=','j.id')->where('v.company_id',$companyId)->where('v.reference_type','WORKER')->where('v.reference_id',$line->worker_id)
                    ->where('t.type_code','RECEIPT')->where('j.status','POSTED')->whereNull('j.reversed_at')->where('l.account_id',$workerLoanAccount)->where('l.credit','>',0)->exists())
                    throw new \RuntimeException('يوجد تحصيل سلفة للعامل دون تخصيص لسلفة محددة؛ يلزم مطابقة محاسبية قبل خصم السلف من المسير.');
                if(round((float)$loans->sum('amount'),3)!==round((float)$line->loan_deduction,3))throw new \RuntimeException('تغيرت سلف العامل بعد إنشاء المسير؛ أعد إنشاء المسودة.');
                foreach($loans as $loan){
                    $entry=DB::table('journal_entries')->where('company_id',$companyId)->where('branch_id',$run->branch_id)->where('id',$loan->journal_entry_id)->where('source_type','WORKER_LOAN')->where('source_id',$loan->id)->where('status','POSTED')->whereNull('reversed_at')->first();
                    $debits=$entry?DB::table('journal_entry_lines')->where('journal_entry_id',$entry->id)->where('debit','>',0)->get():collect();
                    if($debits->count()!==1||(int)$debits[0]->account_id!==$workerLoanAccount||round((float)$debits[0]->debit,3)!==round((float)$loan->amount,3))throw new \RuntimeException('حساب السلفة الأصلية لا يطابق التسوية؛ يلزم مراجعة محاسبية.');
                }
            }
            $accrued=DB::table('worker_commissions')->where('company_id',$companyId)->where('branch_id',$run->branch_id)
                ->where('status','APPROVED')->whereIn('worker_id',$lines->pluck('worker_id')->all())
                ->whereBetween('commission_date',[date('Y-m-01',strtotime($run->salary_month)),date('Y-m-t',strtotime($run->salary_month))])->lockForUpdate()->get();
            if(round((float)$accrued->sum('amount'),3)!==round($totalCommission,3))throw new \RuntimeException('تغيرت العمولات بعد إنشاء المسير؛ أعد إنشاء المسودة قبل الاعتماد.');
            foreach($accrued as $commission){
                if(app(WorkerAccountingSupport::class)->payable($commission)!==$workerPayableAccount)throw new \RuntimeException('حساب استحقاق العمولة التاريخي يختلف عن إعداد المسير؛ يلزم مراجعة محاسبية.');
            }

            $journalLines = [];

            $salaryExpense = $totalBasic + $totalOvertime + $totalAllowance + $totalBonus;

            if ($salaryExpense > 0) {
                $journalLines[] = [
                    'account_id' => $salaryExpenseAccount,
                    'debit' => round($salaryExpense, 3),
                    'credit' => 0,
                    'description' => 'مصروف رواتب',
                ];
            }

            // APPROVED commissions already have an expense/payable journal.
            // Payroll consumes that payable; it must not accrue the expense again.

            foreach($lines as $workerLine){
            if ((float)$workerLine->loan_deduction > 0) {
                $journalLines[] = [
                    'account_id' => $workerLoanAccount,
                    'party_type'=>'WORKER','party_id'=>(int)$workerLine->worker_id,
                    'debit' => 0,
                    'credit' => round((float)$workerLine->loan_deduction, 3),
                    'description' => 'خصم سلف عمال من الرواتب',
                ];
            }

            $newPayable=round((float)$workerLine->net_salary-(float)$workerLine->commission_amount,3);
            if($newPayable!=0)$journalLines[] = [
                'account_id' => $workerPayableAccount,
                'party_type'=>'WORKER','party_id'=>(int)$workerLine->worker_id,
                'debit' => $newPayable<0?abs($newPayable):0,
                'credit' => max($newPayable,0),
                'description' => 'استحقاق صافي رواتب العمال',
            ];
            }

            $journalId = app(AccountingService::class)->post([
                'company_id' => $companyId,
                'branch_id' => $branchId ?: $run->branch_id,
                'entry_date' => date('Y-m-t', strtotime($run->salary_month)),
                'source_type' => 'PAYROLL',
                'source_id' => $salaryRunId,
                'description' => 'اعتماد مسير رواتب ' . $run->run_number,
                'created_by' => auth()->id(),
                'lines' => $journalLines,
            ]);

            DB::table('worker_salary_runs')
                ->where('id', $salaryRunId)
                ->update([
                    'status' => 'APPROVED',
                    'journal_entry_id' => $journalId,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('worker_loans')
                ->where('company_id', $companyId)
                ->whereNull('salary_run_id')
                ->where('branch_id',$run->branch_id)
                ->whereIn('worker_id', $lines->pluck('worker_id')->toArray())
                ->update([
                    'salary_run_id' => $salaryRunId,
                    'updated_at' => now(),
                ]);

            DB::table('worker_commissions')
                ->where('company_id', $companyId)
                ->where('status', 'APPROVED')
                ->where('branch_id',$run->branch_id)
                ->whereIn('worker_id', $lines->pluck('worker_id')->toArray())
                ->whereBetween('commission_date', [
                    date('Y-m-01', strtotime($run->salary_month)),
                    date('Y-m-t', strtotime($run->salary_month)),
                ])
                ->update([
                    'status' => 'IN_PAYROLL',
                    'updated_at' => now(),
                ]);

            return [
                'salary_run_id' => $salaryRunId,
                'journal_entry_id' => $journalId,
                'total_net' => round($totalNet, 3),
            ];
        });
    }

    private function settingAccount(int $companyId, string $key): int
    {
        $accountId = DB::table('accounting_settings')
            ->where('company_id', $companyId)
            ->where('setting_key', $key)
            ->value('account_id');

        if (!$accountId) {
            throw new \Exception('الحساب غير مضبوط في الإعدادات: ' . $key);
        }

        return (int) $accountId;
    }
}
