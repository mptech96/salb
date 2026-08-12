<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetMovement;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetDisposalService
{
    /**
     * شطب أصل ثابت نهائيًا.
     *
     * القيد المحاسبي:
     *
     * من حـ/ مجمع الإهلاك
     * من حـ/ خسائر شطب الأصل، عند وجود قيمة دفترية
     *     إلى حـ/ الأصل الثابت
     */
    public function dispose(
        int $assetId,
        array $data,
        array $context = []
    ): array {
        return DB::transaction(function () use (
            $assetId,
            $data,
            $context
        ) {
            $companyId = (int) ($context['company_id'] ?? 0);

            $userId = isset($context['created_by'])
                ? (int) $context['created_by']
                : null;

            if (!$companyId) {
                throw new \Exception(
                    'لم يتم تحديد الشركة الحالية.'
                );
            }

            $asset = FixedAsset::query()
                ->with('category')
                ->where('company_id', $companyId)
                ->where('id', $assetId)
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                throw new \Exception(
                    'الأصل المطلوب غير موجود.'
                );
            }

            if (!$asset->is_active) {
                throw new \Exception(
                    'لا يمكن شطب أصل غير نشط.'
                );
            }

            if ($asset->asset_status === 'SOLD') {
                throw new \Exception(
                    'لا يمكن شطب أصل تم بيعه مسبقًا.'
                );
            }

            if ($asset->asset_status === 'DISPOSED') {
                throw new \Exception(
                    'تم شطب هذا الأصل مسبقًا.'
                );
            }

            if (
                $asset->asset_status ===
                'UNDER_MAINTENANCE'
            ) {
                throw new \Exception(
                    'لا يمكن شطب الأصل أثناء وجوده تحت الصيانة.'
                );
            }

            $disposalDate = Carbon::parse(
                $data['disposal_date']
                    ?? now()->toDateString()
            )->toDateString();

            $purchaseCost = round(
                (float) $asset->purchase_cost,
                3
            );

            $accumulatedDepreciation = round(
                (float) $asset->accumulated_depreciation,
                3
            );

            $bookValue = round(
                (float) $asset->current_book_value,
                3
            );

            if ($purchaseCost < 0) {
                throw new \Exception(
                    'تكلفة الأصل غير صحيحة.'
                );
            }

            if ($accumulatedDepreciation < 0) {
                throw new \Exception(
                    'مجمع إهلاك الأصل غير صحيح.'
                );
            }

            if ($bookValue < 0) {
                throw new \Exception(
                    'القيمة الدفترية للأصل غير صحيحة.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | تحديد الحسابات
            |--------------------------------------------------------------------------
            */

            $assetAccountId =
                $data['asset_account_id']
                ?? $asset->asset_account_id;

            $accumulatedAccountId =
                $data['accumulated_account_id']
                ?? $asset->accumulated_account_id;

            $lossAccountId =
                $data['disposal_loss_account_id']
                ?? $asset->category
                    ?->disposal_loss_account_id;

            if (!$assetAccountId) {
                throw new \Exception(
                    'حساب الأصل الثابت غير محدد.'
                );
            }

            if (
                $accumulatedDepreciation > 0
                && !$accumulatedAccountId
            ) {
                throw new \Exception(
                    'حساب مجمع الإهلاك غير محدد.'
                );
            }

            if ($bookValue > 0 && !$lossAccountId) {
                throw new \Exception(
                    'حساب خسائر شطب الأصل غير محدد في فئة الأصل.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | إعداد سطور القيد
            |--------------------------------------------------------------------------
            */

            $journalLines = [];

            if ($accumulatedDepreciation > 0) {
                $journalLines[] = [
                    'account_id' =>
                        (int) $accumulatedAccountId,

                    'debit' =>
                        $accumulatedDepreciation,

                    'credit' => 0,

                    'description' =>
                        'إقفال مجمع إهلاك الأصل '
                        . $asset->asset_name,
                ];
            }

            if ($bookValue > 0) {
                $journalLines[] = [
                    'account_id' =>
                        (int) $lossAccountId,

                    'debit' =>
                        $bookValue,

                    'credit' => 0,

                    'description' =>
                        'خسارة شطب الأصل '
                        . $asset->asset_name,
                ];
            }

            $journalLines[] = [
                'account_id' =>
                    (int) $assetAccountId,

                'debit' => 0,

                'credit' =>
                    $purchaseCost,

                'description' =>
                    'استبعاد تكلفة الأصل '
                    . $asset->asset_name,
            ];

            $totalDebit = round(
                collect($journalLines)
                    ->sum(
                        fn (array $line) =>
                            (float) ($line['debit'] ?? 0)
                    ),
                3
            );

            $totalCredit = round(
                collect($journalLines)
                    ->sum(
                        fn (array $line) =>
                            (float) ($line['credit'] ?? 0)
                    ),
                3
            );

            /*
            |--------------------------------------------------------------------------
            | التحقق من توازن القيد
            |--------------------------------------------------------------------------
            */

            if (
                abs($totalDebit - $totalCredit)
                > 0.001
            ) {
                throw new \Exception(
                    'قيد شطب الأصل غير متوازن. '
                    . 'المدين: '
                    . number_format($totalDebit, 3)
                    . '، الدائن: '
                    . number_format($totalCredit, 3)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | إنشاء القيد المحاسبي
            |--------------------------------------------------------------------------
            */

            $journalId = app(
                AccountingService::class
            )->post([
                'company_id' =>
                    $companyId,

                'branch_id' =>
                    $asset->branch_id,

                'entry_date' =>
                    $disposalDate,

                'source_type' =>
                    'FIXED_ASSET_DISPOSAL',

                'source_id' =>
                    $asset->id,

                'description' =>
                    'شطب الأصل '
                    . $asset->asset_code
                    . ' - '
                    . $asset->asset_name,

                'lines' =>
                    $journalLines,
            ]);

            /*
            |--------------------------------------------------------------------------
            | إنشاء حركة الشطب
            |--------------------------------------------------------------------------
            */

            $movement = FixedAssetMovement::create([
                'company_id' =>
                    $companyId,

                'branch_id' =>
                    $asset->branch_id,

                'asset_id' =>
                    $asset->id,

                'movement_type' =>
                    'DISPOSAL',

                'movement_date' =>
                    $disposalDate,

                'amount' =>
                    $bookValue,

                'from_branch_id' =>
                    $asset->branch_id,

                'to_branch_id' =>
                    null,

                'worker_id' =>
                    $asset->responsible_worker_id,

                'journal_entry_id' =>
                    $journalId,

                'reference_no' =>
                    $data['reference_no']
                    ?? (
                        'DSP-'
                        . $asset->asset_code
                        . '-'
                        . Carbon::parse(
                            $disposalDate
                        )->format('Ymd')
                    ),

                'notes' =>
                    $data['notes']
                    ?? (
                        'شطب نهائي للأصل. '
                        . 'التكلفة: '
                        . number_format(
                            $purchaseCost,
                            3
                        )
                        . '، مجمع الإهلاك: '
                        . number_format(
                            $accumulatedDepreciation,
                            3
                        )
                        . '، القيمة الدفترية: '
                        . number_format(
                            $bookValue,
                            3
                        )
                    ),

                'created_by' =>
                    $userId,
            ]);

            /*
            |--------------------------------------------------------------------------
            | تحديث الأصل
            |--------------------------------------------------------------------------
            */

            $asset->update([
                'asset_status' =>
                    'DISPOSED',

                'is_active' =>
                    false,

                'current_book_value' =>
                    0,

                'journal_entry_id' =>
                    $journalId,

                'updated_by' =>
                    $userId,
            ]);

            return [
                'asset_id' =>
                    $asset->id,

                'asset_code' =>
                    $asset->asset_code,

                'asset_name' =>
                    $asset->asset_name,

                'movement_id' =>
                    $movement->id,

                'journal_entry_id' =>
                    $journalId,

                'disposal_date' =>
                    $disposalDate,

                'purchase_cost' =>
                    $purchaseCost,

                'accumulated_depreciation' =>
                    $accumulatedDepreciation,

                'book_value_before_disposal' =>
                    $bookValue,

                'book_value_after_disposal' =>
                    0,

                'asset_status' =>
                    'DISPOSED',

                'is_active' =>
                    false,
            ];
        });
    }
}