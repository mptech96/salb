<?php

namespace App\Services\Payroll;

/** Preflight only: the accounting engine's existing 100-line cap is unchanged. */
final class PayrollJournalCapacity
{
    public static function assert(int $plannedLines): void
    {
        if ($plannedLines > 100) {
            throw new \RuntimeException(
                "يتطلب مسير الرواتب {$plannedLines} سطر قيد؛ الحد المعتمد 100 سطر. لم يتم اعتماد أو صرف المسير."
            );
        }
    }
}
