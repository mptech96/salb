<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetMovement;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetDepreciationService
{
    /**
     * إهلاك أصل واحد لشهر محدد.
     */
    public function depreciate(
        int $assetId,
        string $depreciationMonth,
        array $context = []
    ): array {
        return DB::transaction(function () use (
            $assetId,
            $depreciationMonth,
            $context
        ) {
            $companyId = (int) ($context['company_id'] ?? 0);
            $userId = isset($context['created_by'])
                ? (int) $context['created_by']
                : null;

            if (!$companyId) {
                throw new \Exception('لم يتم تحديد الشركة الحالية.');
            }

            $month = Carbon::parse($depreciationMonth)
                ->startOfMonth();

            $asset = FixedAsset::query()
                ->where('company_id', $companyId)
                ->where('id', $assetId)
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                throw new \Exception('الأصل المطلوب غير موجود.');
            }

            if ($asset->opening_balance_batch_id) {
                $openingDate=DB::table('opening_balance_batches')->where('company_id',$companyId)->where('id',$asset->opening_balance_batch_id)->value('opening_date');
                if (!$openingDate || $month->copy()->endOfMonth()->lte(Carbon::parse($openingDate)->endOfDay())) {
                    throw new \Exception('لا يمكن إعادة إهلاك فترة يغطيها الرصيد الافتتاحي للأصل.');
                }
            }

            if (!$asset->is_active) {
                throw new \Exception('لا يمكن إهلاك أصل غير نشط.');
            }

            if ($asset->asset_status !== 'ACTIVE') {
                throw new \Exception(
                    'لا يمكن إهلاك الأصل لأن حالته الحالية ليست نشطة.'
                );
            }

            if ($asset->depreciation_method === 'NO_DEPRECIATION') {
                throw new \Exception('هذا الأصل غير خاضع للإهلاك.');
            }

            if (!$asset->depreciation_start_date) {
                throw new \Exception('تاريخ بداية إهلاك الأصل غير محدد.');
            }

            $depreciationStartMonth = Carbon::parse(
                $asset->depreciation_start_date
            )->startOfMonth();

            if ($month->lt($depreciationStartMonth)) {
                throw new \Exception(
                    'لا يمكن احتساب الإهلاك قبل تاريخ بداية الإهلاك.'
                );
            }

            $alreadyExists = FixedAssetDepreciation::query()
                ->where('company_id', $companyId)
                ->where('asset_id', $asset->id)
                ->whereDate(
                    'depreciation_month',
                    $month->toDateString()
                )
                ->whereIn('status', ['DRAFT', 'POSTED'])
                ->exists();

            if ($alreadyExists) {
                throw new \Exception(
                    'تم احتساب إهلاك هذا الأصل للشهر المحدد مسبقًا.'
                );
            }

            $purchaseCost = round(
                (float) $asset->purchase_cost,
                3
            );

            $salvageValue = round(
                (float) $asset->salvage_value,
                3
            );

            $openingBookValue = round(
                (float) $asset->current_book_value,
                3
            );

            if ($openingBookValue <= $salvageValue) {
                throw new \Exception(
                    'وصل الأصل إلى القيمة المتبقية ولا يوجد إهلاك إضافي.'
                );
            }

            $depreciationAmount = $this->calculateMonthlyDepreciation(
                $asset,
                $purchaseCost,
                $salvageValue,
                $openingBookValue
            );

            $maximumAllowed = round(
                $openingBookValue - $salvageValue,
                3
            );

            $depreciationAmount = round(
                min($depreciationAmount, $maximumAllowed),
                3
            );

            if ($depreciationAmount <= 0) {
                throw new \Exception(
                    'قيمة الإهلاك المحتسبة تساوي صفرًا.'
                );
            }

            if (!$asset->expense_account_id) {
                throw new \Exception(
                    'حساب مصروف الإهلاك غير محدد للأصل.'
                );
            }

            if (!$asset->accumulated_account_id) {
                throw new \Exception(
                    'حساب مجمع الإهلاك غير محدد للأصل.'
                );
            }

            $newAccumulatedDepreciation = round(
                (float) $asset->accumulated_depreciation
                + $depreciationAmount,
                3
            );

            $closingBookValue = round(
                max(
                    $openingBookValue - $depreciationAmount,
                    $salvageValue
                ),
                3
            );

            /*
            |--------------------------------------------------------------------------
            | القيد المحاسبي
            |--------------------------------------------------------------------------
            |
            | مدين: مصروف الإهلاك
            | دائن: مجمع الإهلاك
            |
            */

            $journalId = app(AccountingService::class)->post([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,

                'entry_date' => $month
                    ->copy()
                    ->endOfMonth()
                    ->toDateString(),

                'source_type' => 'FIXED_ASSET_DEPRECIATION',
                'source_id' => $asset->id,

                'description' =>
                    'إهلاك الأصل '
                    . $asset->asset_code
                    . ' عن شهر '
                    . $month->format('Y-m'),

                'lines' => [
                    [
                        'account_id' =>
                            (int) $asset->expense_account_id,

                        'debit' => $depreciationAmount,
                        'credit' => 0,

                        'description' =>
                            'مصروف إهلاك الأصل '
                            . $asset->asset_name,
                    ],
                    [
                        'account_id' =>
                            (int) $asset->accumulated_account_id,

                        'debit' => 0,
                        'credit' => $depreciationAmount,

                        'description' =>
                            'مجمع إهلاك الأصل '
                            . $asset->asset_name,
                    ],
                ],
            ]);

            $depreciation = FixedAssetDepreciation::create([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'asset_id' => $asset->id,

                'depreciation_month' =>
                    $month->toDateString(),

                'opening_book_value' =>
                    $openingBookValue,

                'depreciation_amount' =>
                    $depreciationAmount,

                'accumulated_depreciation' =>
                    $newAccumulatedDepreciation,

                'closing_book_value' =>
                    $closingBookValue,

                'journal_entry_id' => $journalId,
                'status' => 'POSTED',
                'created_by' => $userId,
            ]);

            $asset->update([
                'accumulated_depreciation' =>
                    $newAccumulatedDepreciation,

                'current_book_value' =>
                    $closingBookValue,

                'last_depreciation_date' =>
                    $month
                        ->copy()
                        ->endOfMonth()
                        ->toDateString(),

                'updated_by' => $userId,
            ]);

            FixedAssetMovement::create([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'asset_id' => $asset->id,

                'movement_type' => 'DEPRECIATION',

                'movement_date' =>
                    $month
                        ->copy()
                        ->endOfMonth()
                        ->toDateString(),

                'amount' => $depreciationAmount,

                'from_branch_id' => null,
                'to_branch_id' => null,

                'worker_id' =>
                    $asset->responsible_worker_id,

                'journal_entry_id' => $journalId,

                'reference_no' =>
                    'DEP-'
                    . $asset->asset_code
                    . '-'
                    . $month->format('Ym'),

                'notes' =>
                    'إهلاك شهري للأصل عن شهر '
                    . $month->format('Y-m'),

                'created_by' => $userId,
            ]);

            return [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->asset_name,

                'depreciation_id' => $depreciation->id,
                'depreciation_month' =>
                    $month->format('Y-m'),

                'opening_book_value' =>
                    $openingBookValue,

                'depreciation_amount' =>
                    $depreciationAmount,

                'accumulated_depreciation' =>
                    $newAccumulatedDepreciation,

                'closing_book_value' =>
                    $closingBookValue,

                'journal_entry_id' => $journalId,
            ];
        });
    }

    /**
     * احتساب الإهلاك الشهري حسب طريقة الأصل.
     */
    private function calculateMonthlyDepreciation(
        FixedAsset $asset,
        float $purchaseCost,
        float $salvageValue,
        float $openingBookValue
    ): float {
        if ($asset->depreciation_method === 'STRAIGHT_LINE') {
            return $this->calculateStraightLine(
                $purchaseCost,
                $salvageValue,
                (int) $asset->useful_life_months
            );
        }

        if ($asset->depreciation_method === 'DECLINING_BALANCE') {
            return $this->calculateDecliningBalance(
                $openingBookValue,
                (float) $asset->annual_depreciation_rate
            );
        }

        throw new \Exception(
            'طريقة الإهلاك المحددة غير مدعومة.'
        );
    }

    /**
     * القسط الثابت:
     * تكلفة الأصل ناقص القيمة المتبقية على العمر بالأشهر.
     */
    private function calculateStraightLine(
        float $purchaseCost,
        float $salvageValue,
        int $usefulLifeMonths
    ): float {
        if ($usefulLifeMonths <= 0) {
            throw new \Exception(
                'العمر الإنتاجي للأصل يجب أن يكون أكبر من صفر.'
            );
        }

        return round(
            ($purchaseCost - $salvageValue)
            / $usefulLifeMonths,
            3
        );
    }

    /**
     * الرصيد المتناقص:
     * القيمة الدفترية الحالية × النسبة السنوية ÷ 12.
     */
    private function calculateDecliningBalance(
        float $openingBookValue,
        float $annualRate
    ): float {
        if ($annualRate <= 0) {
            throw new \Exception(
                'نسبة الإهلاك السنوية يجب أن تكون أكبر من صفر.'
            );
        }

        return round(
            $openingBookValue
            * ($annualRate / 100)
            / 12,
            3
        );
    }
}
