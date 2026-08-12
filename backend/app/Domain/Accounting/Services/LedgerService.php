<?php

namespace App\Domain\Accounting\Services;

use Illuminate\Support\Facades\DB;

class LedgerService
{
    public function accountLedger(array $filters)
    {
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('cost_centers as cc', 'cc.id', '=', 'l.cost_center_id')
            ->where('l.company_id', $filters['company_id'])
            ->where('l.account_id', $filters['account_id'])
            ->where('e.status', 'POSTED');

        if (!empty($filters['branch_id'])) {
            $query->where('l.branch_id', $filters['branch_id']);
        }

        if (!empty($filters['financial_year_id'])) {
            $query->where('l.financial_year_id', $filters['financial_year_id']);
        }

        if (!empty($filters['cost_center_id'])) {
            $query->where('l.cost_center_id', $filters['cost_center_id']);
        }

        return $query
            ->select(
                'e.entry_number',
                'e.entry_date',
                'e.description as entry_description',
                'l.description',
                'l.debit',
                'l.credit',
                'b.branch_name',
                'cc.cost_center_name'
            )
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('l.id')
            ->get();
    }
}
