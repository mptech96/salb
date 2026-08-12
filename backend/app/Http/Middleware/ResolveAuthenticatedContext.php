<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveAuthenticatedContext
{
    public function __construct(
        private readonly SessionContextService $sessions
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || (int) $user->is_active !== 1) {
            return response()->json([
                'status' => false,
                'code' => 'AUTH_REQUIRED',
                'message' => 'انتهت جلسة الدخول أو تم تعطيل المستخدم.',
            ], 401);
        }

        $actualRole = $this->sessions->roleForUser((int) $user->id);

        if (!$actualRole) {
            return response()->json([
                'status' => false,
                'code' => 'ROLE_MISSING',
                'message' => 'لا يوجد دور فعال مرتبط بالمستخدم.',
            ], 403);
        }

        $actualRoleCode = strtoupper(trim((string) $actualRole->role_code));
        $token = $user->currentAccessToken();
        $abilities = is_array($token?->abilities) ? $token->abilities : [];
        $isSupportMode = in_array('support-mode', $abilities, true);

        $companyId = $user->company_id ? (int) $user->company_id : null;
        $branchId = $user->branch_id ? (int) $user->branch_id : null;
        $effectiveRoleCode = $actualRoleCode;

        if ($isSupportMode) {
            if ($actualRoleCode !== 'SUPER_ADMIN') {
                return response()->json([
                    'status' => false,
                    'code' => 'SUPPORT_FORBIDDEN',
                    'message' => 'جلسة الدعم غير صالحة لهذا المستخدم.',
                ], 403);
            }

            $companyId = $this->abilityInteger($abilities, 'support-company:');
            $branchId = $this->abilityInteger($abilities, 'support-branch:');

            if (!$companyId || !DB::table('companies')->where('id', $companyId)->exists()) {
                return response()->json([
                    'status' => false,
                    'code' => 'SUPPORT_COMPANY_MISSING',
                    'message' => 'شركة جلسة الدعم غير موجودة.',
                ], 403);
            }

            if ($branchId && !DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists()) {
                return response()->json([
                    'status' => false,
                    'code' => 'SUPPORT_BRANCH_INVALID',
                    'message' => 'فرع جلسة الدعم لا يتبع الشركة.',
                ], 403);
            }

            // مهم: نمرر دور مدير شركة للـControllers القديمة حتى يبقى الدعم
            // محصورًا داخل الشركة بدل تجاوز العزل كمدير منصة.
            $effectiveRoleCode = 'MANAGER';
            $permissions = $this->sessions->allPermissions();
        } else {
            $permissions = $this->sessions->permissionsForUser(
                (int) $user->id,
                $companyId
            );
        }

        $this->replaceHeader($request, 'X-Company-ID', $companyId);
        $this->replaceHeader($request, 'X-Branch-ID', $branchId);
        $this->replaceHeader($request, 'X-User-ID', (int) $user->id);
        $this->replaceHeader($request, 'X-Role-Code', $effectiveRoleCode);
        $this->replaceHeader($request, 'X-Support-Mode', $isSupportMode ? 1 : 0);

        $request->attributes->set('tenant_company_id', $companyId);
        $request->attributes->set('tenant_branch_id', $branchId);
        $request->attributes->set('authenticated_user_id', (int) $user->id);
        $request->attributes->set('actual_role_code', $actualRoleCode);
        $request->attributes->set('effective_role_code', $effectiveRoleCode);
        $request->attributes->set('is_support_mode', $isSupportMode);
        $request->attributes->set('permission_codes', $permissions->all());

        return $next($request);
    }

    private function abilityInteger(array $abilities, string $prefix): ?int
    {
        foreach ($abilities as $ability) {
            if (!is_string($ability) || !str_starts_with($ability, $prefix)) {
                continue;
            }

            $value = substr($ability, strlen($prefix));

            return is_numeric($value) && (int) $value > 0
                ? (int) $value
                : null;
        }

        return null;
    }

    private function replaceHeader(Request $request, string $name, mixed $value): void
    {
        if ($value === null || $value === '') {
            $request->headers->remove($name);
            return;
        }

        $request->headers->set($name, (string) $value);
    }
}
