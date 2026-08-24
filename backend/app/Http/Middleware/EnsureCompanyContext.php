<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->attributes->get('tenant_company_id');
        $branchId = $request->attributes->get('tenant_branch_id');
        $isSupportMode = (bool) $request->attributes->get('is_support_mode', false);

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'code' => 'COMPANY_CONTEXT_REQUIRED',
                'message' => 'ادخل إلى شركة محددة قبل استخدام بوابة الشركة.',
            ], 403);
        }

        $company = DB::table('companies')->where('id', $companyId)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'code' => 'COMPANY_NOT_FOUND',
                'message' => 'الشركة الحالية غير موجودة.',
            ], 403);
        }

        if (!$isSupportMode && (int) $company->is_active !== 1) {
            return response()->json([
                'status' => false,
                'code' => 'COMPANY_INACTIVE',
                'message' => 'الشركة الحالية غير مفعلة.',
            ], 403);
        }

        if ($branchId) {
            $branchQuery = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId);

            if (!$isSupportMode) {
                $branchQuery->where('is_active', 1);
            }

            if (!$branchQuery->exists()) {
                return response()->json([
                    'status' => false,
                    'code' => 'BRANCH_CONTEXT_INVALID',
                    'message' => $isSupportMode
                        ? 'فرع جلسة الدعم لا يتبع الشركة.'
                        : 'الفرع الحالي غير فعال أو لا يتبع الشركة.',
                ], 403);
            }
        }

        return $next($request);
    }
}
