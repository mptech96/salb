<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetMovement;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetSaleService
{
    /**
     * بيع أصل ثابت واستبعاده محاسبيًا.
     *
     * القيد:
     * مدين: حساب التحصيل بقيمة البيع
     * مدين: مجمع الإهلاك
     * مدين: خسارة البيع عند وجود خسارة
     * دائن: حساب الأصل بالتكلفة
     * دائن: ربح البيع عند وجود ربح
     */
    public function sell(
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
                    'لا يمكن بيع أصل غير نشط.'
                );
            }

            if ($asset->asset_status === 'SOLD') {
                throw new \Exception(
                    'تم بيع هذا الأصل مسبقًا.'
                );
            }

            if ($asset->asset_status === 'DISPOSED') {
                throw new \Exception(
                    'لا يمكن بيع أصل مشطوب.'
                );
            }

            if ($asset->asset_status === 'UNDER_MAINTENANCE') {
                throw new \Exception(
                    'لا يمكن بيع الأصل أثناء وجوده تحت الصيانة.'
                );
            }

            $saleDate = Carbon::parse(
                $data['sale_date'] ?? now()->toDateString()
            )->toDateString();

            $saleAmount = round(
                (float) ($data['sale_amount'] ?? 0),
                3
            );

            if ($saleAmount < 0) {
                throw new \Exception(
                    'قيمة بيع الأصل لا يمكن أن تكون سالبة.'
                );
            }

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

            $gain = round(
                max($saleAmount - $bookValue, 0),
                3
            );

            $loss = round(
                max($bookValue - $saleAmount, 0),
                3
            );

            $assetAccountId =
                $data['asset_account_id']
                ?? $asset->asset_account_id;

            $accumulatedAccountId =
                $data['accumulated_account_id']
                ?? $asset->accumulated_account_id;

            $collectionAccountId =
                $data['collection_account_id'] ?? null;

            $gainAccountId =
                $data['disposal_gain_account_id']
                ?? $asset->category?->disposal_gain_account_id;

            $lossAccountId =
                $data['disposal_loss_account_id']
                ?? $asset->category?->disposal_loss_account_id;

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

            if (!$collectionAccountId) {
                throw new \Exception(
                    'حساب تحصيل قيمة البيع غير محدد.'
                );
            }

            if ($gain > 0 && !$gainAccountId) {
                throw new \Exception(
                    'حساب أرباح بيع الأصل غير محدد.'
                );
            }

            if ($loss > 0 && !$lossAccountId) {
                throw new \Exception(
                    'حساب خسائر بيع الأصل غير محدد.'
                );
            }

            $journalLines = [];

            if ($saleAmount > 0) {
                $journalLines[] = [
                    'account_id' => (int) $collectionAccountId,
                    'debit' => $saleAmount,
                    'credit' => 0,
                    'description' =>
                        'تحصيل قيمة بيع الأصل '
                        . $asset->asset_name,
                ];
            }

            if ($accumulatedDepreciation > 0) {
                $journalLines[] = [
                    'account_id' => (int) $accumulatedAccountId,
                    'debit' => $accumulatedDepreciation,
                    'credit' => 0,
                    'description' =>
                        'إقفال مجمع إهلاك الأصل '
                        . $asset->asset_name,
                ];
            }

            if ($loss > 0) {
                $journalLines[] = [
                    'account_id' => (int) $lossAccountId,
                    'debit' => $loss,
                    'credit' => 0,
                    'description' =>
                        'خسارة بيع الأصل '
                        . $asset->asset_name,
                ];
            }

            $journalLines[] = [
                'account_id' => (int) $assetAccountId,
                'debit' => 0,
                'credit' => $purchaseCost,
                'description' =>
                    'استبعاد تكلفة الأصل '
                    . $asset->asset_name,
            ];

            if ($gain > 0) {
                $journalLines[] = [
                    'account_id' => (int) $gainAccountId,
                    'debit' => 0,
                    'credit' => $gain,
                    'description' =>
                        'ربح بيع الأصل '
                        . $asset->asset_name,
                ];
            }

            $totalDebit = round(
                collect($journalLines)->sum(
                    fn (array $line) =>
                        (float) ($line['debit'] ?? 0)
                ),
                3
            );

            $totalCredit = round(
                collect($journalLines)->sum(
                    fn (array $line) =>
                        (float) ($line['credit'] ?? 0)
                ),
                3
            );

            if (abs($totalDebit - $totalCredit) > 0.001) {
                throw new \Exception(
                    'قيد بيع الأصل غير متوازن. المدين: '
                    . number_format($totalDebit, 3)
                    . '، الدائن: '
                    . number_format($totalCredit, 3)
                );
            }

            $journalId = app(AccountingService::class)->post([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'entry_date' => $saleDate,
                'source_type' => 'FIXED_ASSET_SALE',
                'source_id' => $asset->id,
                'description' =>
                    'بيع الأصل '
                    . $asset->asset_code
                    . ' - '
                    . $asset->asset_name,
                'lines' => $journalLines,
            ]);

            $movement = FixedAssetMovement::create([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'asset_id' => $asset->id,
                'movement_type' => 'SALE',
                'movement_date' => $saleDate,
                'amount' => $saleAmount,
                'from_branch_id' => $asset->branch_id,
                'to_branch_id' => null,
                'worker_id' => $asset->responsible_worker_id,
                'journal_entry_id' => $journalId,
                'reference_no' =>
                    $data['reference_no']
                    ?? (
                        'SAL-'
                        . $asset->asset_code
                        . '-'
                        . Carbon::parse($saleDate)->format('Ymd')
                    ),
                'notes' =>
                    $data['notes']
                    ?? (
                        'بيع أصل ثابت. قيمة البيع: '
                        . number_format($saleAmount, 3)
                        . '، القيمة الدفترية: '
                        . number_format($bookValue, 3)
                        . '، الربح: '
                        . number_format($gain, 3)
                        . '، الخسارة: '
                        . number_format($loss, 3)
                    ),
                'created_by' => $userId,
            ]);

            $asset->update([
                'asset_status' => 'SOLD',
                'is_active' => false,
                'current_book_value' => 0,
                'journal_entry_id' => $journalId,
                'updated_by' => $userId,
            ]);

            return [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->asset_name,
                'movement_id' => $movement->id,
                'journal_entry_id' => $journalId,
                'sale_date' => $saleDate,
                'sale_amount' => $saleAmount,
                'purchase_cost' => $purchaseCost,
                'accumulated_depreciation' =>
                    $accumulatedDepreciation,
                'book_value_before_sale' => $bookValue,
                'gain_amount' => $gain,
                'loss_amount' => $loss,
                'book_value_after_sale' => 0,
                'asset_status' => 'SOLD',
                'is_active' => false,
            ];
        });
    }
}