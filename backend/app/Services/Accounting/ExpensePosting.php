<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

class ExpensePosting
{
    public function post(array $data): PostingResult
    {
        DB::beginTransaction();

        try {
            $companyId = $data['company_id'];
            $branchId  = $data['branch_id'] ?? null;
            $expenseId = $data['expense_id'];
            $amount    = round((float)$data['amount'], 3);
            $date      = $data['expense_date'];
            $isPaid    = ($data['payment_status'] ?? 'PAID') === 'PAID';

            if ($amount <= 0) {
                throw new \Exception('مبلغ المصروف غير صحيح');
            }

            $expenseAccount = $data['expense_account_id']
                ?? $this->settingAccount($companyId, 'GENERAL_EXPENSE_ACCOUNT');

            $cashAccount = $this->cashOrBankAccount(
                $companyId,
                $data['payment_method'] ?? 'CASH'
            );

            $voucherId = null;

            if ($isPaid) {
                $voucherId = $this->createVoucher($companyId, $branchId, $expenseId, $amount, $date, $data);
            }

            $journalEntryId = $this->createJournal(
                $companyId,
                $branchId,
                $expenseId,
                $amount,
                $date,
                $expenseAccount,
                $cashAccount,
                $isPaid,
                $data
            );

            DB::table('expenses')
                ->where('company_id', $companyId)
                ->where('id', $expenseId)
                ->update([
                    'voucher_id' => $voucherId,
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            DB::commit();

            return PostingResult::success(
                'تم ترحيل المصروف محاسبيًا',
                $journalEntryId,
                $voucherId
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return PostingResult::error($e->getMessage());
        }
    }

    private function createVoucher($companyId, $branchId, $expenseId, $amount, $date, array $data): int
    {
        $lastId = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->max('id') ?? 0;

        $voucherNumber = 'PAY-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);

        return DB::table('vouchers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'voucher_type_id' => 2,
            'voucher_number' => $voucherNumber,
            'voucher_date' => $date,
            'reference_type' => 'EXPENSE',
            'reference_id' => $expenseId,
            'amount' => $amount,
            'payment_method' => $data['payment_method'] ?? 'CASH',
            'notes' => 'سند صرف تلقائي لمصروف رقم ' . $expenseId,
            'created_by' => $data['created_by'] ?? request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJournal(
        $companyId,
        $branchId,
        $expenseId,
        $amount,
        $date,
        $expenseAccount,
        $cashAccount,
        $isPaid,
        array $data
    ): int {
        $entryNumber = $this->nextEntryNumber($companyId);

        $entryId = DB::table('journal_entries')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'entry_number' => $entryNumber,
            'entry_date' => $date,
            'source_type' => 'EXPENSE',
            'source_id' => $expenseId,
            'description' => 'ترحيل مصروف رقم ' . $expenseId,
            'status' => 'POSTED',
            'created_by' => $data['created_by'] ?? request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_entry_lines')->insert([
            [
                'journal_entry_id' => $entryId,
                'company_id' => $companyId,
                'account_id' => $expenseAccount,
                'debit' => $amount,
                'credit' => 0,
                'description' => 'إثبات المصروف',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entryId,
                'company_id' => $companyId,
                'account_id' => $isPaid
                    ? $cashAccount
                    : $this->settingAccount($companyId, 'SUPPLIER_ACCOUNT'),
                'debit' => 0,
                'credit' => $amount,
                'description' => $isPaid ? 'صرف المصروف' : 'مصروف مستحق غير مدفوع',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $entryId;
    }

    private function settingAccount($companyId, string $key): int
    {
        $id = DB::table('accounting_settings')
            ->where('company_id', $companyId)
            ->where('setting_key', $key)
            ->value('account_id');

        if (!$id) {
            throw new \Exception('الحساب غير مضبوط في الإعدادات: ' . $key);
        }

        return (int)$id;
    }

    private function cashOrBankAccount($companyId, string $method): int
    {
        if ($method === 'BANK') {
            return $this->settingAccount($companyId, 'BANK_ACCOUNT');
        }

        return $this->settingAccount($companyId, 'CASH_ACCOUNT');
    }

    private function nextEntryNumber($companyId): string
    {
        $last = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->max('id') ?? 0;

        return 'JE-' . date('Y') . '-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }
}