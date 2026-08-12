<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetMovement;
use Illuminate\Support\Facades\DB;

class FixedAssetTransferService
{
    /**
     * نقل أصل إلى فرع أو موقع أو موظف مسؤول جديد.
     */
    public function transfer(
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
                throw new \Exception('لا يمكن نقل أصل غير نشط.');
            }

            if (in_array($asset->asset_status, ['SOLD', 'DISPOSED'], true)) {
                throw new \Exception(
                    'لا يمكن نقل أصل مباع أو مشطوب.'
                );
            }

            $toBranchId = isset($data['to_branch_id'])
                && $data['to_branch_id'] !== ''
                ? (int) $data['to_branch_id']
                : $asset->branch_id;

            $toWorkerId = isset($data['to_worker_id'])
                && $data['to_worker_id'] !== ''
                ? (int) $data['to_worker_id']
                : null;

            $toLocation = array_key_exists('to_location', $data)
                ? trim((string) ($data['to_location'] ?? ''))
                : $asset->location;

            if (
                (int) $asset->branch_id === (int) $toBranchId
                && (int) $asset->responsible_worker_id === (int) $toWorkerId
                && trim((string) $asset->location) === $toLocation
            ) {
                throw new \Exception(
                    'بيانات النقل الجديدة مطابقة لبيانات الأصل الحالية.'
                );
            }

            $transferDate = $data['transfer_date']
                ?? now()->toDateString();

            $fromBranchId = $asset->branch_id;
            $fromWorkerId = $asset->responsible_worker_id;
            $fromLocation = $asset->location;

            $asset->update([
                'branch_id' => $toBranchId,
                'responsible_worker_id' => $toWorkerId,
                'location' => $toLocation ?: null,
                'updated_by' => $userId,
            ]);

            $movement = FixedAssetMovement::create([
                'company_id' => $companyId,
                'branch_id' => $toBranchId,
                'asset_id' => $asset->id,
                'movement_type' => 'TRANSFER',
                'movement_date' => $transferDate,
                'amount' => 0,

                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,

                'worker_id' => $toWorkerId,
                'journal_entry_id' => null,

                'reference_no' =>
                    $data['reference_no']
                    ?? 'TRF-' . $asset->asset_code . '-' . now()->format('YmdHis'),

                'notes' => $this->buildNotes(
                    $fromLocation,
                    $toLocation,
                    $fromWorkerId,
                    $toWorkerId,
                    $data['notes'] ?? null
                ),

                'created_by' => $userId,
            ]);

            return [
                'asset_id' => $asset->id,
                'movement_id' => $movement->id,

                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,

                'from_worker_id' => $fromWorkerId,
                'to_worker_id' => $toWorkerId,

                'from_location' => $fromLocation,
                'to_location' => $toLocation,

                'transfer_date' => $transferDate,
            ];
        });
    }

    private function buildNotes(
        ?string $fromLocation,
        ?string $toLocation,
        ?int $fromWorkerId,
        ?int $toWorkerId,
        ?string $customNotes
    ): string {
        $parts = [
            'نقل أصل ثابت',
            'الموقع السابق: ' . ($fromLocation ?: '-'),
            'الموقع الجديد: ' . ($toLocation ?: '-'),
            'الموظف السابق: ' . ($fromWorkerId ?: '-'),
            'الموظف الجديد: ' . ($toWorkerId ?: '-'),
        ];

        if ($customNotes) {
            $parts[] = 'ملاحظات: ' . trim($customNotes);
        }

        return implode(' | ', $parts);
    }
}