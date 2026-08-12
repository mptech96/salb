<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use Carbon\Carbon;

class FixedAssetDepreciationRunService
{
    public function __construct(
        private FixedAssetDepreciationService $depreciationService
    ) {
    }

    /**
     * تشغيل الإهلاك على جميع الأصول المؤهلة لشهر محدد.
     */
    public function run(
        string $depreciationMonth,
        array $context = []
    ): array {
        $companyId = (int) ($context['company_id'] ?? 0);

        $branchId = isset($context['branch_id'])
            && $context['branch_id'] !== ''
            && $context['branch_id'] !== null
            ? (int) $context['branch_id']
            : null;

        $userId = isset($context['created_by'])
            ? (int) $context['created_by']
            : null;

        if (!$companyId) {
            throw new \Exception('لم يتم تحديد الشركة الحالية.');
        }

        $month = Carbon::parse($depreciationMonth)
            ->startOfMonth();

        $query = FixedAsset::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('asset_status', 'ACTIVE')
            ->where(
                'depreciation_method',
                '!=',
                'NO_DEPRECIATION'
            )
            ->whereNotNull('depreciation_start_date')
            ->whereDate(
                'depreciation_start_date',
                '<=',
                $month
                    ->copy()
                    ->endOfMonth()
                    ->toDateString()
            );

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $assetIds = $query
            ->orderBy('id')
            ->pluck('id');

        if ($assetIds->isEmpty()) {
            throw new \Exception(
                'لا توجد أصول مؤهلة للإهلاك في الشهر المحدد.'
            );
        }

        $results = [];
        $errors = [];

        foreach ($assetIds as $assetId) {
            try {
                $results[] =
                    $this->depreciationService->depreciate(
                        (int) $assetId,
                        $month->toDateString(),
                        [
                            'company_id' => $companyId,
                            'created_by' => $userId,
                        ]
                    );
            } catch (\Throwable $e) {
                $errors[] = [
                    'asset_id' => (int) $assetId,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $totalDepreciation = round(
            collect($results)->sum(
                fn (array $result) =>
                    (float) (
                        $result['depreciation_amount']
                        ?? 0
                    )
            ),
            3
        );

        return [
            'depreciation_month' =>
                $month->format('Y-m'),

            'company_id' => $companyId,
            'branch_id' => $branchId,

            'eligible_assets_count' =>
                $assetIds->count(),

            'posted_count' =>
                count($results),

            'failed_count' =>
                count($errors),

            'total_depreciation' =>
                $totalDepreciation,

            'results' => $results,
            'errors' => $errors,
        ];
    }
}