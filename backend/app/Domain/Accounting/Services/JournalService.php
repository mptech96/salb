<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Repositories\JournalRepository;
use App\Services\Accounting\PostingSupport;
use Illuminate\Support\Facades\DB;

class JournalService
{
    public function __construct(
        private JournalRepository $journals,
        private PostingSupport $support
    ) {}

    public function post(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $companyId = (int) ($data['company_id'] ?? 0);
            $branchId = isset($data['branch_id']) && (int) $data['branch_id'] > 0
                ? (int) $data['branch_id']
                : null;
            $allowCompanyLevel = (bool) ($data['allow_company_level'] ?? false);
            $date = trim((string) ($data['entry_date'] ?? ''));
            $lines = $data['lines'] ?? [];
            $description = trim((string) ($data['description'] ?? ''));

            if (!$companyId || !$date) {
                throw new \RuntimeException('الشركة وتاريخ القيد مطلوبان.');
            }

            if (!$branchId && !$allowCompanyLevel) {
                throw new \RuntimeException('اختر الفرع الذي يخص القيد.');
            }

            if ($branchId && !DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->exists()) {
                throw new \RuntimeException('الفرع غير موجود أو لا يتبع الشركة.');
            }

            /*
             * قفل سجل السنة داخل Transaction يمنع سباق أرقام القيود
             * ويضمن أن فحص حالة السنة وتوليد الرقم يتمان كوحدة واحدة.
             */
            $financialYear = DB::table('financial_years')
                ->where('company_id', $companyId)
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->orderByDesc('start_date')
                ->lockForUpdate()
                ->first();

            if (!$financialYear) {
                throw new \RuntimeException('لا توجد سنة مالية تغطي تاريخ القيد.');
            }

            if ((int) $financialYear->is_closed === 1
                && !($data['allow_closed_year'] ?? false)) {
                throw new \RuntimeException('السنة المالية مقفلة ولا تقبل قيودًا جديدة.');
            }

            if (count($lines) < 2) {
                throw new \RuntimeException('القيد يجب أن يحتوي على طرفين على الأقل.');
            }

            if (count($lines) > 100) {
                throw new \RuntimeException('الحد الأعلى للقيد اليدوي هو 100 سطر.');
            }

            if (($data['source_type'] ?? 'MANUAL') === 'MANUAL'
                && mb_strlen($description) < 3) {
                throw new \RuntimeException('اكتب بيانًا واضحًا للقيد.');
            }

            $defaultCostCenter = $this->support->branchCostCenter(
                $companyId,
                $branchId
            );

            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($lines as $index => &$line) {
                $accountId = (int) ($line['account_id'] ?? 0);

                $account = DB::table('accounts')
                    ->where('id', $accountId)
                    ->where('company_id', $companyId)
                    ->where('is_active', 1)
                    ->where('is_group', 0)
                    ->where('allow_posting', 1)
                    ->first();

                if (!$account) {
                    throw new \RuntimeException(
                        'الحساب في السطر رقم ' . ($index + 1) .
                        ' غير صالح للترحيل.'
                    );
                }

                $debit = round((float) ($line['debit'] ?? 0), 3);
                $credit = round((float) ($line['credit'] ?? 0), 3);

                if ($debit < 0 || $credit < 0) {
                    throw new \RuntimeException(
                        'لا تقبل المبالغ السالبة في السطر رقم ' . ($index + 1) . '.'
                    );
                }

                if ($debit > 0 && $credit > 0) {
                    throw new \RuntimeException(
                        'السطر رقم ' . ($index + 1) .
                        ' لا يمكن أن يكون مدينًا ودائنًا معًا.'
                    );
                }

                if ($debit == 0 && $credit == 0) {
                    throw new \RuntimeException(
                        'السطر رقم ' . ($index + 1) .
                        ' يجب أن يحتوي على مبلغ مدين أو دائن.'
                    );
                }

                if ((int) $account->allow_cost_center === 1) {
                    $costCenterId = (int) ($line['cost_center_id'] ?? 0);

                    if (!$costCenterId && $defaultCostCenter) {
                        $costCenterId = $defaultCostCenter;
                    }

                    if ($costCenterId) {
                        $valid = DB::table('cost_centers')
                            ->where('id', $costCenterId)
                            ->where('company_id', $companyId)
                            ->where('is_active', 1)
                            ->when(
                                $branchId,
                                fn ($q) => $q->where(function ($x) use ($branchId) {
                                    $x->whereNull('branch_id')
                                        ->orWhere('branch_id', $branchId);
                                })
                            )
                            ->exists();

                        if (!$valid) {
                            throw new \RuntimeException(
                                'مركز التكلفة غير صالح في السطر رقم ' .
                                ($index + 1) . '.'
                            );
                        }

                        $line['cost_center_id'] = $costCenterId;
                    } elseif (!$allowCompanyLevel) {
                        throw new \RuntimeException(
                            'الحساب في السطر رقم ' . ($index + 1) .
                            ' يتطلب مركز تكلفة.'
                        );
                    }
                } else {
                    $line['cost_center_id'] = null;
                }

                if (!empty($line['party_type']) && empty($line['party_id'])) {
                    throw new \RuntimeException(
                        'الطرف المحاسبي غير مكتمل في السطر رقم ' .
                        ($index + 1) . '.'
                    );
                }

                $totalDebit += $debit;
                $totalCredit += $credit;
            }
            unset($line);

            $totalDebit = round($totalDebit, 3);
            $totalCredit = round($totalCredit, 3);

            if ($totalDebit <= 0) {
                throw new \RuntimeException('قيمة القيد يجب أن تكون أكبر من صفر.');
            }

            if (abs($totalDebit - $totalCredit) > 0.0001) {
                throw new \RuntimeException(
                    'القيد غير متوازن. المدين: ' .
                    number_format($totalDebit, 3) .
                    '، الدائن: ' .
                    number_format($totalCredit, 3)
                );
            }

            $entryNumber = $data['entry_number']
                ?? $this->journals->nextEntryNumber(
                    $companyId,
                    (int) $financialYear->id,
                    $date
                );

            $entryId = $this->journals->createEntry([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'financial_year_id' => $financialYear->id,
                'cost_center_id' => $data['cost_center_id'] ?? $defaultCostCenter,
                'entry_number' => $entryNumber,
                'reference_no' => trim((string) ($data['reference_no'] ?? '')) ?: null,
                'entry_date' => $date,
                'source_type' => $data['source_type'] ?? 'MANUAL',
                'source_id' => $data['source_id'] ?? null,
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
                'description' => $description ?: null,
                'status' => 'POSTED',
                'is_closing_entry' => $data['is_closing_entry'] ?? 0,
                'is_system_generated' => $data['is_system_generated'] ?? 0,
                'created_by' => $data['created_by']
                    ?? request()->attributes->get('authenticated_user_id'),
            ]);

            $this->journals->createLines(
                $entryId,
                $companyId,
                $branchId,
                (int) $financialYear->id,
                $lines
            );

            return $entryId;
        });
    }

    public function reverse(
        int $companyId,
        int $entryId,
        array $context = []
    ): int {
        return DB::transaction(function () use ($companyId, $entryId, $context) {
            $data = $this->journals->findWithLines(
                $companyId,
                $entryId,
                null
            );

            if (!$data) {
                throw new \RuntimeException('القيد المطلوب عكسه غير موجود.');
            }

            $entry = $data['entry'];

            if ($entry->reversed_at) {
                throw new \RuntimeException('تم عكس هذا القيد مسبقًا.');
            }

            if ($entry->reversal_of_id) {
                throw new \RuntimeException('لا يمكن عكس قيد عكسي من هذه الشاشة.');
            }

            $reason = trim((string) ($context['reason'] ?? ''));

            if (mb_strlen($reason) < 5) {
                throw new \RuntimeException('سبب العكس مطلوب ويجب أن يكون واضحًا.');
            }

            $reversalDate = (string) (
                $context['entry_date'] ?? $entry->entry_date
            );

            if ($reversalDate < $entry->entry_date) {
                throw new \RuntimeException(
                    'تاريخ القيد العكسي لا يمكن أن يسبق تاريخ القيد الأصلي.'
                );
            }

            $lines = [];

            foreach ($data['lines'] as $line) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'description' => 'عكس: ' . ($line->description ?? ''),
                ];
            }

            $reversalEntryId = $this->post([
                'company_id' => $companyId,
                'branch_id' => $entry->branch_id,
                'entry_date' => $reversalDate,
                'reference_no' => 'REV-' . $entry->entry_number,
                'source_type' => $context['source_type'] ?? 'REVERSAL',
                'source_id' => $entryId,
                'reversal_of_id' => $entryId,
                'description' => 'عكس القيد ' . $entry->entry_number .
                    ' — السبب: ' . $reason,
                'lines' => $lines,
                'allow_company_level' => $entry->branch_id === null,
                'allow_closed_year' => $context['allow_closed_year'] ?? false,
                'is_closing_entry' => $context['is_closing_entry']
                    ?? $entry->is_closing_entry,
                'is_system_generated' => 1,
                'created_by' => $context['created_by'] ?? null,
            ]);

            DB::table('journal_entries')
                ->where('company_id', $companyId)
                ->where('id', $entryId)
                ->update([
                    'reversed_by_id' => $reversalEntryId,
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                    'updated_at' => now(),
                ]);

            return $reversalEntryId;
        });
    }

    public function show(
        int $companyId,
        int $entryId,
        ?int $branchId = null
    ) {
        return $this->journals->findWithLines(
            $companyId,
            $entryId,
            $branchId
        );
    }
}
