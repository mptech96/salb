<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

class SalaryCalculator
{
    public function calculate($worker, $salaryRun): array
    {
        $companyId = (int) $salaryRun->company_id;

        $basic = $this->basicSalary($worker);
        $overtime = $this->overtimeAmount($worker, $salaryRun);
        $commission = $this->approvedCommissions($companyId, $worker->id, $salaryRun->salary_month);
        $loanDeduction = $this->loanDeduction($companyId, $worker->id);

        $allowance = 0;
        $bonus = 0;
        $otherDeduction = 0;

        $net = $basic
            + $overtime
            + $allowance
            + $bonus
            + $commission
            - $loanDeduction
            - $otherDeduction;

        return [
            'salary_type' => $worker->salary_type ?? 'MONTHLY',
            'rate_amount' => round((float) ($worker->salary_rate ?? 0), 3),
            'work_units' => 1,
            'basic_amount' => round($basic, 3),
            'overtime_amount' => round($overtime, 3),
            'allowance_amount' => round($allowance, 3),
            'bonus_amount' => round($bonus, 3),
            'commission_amount' => round($commission, 3),
            'loan_deduction' => round($loanDeduction, 3),
            'other_deduction' => round($otherDeduction, 3),
            'net_salary' => round(max($net, 0), 3),
        ];
    }

    private function basicSalary($worker): float
    {
        return round((float) ($worker->salary_rate ?? 0), 3);
    }

    private function overtimeAmount($worker, $salaryRun): float
    {
        return 0;
    }

    private function approvedCommissions(int $companyId, int $workerId, string $salaryMonth): float
    {
        $from = date('Y-m-01', strtotime($salaryMonth));
        $to = date('Y-m-t', strtotime($salaryMonth));

        return (float) DB::table('worker_commissions')
            ->where('company_id', $companyId)
            ->where('worker_id', $workerId)
            ->where('status', 'APPROVED')
            ->whereBetween('commission_date', [$from, $to])
            ->sum('amount');
    }

    private function loanDeduction(int $companyId, int $workerId): float
    {
        return (float) DB::table('worker_loans')
            ->where('company_id', $companyId)
            ->where('worker_id', $workerId)
            ->whereNull('salary_run_id')
            ->sum('amount');
    }
}