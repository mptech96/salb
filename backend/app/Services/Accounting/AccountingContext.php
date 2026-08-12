<?php

namespace App\Services\Accounting;

use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingContext
{
    public function companyId(Request $request): int
    {
        return TenantScope::companyId($request);
    }

    public function userId(Request $request): ?int
    {
        $id = (int) $request->attributes->get('authenticated_user_id', 0);
        return $id > 0 ? $id : null;
    }

    public function branchForOperation(Request $request, ?int $relatedBranchId = null): int
    {
        $scoped = TenantScope::branchId($request);
        if ($scoped !== null) return $scoped;

        $requested = (int) $request->input('branch_id', 0);
        if ($requested > 0) {
            TenantScope::assertBranchBelongsToCompany($requested, $request);
            return $requested;
        }

        if ($relatedBranchId && $relatedBranchId > 0) {
            TenantScope::assertBranchBelongsToCompany($relatedBranchId, $request);
            return $relatedBranchId;
        }

        $branch = DB::table('branches')
            ->where('company_id', TenantScope::companyId($request))
            ->where('is_active', 1)
            ->orderBy('id')
            ->value('id');

        if (!$branch) throw new \RuntimeException('لا يوجد فرع فعال للشركة الحالية.');
        return (int) $branch;
    }

    public function branchFilter(Request $request): ?int
    {
        $scoped = TenantScope::branchId($request);
        if ($scoped !== null) return $scoped;

        $requested = (int) $request->query('branch_id', 0);
        if ($requested > 0) {
            TenantScope::assertBranchBelongsToCompany($requested, $request);
            return $requested;
        }
        return null;
    }
}
