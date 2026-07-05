<?php

namespace App\Modules\Finance\Application\CreateExpense;

use App\Modules\Finance\Infrastructure\Repositories\ExpenseRepository;
use App\Modules\Shared\Kernel\UseCaseResult;
use App\Services\Accounting\AccountingEngine;
use Illuminate\Support\Facades\DB;

class CreateExpenseHandler
{
    public function __construct(
        private ExpenseRepository $expenses,
        private AccountingEngine $accounting
    ) {}

    public function handle(CreateExpenseCommand $cmd): UseCaseResult
    {
        return DB::transaction(function () use ($cmd) {

            $expenseType = DB::table('expense_types')
                ->where('id', $cmd->expenseTypeId)
                ->where(function ($q) use ($cmd) {
                    $q->where('company_id', $cmd->companyId)
                      ->orWhereNull('company_id');
                })
                ->first();

            if (!$expenseType) {
                return UseCaseResult::fail('نوع المصروف غير موجود', 404);
            }

            $expenseId = $this->expenses->create([
                'company_id' => $cmd->companyId,
                'branch_id' => $cmd->branchId,
                'expense_type_id' => $cmd->expenseTypeId,
                'expense_date' => $cmd->expenseDate,
                'scope_type' => $cmd->scopeType,

                'reference_type' => $cmd->scopeType,
                'reference_id' => $cmd->referenceId,

                'shipment_id' => $cmd->shipmentId,
                'car_id' => $cmd->carId,
                'purchase_invoice_id' => $cmd->purchaseInvoiceId,
                'sales_invoice_id' => $cmd->salesInvoiceId,
                'driver_id' => $cmd->driverId,
                'worker_id' => $cmd->workerId,

                'amount' => $cmd->amount,
                'payment_status' => $cmd->paymentStatus,
                'payment_method' => $cmd->paymentMethod,
                'expense_effect' => $cmd->expenseEffect,

                'voucher_id' => null,
                'journal_entry_id' => null,
                'notes' => $cmd->notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $posting = $this->accounting->expense([
                'company_id' => $cmd->companyId,
                'branch_id' => $cmd->branchId,
                'expense_id' => $expenseId,
                'amount' => $cmd->amount,
                'expense_date' => $cmd->expenseDate,
                'payment_status' => $cmd->paymentStatus,
                'payment_method' => $cmd->paymentMethod,
                'expense_account_id' => $expenseType->account_id,
                'created_by' => $cmd->userId,
            ]);

            if (!$posting->success) {
                throw new \Exception($posting->message);
            }

            return UseCaseResult::success('تم إنشاء المصروف وترحيله محاسبيًا', [
                'expense_id' => $expenseId,
                'voucher_id' => $posting->voucherId,
                'journal_entry_id' => $posting->journalEntryId,
            ]);
        });
    }
}