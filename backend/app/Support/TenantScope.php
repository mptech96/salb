<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantScope
{
    /**
     * أدوار تعمل على مستوى الشركة كاملة.
     * مدير الفرع وبقية المستخدمين لا يدخلون هنا، وبالتالي يبقون محصورين بفرعهم.
     */
    private const COMPANY_WIDE_ROLES = [
        'SUPER_ADMIN',
        'MANAGER',
        'COMPANY_MANAGER',
        'COMPANY_ADMIN',
        'COMPANY_OWNER',
        'ADMIN',
    ];

    public static function companyId(?Request $request = null): int
    {
        $request ??= request();

        $companyId = (int) $request->attributes->get('tenant_company_id', 0);

        if ($companyId <= 0) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'code' => 'COMPANY_CONTEXT_REQUIRED',
                    'message' => 'لم يتم تحديد الشركة الحالية.',
                ], 403)
            );
        }

        return $companyId;
    }

    public static function rawBranchId(?Request $request = null): ?int
    {
        $request ??= request();

        $branchId = (int) $request->attributes->get('tenant_branch_id', 0);

        return $branchId > 0 ? $branchId : null;
    }

    public static function roleCode(?Request $request = null): string
    {
        $request ??= request();

        return strtoupper(
            trim((string) $request->attributes->get('effective_role_code', ''))
        );
    }

    public static function isCompanyWide(?Request $request = null): bool
    {
        $request ??= request();

        /*
         * جلسة الدعم تُعامل كنطاق شركة كامل، لكن الشركة نفسها
         * تأتي من ResolveAuthenticatedContext ولا تؤخذ من المتصفح.
         */
        if ((bool) $request->attributes->get('is_support_mode', false)) {
            return self::rawBranchId($request) === null;
        }

        return in_array(
            self::roleCode($request),
            self::COMPANY_WIDE_ROLES,
            true
        );
    }

    public static function isBranchManager(?Request $request = null): bool
    {
        return self::roleCode($request) === 'BRANCH_MANAGER';
    }

    public static function branchId(?Request $request = null): ?int
    {
        $request ??= request();

        if (self::isCompanyWide($request)) {
            return null;
        }

        $branchId = self::rawBranchId($request);

        if (!$branchId) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'code' => 'BRANCH_CONTEXT_REQUIRED',
                    'message' => 'يجب ربط المستخدم بفرع فعال.',
                ], 403)
            );
        }

        return $branchId;
    }

    /**
     * يدعم الشكلين الموجودين حاليًا في المشروع:
     *
     * 1) TenantScope::apply($query, $branchId)
     * 2) TenantScope::apply($query, $branchId, 's.branch_id')
     *
     * ويدعم كذلك الشكل المركزي:
     *
     * 3) TenantScope::apply($query)
     * 4) TenantScope::apply($query, 'company_id', 'branch_id')
     *
     * هذا مهم لأن Dashboard/Reports الحالية تستخدم الشكل القديم
     * بينما طبقة العزل الجديدة تستخدم الشركة/الفرع من Session Context.
     */
    public static function apply(
        $query,
        mixed $scopeOrCompanyColumn = null,
        ?string $branchColumn = null,
        ?Request $request = null
    ) {
        $request ??= request();

        /*
         * Compatibility mode:
         * إذا تم تمرير branchId (رقم أو null) فـ Controller يكون
         * قد طبّق company_id مسبقًا، ونضيف عزل الفرع فقط.
         */
        if (func_num_args() >= 2 && !is_string($scopeOrCompanyColumn)) {
            $branchId = $scopeOrCompanyColumn;
            $column = $branchColumn ?: 'branch_id';

            if ($branchId !== null && (int) $branchId > 0) {
                $query->where($column, (int) $branchId);
            }

            return $query;
        }

        /*
         * Central mode:
         * نطبق الشركة أولًا ثم الفرع إن كان المستخدم Branch scoped.
         */
        $companyColumn = is_string($scopeOrCompanyColumn) && $scopeOrCompanyColumn !== ''
            ? $scopeOrCompanyColumn
            : 'company_id';

        $branchColumn = $branchColumn ?: 'branch_id';

        $query->where($companyColumn, self::companyId($request));

        $branchId = self::branchId($request);

        if ($branchId !== null) {
            $query->where($branchColumn, $branchId);
        }

        return $query;
    }

    public static function assertBranchBelongsToCompany(
        int $branchId,
        ?Request $request = null
    ): void {
        $request ??= request();

        if ($branchId <= 0) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'code' => 'BRANCH_INVALID',
                    'message' => 'الفرع المحدد غير صالح.',
                ], 422)
            );
        }

        $query = DB::table('branches')
            ->where('id', $branchId)
            ->where('company_id', self::companyId($request));

        /*
         * لا نفترض أن is_active موجودة في كل نسخة قديمة من القاعدة.
         * إذا كانت موجودة نتحقق منها، وإلا يكفي تطابق الشركة.
         */
        try {
            if (DB::getSchemaBuilder()->hasColumn('branches', 'is_active')) {
                $query->where('is_active', 1);
            }
        } catch (\Throwable) {
            // لا نوقف العزل بسبب اختلاف بسيط في نسخة Schema.
        }

        if (!$query->exists()) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'code' => 'BRANCH_OUT_OF_SCOPE',
                    'message' => 'الفرع المحدد لا يتبع الشركة الحالية أو غير متاح.',
                ], 403)
            );
        }
    }

    public static function targetBranchId(
        Request $request,
        bool $required = true
    ): ?int {
        /*
         * مدير الفرع / المستخدم العادي:
         * لا يحق له اختيار فرع مختلف عن جلسة الدخول.
         */
        $scopedBranchId = self::branchId($request);

        if ($scopedBranchId !== null) {
            return $scopedBranchId;
        }

        /*
         * مدير الشركة:
         * يسمح له باختيار فرع داخل شركته فقط.
         */
        $requestedBranchId = (int) $request->input('branch_id', 0);

        if ($requestedBranchId > 0) {
            self::assertBranchBelongsToCompany($requestedBranchId, $request);
            return $requestedBranchId;
        }

        if ($required) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'code' => 'BRANCH_REQUIRED',
                    'message' => 'اختر فرعًا تابعًا للشركة.',
                ], 422)
            );
        }

        return null;
    }
}
