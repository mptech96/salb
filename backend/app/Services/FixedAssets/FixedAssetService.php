<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetMovement;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    public function create(array $data): FixedAsset
    {
        return DB::transaction(function () use ($data) {

            $companyId = (int) ($data['company_id'] ?? 0);

            if (!$companyId) {
                throw new \Exception('لم يتم تحديد الشركة الحالية.');
            }

            $category = FixedAssetCategory::query()
                ->where('company_id', $companyId)
                ->where('id', $data['category_id'])
                ->where('is_active', true)
                ->first();

            if (!$category) {
                throw new \Exception('فئة الأصل غير موجودة أو غير نشطة.');
            }

            $assetCode = trim((string) $data['asset_code']);

            $exists = FixedAsset::query()
                ->where('company_id', $companyId)
                ->where('asset_code', $assetCode)
                ->exists();

            if ($exists) {
                throw new \Exception('رقم الأصل مستخدم مسبقًا داخل الشركة.');
            }

            $purchaseCost = round(
                (float) ($data['purchase_cost'] ?? 0),
                3
            );

            $salvageValue = round(
                (float) ($data['salvage_value'] ?? 0),
                3
            );

            if ($purchaseCost < 0) {
                throw new \Exception('تكلفة شراء الأصل لا يمكن أن تكون سالبة.');
            }

            if ($salvageValue < 0) {
                throw new \Exception('القيمة المتبقية لا يمكن أن تكون سالبة.');
            }

            if ($salvageValue > $purchaseCost) {
                throw new \Exception(
                    'القيمة المتبقية لا يمكن أن تكون أكبر من تكلفة الأصل.'
                );
            }

            $depreciationMethod =
                $data['depreciation_method']
                ?? $category->depreciation_method
                ?? 'STRAIGHT_LINE';

            $usefulLifeMonths =
                $data['useful_life_months']
                ?? $category->useful_life_months;

            $annualRate =
                $data['annual_depreciation_rate']
                ?? $category->annual_depreciation_rate;

            if (
                $depreciationMethod !== 'NO_DEPRECIATION'
                && (!$usefulLifeMonths || $usefulLifeMonths <= 0)
                && (!$annualRate || $annualRate <= 0)
            ) {
                throw new \Exception(
                    'يجب تحديد العمر الإنتاجي أو نسبة الإهلاك السنوية.'
                );
            }

            $asset = FixedAsset::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,

                'category_id' => $category->id,

                'asset_code' => $assetCode,
                'asset_name' => trim((string) $data['asset_name']),
                'description' => $data['description'] ?? null,

                'serial_number' => $data['serial_number'] ?? null,
                'barcode' => $data['barcode'] ?? null,

                'location' => $data['location'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'responsible_worker_id' =>
                    $data['responsible_worker_id'] ?? null,

                'purchase_date' => $data['purchase_date'] ?? null,
                'purchase_cost' => $purchaseCost,
                'salvage_value' => $salvageValue,
                'current_book_value' => $purchaseCost,

                'depreciation_method' => $depreciationMethod,
                'useful_life_months' => $usefulLifeMonths,
                'annual_depreciation_rate' => $annualRate,
                'accumulated_depreciation' => 0,
                'depreciation_start_date' =>
                    $data['depreciation_start_date']
                    ?? $data['purchase_date']
                    ?? null,
                'last_depreciation_date' => null,

                'asset_account_id' =>
                    $data['asset_account_id']
                    ?? $category->asset_account_id,

                'accumulated_account_id' =>
                    $data['accumulated_account_id']
                    ?? $category->accumulated_depreciation_account_id,

                'expense_account_id' =>
                    $data['expense_account_id']
                    ?? $category->depreciation_expense_account_id,

                'purchase_invoice_id' =>
                    $data['purchase_invoice_id'] ?? null,

                'journal_entry_id' =>
                    $data['journal_entry_id'] ?? null,

                'asset_status' => 'ACTIVE',
                'is_active' => true,

                'created_by' => $data['created_by'] ?? null,
                'updated_by' => $data['created_by'] ?? null,
            ]);

            FixedAssetMovement::create([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'asset_id' => $asset->id,
                'movement_type' => 'PURCHASE',
                'movement_date' =>
                    $asset->purchase_date
                    ? $asset->purchase_date->format('Y-m-d')
                    : now()->toDateString(),
                'amount' => $purchaseCost,
                'from_branch_id' => null,
                'to_branch_id' => $asset->branch_id,
                'worker_id' => $asset->responsible_worker_id,
                'journal_entry_id' => $asset->journal_entry_id,
                'reference_no' =>
                    $data['reference_no']
                    ?? $asset->asset_code,
                'notes' => 'تسجيل أصل ثابت جديد',
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $asset->load('category');
        });
    }
}