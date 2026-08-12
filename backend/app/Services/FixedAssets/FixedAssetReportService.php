<?php

namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetMaintenance;
use App\Models\FixedAssetMovement;

class FixedAssetReportService
{
    public function summary(array $filters = []): array
    {
        $companyId = (int) ($filters['company_id'] ?? 0);

        if (!$companyId) {
            throw new \Exception('لم يتم تحديد الشركة الحالية.');
        }

        $branchId = !empty($filters['branch_id'])
            ? (int) $filters['branch_id']
            : null;

        $assetsQuery = FixedAsset::query()
            ->where('company_id', $companyId);

        if ($branchId) {
            $assetsQuery->where('branch_id', $branchId);
        }

        $assets = $assetsQuery->get();

        return [
            'total_assets' => $assets->count(),

            'active_assets' => $assets
                ->where('asset_status', 'ACTIVE')
                ->count(),

            'under_maintenance_assets' => $assets
                ->where('asset_status', 'UNDER_MAINTENANCE')
                ->count(),

            'sold_assets' => $assets
                ->where('asset_status', 'SOLD')
                ->count(),

            'disposed_assets' => $assets
                ->where('asset_status', 'DISPOSED')
                ->count(),

            'purchase_cost_total' => round(
                $assets->sum(
                    fn ($asset) => (float) $asset->purchase_cost
                ),
                3
            ),

            'accumulated_depreciation_total' => round(
                $assets->sum(
                    fn ($asset) =>
                        (float) $asset->accumulated_depreciation
                ),
                3
            ),

            'book_value_total' => round(
                $assets->sum(
                    fn ($asset) => (float) $asset->current_book_value
                ),
                3
            ),

            'salvage_value_total' => round(
                $assets->sum(
                    fn ($asset) => (float) $asset->salvage_value
                ),
                3
            ),
        ];
    }

    public function assets(array $filters = [])
    {
        $companyId = (int) ($filters['company_id'] ?? 0);

        if (!$companyId) {
            throw new \Exception('لم يتم تحديد الشركة الحالية.');
        }

        $query = FixedAsset::query()
            ->with('category')
            ->where('company_id', $companyId);

        if (!empty($filters['branch_id'])) {
            $query->where(
                'branch_id',
                (int) $filters['branch_id']
            );
        }

        if (!empty($filters['category_id'])) {
            $query->where(
                'category_id',
                (int) $filters['category_id']
            );
        }

        if (!empty($filters['asset_status'])) {
            $query->where(
                'asset_status',
                $filters['asset_status']
            );
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('asset_code', 'like', "%{$search}%")
                    ->orWhere('asset_name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('asset_code')
            ->paginate(
                (int) ($filters['per_page'] ?? 20)
            );
    }

    public function depreciations(array $filters = [])
    {
        $companyId = (int) ($filters['company_id'] ?? 0);

        if (!$companyId) {
            throw new \Exception('لم يتم تحديد الشركة الحالية.');
        }

        $query = FixedAssetDepreciation::query()
            ->with('asset')
            ->where('company_id', $companyId);

        if (!empty($filters['branch_id'])) {
            $query->where(
                'branch_id',
                (int) $filters['branch_id']
            );
        }

        if (!empty($filters['asset_id'])) {
            $query->where(
                'asset_id',
                (int) $filters['asset_id']
            );
        }

        if (!empty($filters['month_from'])) {
            $query->whereDate(
                'depreciation_month',
                '>=',
                $filters['month_from']
            );
        }

        if (!empty($filters['month_to'])) {
            $query->whereDate(
                'depreciation_month',
                '<=',
                $filters['month_to']
            );
        }

        return $query
            ->orderByDesc('depreciation_month')
            ->paginate(
                (int) ($filters['per_page'] ?? 20)
            );
    }

    public function maintenances(array $filters = [])
    {
        $companyId = (int) ($filters['company_id'] ?? 0);

        if (!$companyId) {
            throw new \Exception('لم يتم تحديد الشركة الحالية.');
        }

        $query = FixedAssetMaintenance::query()
            ->with('asset')
            ->where('company_id', $companyId);

        if (!empty($filters['branch_id'])) {
            $query->where(
                'branch_id',
                (int) $filters['branch_id']
            );
        }

        if (!empty($filters['asset_id'])) {
            $query->where(
                'asset_id',
                (int) $filters['asset_id']
            );
        }

        if (!empty($filters['status'])) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate(
                'maintenance_date',
                '>=',
                $filters['date_from']
            );
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate(
                'maintenance_date',
                '<=',
                $filters['date_to']
            );
        }

        return $query
            ->orderByDesc('maintenance_date')
            ->paginate(
                (int) ($filters['per_page'] ?? 20)
            );
    }

    public function movements(array $filters = [])
    {
        $companyId = (int) ($filters['company_id'] ?? 0);

        if (!$companyId) {
            throw new \Exception('لم يتم تحديد الشركة الحالية.');
        }

        $query = FixedAssetMovement::query()
            ->with('asset')
            ->where('company_id', $companyId);

        if (!empty($filters['branch_id'])) {
            $query->where(
                'branch_id',
                (int) $filters['branch_id']
            );
        }

        if (!empty($filters['asset_id'])) {
            $query->where(
                'asset_id',
                (int) $filters['asset_id']
            );
        }

        if (!empty($filters['movement_type'])) {
            $query->where(
                'movement_type',
                $filters['movement_type']
            );
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate(
                'movement_date',
                '>=',
                $filters['date_from']
            );
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate(
                'movement_date',
                '<=',
                $filters['date_to']
            );
        }

        return $query
            ->orderByDesc('movement_date')
            ->paginate(
                (int) ($filters['per_page'] ?? 20)
            );
    }
}