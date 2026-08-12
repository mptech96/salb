<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetMaintenance;
use App\Models\FixedAssetMovement;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;

class FixedAssetMaintenanceService
{
    public function create(
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
            $userId = $context['created_by'] ?? null;

            if (!$companyId) {
                throw new \Exception('لم يتم تحديد الشركة الحالية.');
            }

            $asset = FixedAsset::query()
                ->where('company_id', $companyId)
                ->where('id', $assetId)
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                throw new \Exception('الأصل المطلوب غير موجود.');
            }

            if (!$asset->is_active) {
                throw new \Exception('لا يمكن صيانة أصل غير نشط.');
            }

            if (in_array($asset->asset_status, ['SOLD', 'DISPOSED'], true)) {
                throw new \Exception('لا يمكن صيانة أصل مباع أو مشطوب.');
            }

            $maintenanceCost = round(
                (float) ($data['maintenance_cost'] ?? 0),
                3
            );

            if ($maintenanceCost < 0) {
                throw new \Exception('تكلفة الصيانة لا يمكن أن تكون سالبة.');
            }

            $costTreatment = $data['cost_treatment'] ?? 'EXPENSE';

            if (!in_array($costTreatment, ['EXPENSE', 'CAPITALIZE'], true)) {
                throw new \Exception('طريقة معالجة تكلفة الصيانة غير صحيحة.');
            }

            $maintenance = FixedAssetMaintenance::create([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'asset_id' => $asset->id,

                'maintenance_date' =>
                    $data['maintenance_date'] ?? now()->toDateString(),

                'maintenance_type' =>
                    $data['maintenance_type'] ?? null,

                'supplier_name' =>
                    $data['supplier_name'] ?? null,

                'invoice_number' =>
                    $data['invoice_number'] ?? null,

                'maintenance_cost' =>
                    $maintenanceCost,

                'cost_treatment' =>
                    $costTreatment,

                'status' => 'DRAFT',

                'expense_account_id' =>
                    $data['expense_account_id'] ?? null,

                'payment_account_id' =>
                    $data['payment_account_id'] ?? null,

                'description' =>
                    $data['description'] ?? null,

                'notes' =>
                    $data['notes'] ?? null,

                'created_by' =>
                    $userId,
            ]);

            $asset->update([
                'asset_status' => 'UNDER_MAINTENANCE',
                'updated_by' => $userId,
            ]);

            FixedAssetMovement::create([
                'company_id' => $companyId,
                'branch_id' => $asset->branch_id,
                'asset_id' => $asset->id,

                'movement_type' => 'MAINTENANCE',
                'movement_date' =>
                    $maintenance->maintenance_date,

                'amount' =>
                    $maintenanceCost,

                'from_branch_id' =>
                    $asset->branch_id,

                'to_branch_id' =>
                    $asset->branch_id,

                'worker_id' =>
                    $asset->responsible_worker_id,

                'reference_no' =>
                    $maintenance->invoice_number
                    ?? 'MNT-' . $asset->asset_code . '-' . $maintenance->id,

                'notes' =>
                    'فتح عملية صيانة للأصل: ' . $asset->asset_name,

                'created_by' =>
                    $userId,
            ]);

