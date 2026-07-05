<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Repositories\JournalRepository;
use Illuminate\Support\Facades\DB;

class JournalService
{
    public function __construct(
        private JournalRepository $journals
    ) {}

    public function post(array $data): int
    {
        return DB::transaction(function () use ($data) {

            $companyId = (int) $data['company_id'];
            $lines = $data['lines'] ?? [];

            if (count($lines) < 2) {
                throw new \Exception('القيد يجب أن يحتوي على طرفين على الأقل.');
            }

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($lines as $index => $line) {
                $debit = round((float)($line['debit'] ?? 0), 3);
                $credit = round((float)($line['credit'] ?? 0), 3);

                if ($debit < 0 || $credit < 0) {
                    throw new \Exception('لا يمكن أن يحتوي القيد على مبالغ سالبة.');
                }

                if ($debit > 0 && $credit > 0) {
                    throw new \Exception('السطر رقم ' . ($index + 1) . ' لا يمكن أن يكون مدين ودائن في نفس الوقت.');
                }

                if ($debit == 0 && $credit == 0) {
                    throw new \Exception('السطر رقم ' . ($index + 1) . ' يجب أن يحتوي على مبلغ مدين أو دائن.');
                }

                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            if (round($totalDebit, 3) !== round($totalCredit, 3)) {
                throw new \Exception(
                    'القيد غير متوازن. إجمالي المدين: ' .
                    number_format($totalDebit, 3) .
                    '، إجمالي الدائن: ' .
                    number_format($totalCredit, 3)
                );
            }

            $entryId = $this->journals->createEntry([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'entry_number' => $data['entry_number'] ?? $this->journals->nextEntryNumber($companyId),
                'entry_date' => $data['entry_date'],
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'POSTED',
                'created_by' => $data['created_by'] ?? request()->header('X-User-ID'),
            ]);

            $this->journals->createLines($entryId, $companyId, $lines);

            return $entryId;
        });
    }

    public function show(int $companyId, int $entryId)
    {
        return $this->journals->findWithLines($companyId, $entryId);
    }
}