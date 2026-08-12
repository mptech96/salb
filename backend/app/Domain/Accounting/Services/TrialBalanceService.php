<?php

namespace App\Domain\Accounting\Services;

use Illuminate\Support\Facades\DB;

class TrialBalanceService
{
    public function report(array $filters)
    {
        $query = DB::table('accounts as a')
            ->leftJoin('journal_entry_lines as l', function ($join) use ($filters) {
                $join->on('l.account_id', '=', 'a.id')
                    ->where('l.company_id', '=', $filters['company_id']);
            })
            ->leftJoin('journal_entries as e', function ($join) {
                $join->on('e.id', '=', 'l.journal_entry_id')
                    ->where('e.status', '=', 'POSTED');
            })
            ->where('a.company_id', $filters['company_id'])
            ->where('a.is_active', 1)
            ->where('a.is_group', 0);

        if (!empty($filters['branch_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereNull('l.id')
                    ->orWhere('l.branch_id', $filters['branch_id']);
            });
        }

        if (!empty($filters['financial_year_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereNull('l.id')
                    ->orWhere('l.financial_year_id', $filters['financial_year_id']);
            });
        }

        if (!empty($filters['cost_center_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereNull('l.id')
                    ->orWhere('l.cost_center_id', $filters['cost_center_id']);
            });
        }

        return $query
            ->select(
                'a.id',
                'a.account_code',
                'a.account_name',
                'a.account_type',
                DB::raw('COALESCE(SUM(l.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(l.credit), 0) as total_credit'),
                DB::raw('COALESCE(SUM(l.debit - l.credit), 0) as balance')
            )
            ->groupBy(
                'a.id',
                'a.account_code',
                'a.account_name',
                'a.account_type'
            )
            ->orderBy('a.account_code')
            ->get();
    }
}