            return [
                'maintenance_id' => $maintenance->id,
                'asset_id' => $asset->id,
                'asset_status' => 'UNDER_MAINTENANCE',
                'status' => $maintenance->status,
                'maintenance_cost' => $maintenanceCost,
            ];
        });
    }

    public function approve(
        int $maintenanceId,
        array $context = []
    ): array {
        return DB::transaction(function () use (
            $maintenanceId,
            $context
        ) {
            $companyId = (int) ($context['company_id'] ?? 0);
            $userId = $context['created_by'] ?? null;

            if (!$companyId) {
                throw new \Exception('لم يتم تحديد الشركة الحالية.');
            }

            $maintenance = FixedAssetMaintenance::query()
                ->where('company_id', $companyId)
                ->where('id', $maintenanceId)
                ->lockForUpdate()
                ->first();

            if (!$maintenance) {
                throw new \Exception('عملية الصيانة غير موجودة.');
            }

            if ($maintenance->status !== 'DRAFT') {
                throw new \Exception('يمكن اعتماد عمليات الصيانة المسودة فقط.');
            }

            $journalId = null;

            if ((float) $maintenance->maintenance_cost > 0) {
                if (!$maintenance->payment_account_id) {
                    throw new \Exception('حساب السداد غير محدد.');
                }

                if ($maintenance->cost_treatment === 'EXPENSE') {
                    if (!$maintenance->expense_account_id) {
                        throw new \Exception('حساب مصروف الصيانة غير محدد.');
                    }

                    $debitAccountId =
                        (int) $maintenance->expense_account_id;
                } else {
                    $asset = FixedAsset::query()
                        ->where('company_id', $companyId)
                        ->where('id', $maintenance->asset_id)
                        ->first();

                    if (!$asset || !$asset->asset_account_id) {
                        throw new \Exception('حساب الأصل غير محدد.');
                    }

                    $debitAccountId =
                        (int) $asset->asset_account_id;

                    $asset->increment(
                        'purchase_cost',
                        (float) $maintenance->maintenance_cost
                    );

                    $asset->increment(
                        'current_book_value',
                        (float) $maintenance->maintenance_cost
                    );
                }

                $journalId = app(AccountingService::class)->post([
                    'company_id' => $companyId,
                    'branch_id' => $maintenance->branch_id,
                    'entry_date' => $maintenance->maintenance_date,
                    'source_type' => 'FIXED_ASSET_MAINTENANCE',
                    'source_id' => $maintenance->id,
                    'description' =>
                        'صيانة أصل ثابت رقم ' . $maintenance->asset_id,

                    'lines' => [
                        [
                            'account_id' => $debitAccountId,
                            'debit' => (float) $maintenance->maintenance_cost,
                            'credit' => 0,
                            'description' => 'تكلفة صيانة أصل ثابت',
                        ],
                        [
                            'account_id' =>
                                (int) $maintenance->payment_account_id,
                            'debit' => 0,
                            'credit' => (float) $maintenance->maintenance_cost,
                            'description' => 'سداد تكلفة الصيانة',
                        ],
                    ],
                ]);
            }

            $maintenance->update([
                'status' => 'APPROVED',
                'journal_entry_id' => $journalId,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return [
                'maintenance_id' => $maintenance->id,
                'status' => 'APPROVED',
                'journal_entry_id' => $journalId,
            ];
        });
    }

    public function complete(
        int $maintenanceId,
        array $context = []
    ): array {
        return DB::transaction(function () use (
            $maintenanceId,
            $context
        ) {
            $companyId = (int) ($context['company_id'] ?? 0);
            $userId = $context['created_by'] ?? null;

            if (!$companyId) {
                throw new \Exception('لم يتم تحديد الشركة الحالية.');
            }

            $maintenance = FixedAssetMaintenance::query()
                ->where('company_id', $companyId)
                ->where('id', $maintenanceId)
                ->lockForUpdate()
                ->first();

            if (!$maintenance) {
                throw new \Exception('عملية الصيانة غير موجودة.');
            }

            if (!in_array($maintenance->status, ['APPROVED', 'PAID'], true)) {
                throw new \Exception(
                    'يجب اعتماد عملية الصيانة قبل إغلاقها.'
                );
            }

            $asset = FixedAsset::query()
                ->where('company_id', $companyId)
                ->where('id', $maintenance->asset_id)
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                throw new \Exception('الأصل المرتبط بالصيانة غير موجود.');
            }

            $asset->update([
                'asset_status' => 'ACTIVE',
                'updated_by' => $userId,
            ]);

            if ($maintenance->status !== 'PAID') {
                $maintenance->update([
                    'status' => 'PAID',
                    'paid_at' => now(),
                ]);
            }

            return [
                'maintenance_id' => $maintenance->id,
                'asset_id' => $asset->id,
                'asset_status' => 'ACTIVE',
                'status' => 'PAID',
            ];
        });
    }
}