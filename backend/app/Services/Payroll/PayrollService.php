<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private SalaryCalculator $calculator
    ) {}

    public function generate(array $data): array
    {
        return DB::transaction(function () use ($data) {

            $companyId = (int) $data['company_id'];
            $branchId  = $data['branch_id'] ?? null;
            $month     = date('Y-m-01', strtotime($data['salary_month']));

            $exists = DB::table('worker_salary_runs')
                ->where('company_id', $companyId)
                ->where('salary_month', $month)
                ->first();

            if ($exists) {
                throw new \Exception('يوجد مسير رواتب لهذا الشهر مسبقًا.');
            }

            $runId = DB::table('worker_salary_runs')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'salary_month' => $month,
                'run_number' => 'PAY-' . date('Y-m', strtotime($month)),
                'status' => 'DRAFT',
                'created_by' => request()->header('X-User-ID'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $run = DB::table('worker_salary_runs')->where('id', $runId)->first();

            $workers = DB::table('workers')
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->where('worker_status', 'ACTIVE')
                ->get();

            if ($workers->count() === 0) {
                throw new \Exception('لا يوجد موظفون نشطون لإنشاء مسير الرواتب.');
            }

            $total = 0;

            foreach ($workers as $worker) {
                $calc = $this->calculator->calculate($worker, $run);

                $total += $calc['net_salary'];

                DB::table('worker_salary_lines')->insert([
                    'company_id' => $companyId,
                    'salary_run_id' => $runId,
                    'worker_id' => $worker->id,

                    'salary_type' => $calc['salary_type'],
                    'rate_amount' => $calc['rate_amount'],
                    'work_units' => $calc['work_units'],

                    'basic_amount' => $calc['basic_amount'],
                    'overtime_amount' => $calc['overtime_amount'],
                    'allowance_amount' => $calc['allowance_amount'],
                    'bonus_amount' => $calc['bonus_amount'],
                    'commission_amount' => $calc['commission_amount'],
                    'loan_deduction' => $calc['loan_deduction'],
                    'other_deduction' => $calc['other_deduction'],
                    'net_salary' => $calc['net_salary'],

                    'payment_status' => 'UNPAID',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('worker_salary_runs')
                ->where('id', $runId)
                ->update([
                    'total_amount' => round($total, 3),
                    'updated_at' => now(),
                ]);

            return [
                'salary_run_id' => $runId,
                'total_amount' => round($total, 3),
                'workers_count' => $workers->count(),
            ];
        });
    }
}