<?php

namespace App\Services\Payroll;

use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;

class PayrollPayment
{
    public function pay(int $salaryRunId, string $paymentMethod): array
    {
        return DB::transaction(function () use ($salaryRunId, $paymentMethod) {

            $companyId = (int) request()->header('X-Company-ID');
            $branchId  = request()->header('X-Branch-ID');

            $run = DB::table('worker_salary_runs')
                ->where('company_id', $companyId)
                ->where('id', $salaryRunId)
                ->first();

            if (!$run) {
                throw new \Exception('مسير الرواتب غير موجود.');
            }

            if ($run->status !== 'APPROVED') {
                throw new \Exception('يجب اعتماد المسير أولاً.');
            }

            if ($run->paid_at) {
                throw new \Exception('تم صرف هذا المسير مسبقاً.');
            }

            $payableAccount = $this->settingAccount(
                $companyId,
                'WORKER_PAYABLE_ACCOUNT'
            );

            $cashAccount = match ($paymentMethod) {

                'CASH' => $this->settingAccount($companyId, 'CASH_ACCOUNT'),

                'BANK' => $this->settingAccount($companyId, 'BANK_ACCOUNT'),

                default => throw new \Exception('طريقة الدفع غير صحيحة.')
            };

            $journalId = app(AccountingService::class)->post([

                'company_id'=>$companyId,

                'branch_id'=>$branchId ?: $run->branch_id,

                'entry_date'=>date('Y-m-d'),

                'source_type'=>'PAYROLL_PAYMENT',

                'source_id'=>$salaryRunId,

                'description'=>'صرف مسير رواتب '.$run->run_number,

                'lines'=>[

                    [
                        'account_id'=>$payableAccount,
                        'debit'=>$run->total_amount,
                        'credit'=>0,
                        'description'=>'إقفال مستحقات الرواتب'
                    ],

                    [
                        'account_id'=>$cashAccount,
                        'debit'=>0,
                        'credit'=>$run->total_amount,
                        'description'=>'صرف رواتب'
                    ]

                ]

            ]);

            DB::table('worker_salary_runs')

                ->where('id',$salaryRunId)

                ->update([

                    'status'=>'PAID',

                    'paid_amount'=>$run->total_amount,

                    'paid_at'=>now(),

                    'updated_at'=>now()

                ]);

            DB::table('worker_salary_lines')

                ->where('salary_run_id',$salaryRunId)

                ->update([

                    'payment_status'=>'PAID',

                    'payment_method'=>$paymentMethod,

                    'journal_entry_id'=>$journalId,

                    'paid_at'=>now(),

                    'updated_at'=>now()

                ]);

            return [

                'salary_run'=>$salaryRunId,

                'journal_entry_id'=>$journalId,

                'paid_amount'=>$run->total_amount

            ];

        });

    }

    private function settingAccount($companyId,$key)
    {
        $id = DB::table('accounting_settings')

            ->where('company_id',$companyId)

            ->where('setting_key',$key)

            ->value('account_id');

        if(!$id){

            throw new \Exception("الحساب {$key} غير معرف.");

        }

        return (int)$id;
    }

}