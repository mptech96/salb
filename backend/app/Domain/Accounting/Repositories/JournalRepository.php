<?php

namespace App\Domain\Accounting\Repositories;

use Illuminate\Support\Facades\DB;

class JournalRepository
{
    public function createEntry(array $data): int
    {
        return DB::table('journal_entries')->insertGetId([
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'entry_number' => $data['entry_number'],
            'entry_date' => $data['entry_date'],
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'POSTED',
            'created_by' => $data['created_by'] ?? request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function createLines(int $entryId, int $companyId, array $lines): void
    {
        foreach ($lines as $line) {
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entryId,
                'company_id' => $companyId,
                'account_id' => $line['account_id'],
                'debit' => round((float)($line['debit'] ?? 0), 3),
                'credit' => round((float)($line['credit'] ?? 0), 3),
                'description' => $line['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function nextEntryNumber(int $companyId): string
    {
        $last = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->max('id') ?? 0;

        return 'JE-' . date('Y') . '-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }

    public function findWithLines(int $companyId, int $entryId)
    {
        $entry = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->where('id', $entryId)
            ->first();

        if (!$entry) {
            return null;
        }

        $lines = DB::table('journal_entry_lines as l')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)
            ->where('l.journal_entry_id', $entryId)
            ->select('l.*', 'a.account_code', 'a.account_name')
            ->get();

        return [
            'entry' => $entry,
            'lines' => $lines,
        ];
    }
}