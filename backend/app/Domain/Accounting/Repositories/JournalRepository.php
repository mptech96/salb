<?php

namespace App\Domain\Accounting\Repositories;

use Illuminate\Support\Facades\DB;

class JournalRepository
{
    public function createEntry(array $d): int
    {
        return DB::table('journal_entries')->insertGetId([
            'company_id' => $d['company_id'],
            'branch_id' => $d['branch_id'] ?? null,
            'financial_year_id' => $d['financial_year_id'],
            'cost_center_id' => $d['cost_center_id'] ?? null,
            'entry_number' => $d['entry_number'],
            'reference_no' => $d['reference_no'] ?? null,
            'entry_date' => $d['entry_date'],
            'source_type' => $d['source_type'] ?? null,
            'source_id' => $d['source_id'] ?? null,
            'reversal_of_id' => $d['reversal_of_id'] ?? null,
            'description' => $d['description'] ?? null,
            'status' => $d['status'] ?? 'POSTED',
            'is_closing_entry' => (int) ($d['is_closing_entry'] ?? 0),
            'is_system_generated' => (int) ($d['is_system_generated'] ?? 0),
            'created_by' => $d['created_by'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function createLines(
        int $entryId,
        int $companyId,
        ?int $branchId,
        int $financialYearId,
        array $lines
    ): void {
        foreach ($lines as $line) {
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entryId,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'financial_year_id' => $financialYearId,
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'account_id' => $line['account_id'],
                'party_type' => $line['party_type'] ?? null,
                'party_id' => $line['party_id'] ?? null,
                'debit' => round((float) ($line['debit'] ?? 0), 3),
                'credit' => round((float) ($line['credit'] ?? 0), 3),
                'description' => $line['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function nextEntryNumber(
        int $companyId,
        int $financialYearId,
        string $date
    ): string {
        $year = date('Y', strtotime($date));

        $lastNumber = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->where('financial_year_id', $financialYearId)
            ->orderByDesc('id')
            ->value('entry_number');

        $sequence = 1;

        if (is_string($lastNumber)
            && preg_match('/^JE-\d{4}-(\d+)$/', $lastNumber, $m)) {
            $sequence = ((int) $m[1]) + 1;
        } else {
            $sequence = DB::table('journal_entries')
                ->where('company_id', $companyId)
                ->where('financial_year_id', $financialYearId)
                ->count() + 1;
        }

        do {
            $number = 'JE-' . $year . '-' . str_pad($sequence, 6, '0', STR_PAD_LEFT);
            $exists = DB::table('journal_entries')
                ->where('company_id', $companyId)
                ->where('financial_year_id', $financialYearId)
                ->where('entry_number', $number)
                ->exists();

            $sequence++;
        } while ($exists);

        return $number;
    }

    public function findWithLines(
        int $companyId,
        int $entryId,
        ?int $branchId = null
    ) {
        $query = DB::table('journal_entries as e')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->where('e.company_id', $companyId)
            ->where('e.id', $entryId);

        if ($branchId !== null) {
            $query->where('e.branch_id', $branchId);
        }

        $entry = $query
            ->select('e.*', 'b.branch_name', 'u.name as created_by_name')
            ->first();

        if (!$entry) {
            return null;
        }

        $lines = DB::table('journal_entry_lines as l')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('cost_centers as cc', 'cc.id', '=', 'l.cost_center_id')
            ->where('l.company_id', $companyId)
            ->where('l.journal_entry_id', $entryId)
            ->select(
                'l.*',
                'a.account_code',
                'a.account_name',
                'a.normal_side',
                'a.allow_cost_center',
                'cc.cost_center_code',
                'cc.cost_center_name'
            )
            ->orderBy('l.id')
            ->get();

        return [
            'entry' => $entry,
            'lines' => $lines,
        ];
    }
}
