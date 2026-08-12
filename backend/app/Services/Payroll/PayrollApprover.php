<?php

namespace App\Services\Payroll;

use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;

class PayrollApprover
{
    public function approve(int $salaryRunId): array
    {
        return DB::transaction(function () use ($salaryRunId) {

            $companyId = (int) request()->header('X-Company-ID');
            $branchId  = request()->header('X-Branch-ID');

            $run = DB::table('worker_salary_runs')
                ->where('company_id', $companyId)
                ->where('id', $salaryRunId)
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

            $salaryExpenseAccount = $this->settingAccount($companyId, 'SALARY_EXPENSE_ACCOUNT');
            $commissionExpenseAccount = $this->settingAccount($companyId, 'WORKER_COMMISSION_EXPENSE_ACCOUNT');
            $workerPayableAccount = $this->settingAccount($companyId, 'WORKER_PAYABLE_ACCOUNT');
            $workerLoanAccount = $this->settingAccount($companyId, 'WORKER_LOAN_ACCOUNT');

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

            if ($totalCommission > 0) {
                $journalLines[] = [
                    'account_id' => $commissionExpenseAccount,
                    'debit' => round($totalCommission, 3),
                    'credit' => 0,
                    'description' => 'مصروف عمولات عمال ضمن مسير الرواتب',
                ];
            }

            if ($totalLoans > 0) {
                $journalLines[] = [
                    'account_id' => $workerLoanAccount,
                    'debit' => 0,
                    'credit' => round($totalLoans, 3),
                    'description' => 'خصم سلف عمال من الرواتب',
                ];
            }

            $journalLines[] = [
                'account_id' => $workerPayableAccount,
                'debit' => 0,
                'credit' => round($totalNet, 3),
                'description' => 'استحقاق صافي رواتب العمال',
            ];

            $journalId = app(AccountingService::class)->post([
                'company_id' => $companyId,
                'branch_id' => $branchId ?: $run->branch_id,
                'entry_date' => date('Y-m-t', strtotime($run->salary_month)),
                'source_type' => 'PAYROLL',
                'source_id' => $salaryRunId,
                'description' => 'اعتماد مسير رواتب ' . $run->run_number,
                'lines' => $journalLines,
            ]);

            DB::table('worker_salary_runs')
                ->where('id', $salaryRunId)
                ->update([
                    'status' => 'APPROVED',
                    'journal_entry_id' => $journalId,
                    'approved_by' => request()->header('X-User-ID'),
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('worker_loans')
                ->where('company_id', $companyId)
                ->whereNull('salary_run_id')
                ->whereIn('worker_id', $lines->pluck('worker_id')->toArray())
                ->update([
                    'salary_run_id' => $salaryRunId,
                    'updated_at' => now(),
                ]);

            DB::table('worker_commissions')
                ->where('company_id', $companyId)
                ->where('status', 'APPROVED')
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