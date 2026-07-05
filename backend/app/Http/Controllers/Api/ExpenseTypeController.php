$expenseType = DB::table('expense_types')
    ->where('id', $request->expense_type_id)
    ->first();

$expenseAccount = $expenseType->account_id
    ?: $accounting->settingAccount($companyId, 'GENERAL_EXPENSE_ACCOUNT');

$cashAccount = $accounting->settingAccount($companyId, 'CASH_ACCOUNT');

$journalId = $accounting->post([
    'company_id' => $companyId,
    'branch_id' => $branchId,
    'entry_date' => $request->expense_date,
    'source_type' => 'EXPENSE',
    'source_id' => $id,
    'description' => 'مصروف رقم ' . $id,
    'lines' => [
        [
            'account_id' => $expenseAccount,
            'debit' => $request->amount,
            'credit' => 0,
            'description' => 'إثبات المصروف',
        ],
        [
            'account_id' => $cashAccount,
            'debit' => 0,
            'credit' => $request->amount,
            'description' => 'صرف من الصندوق',
        ],
    ],
]);

DB::table('expenses')
    ->where('id', $id)
    ->update([
        'journal_entry_id' => $journalId,
        'updated_at' => now(),
    ]);