<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionContextService
{
    public function roleForUser(int $userId): ?object
    {
        return DB::table('user_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where(function ($query) {
                $query
                    ->whereNull('ur.company_id')
                    ->orWhereColumn('ur.company_id', 'u.company_id');
            })
            ->where('ur.is_active', 1)
            ->where('r.is_active', 1)
            ->select('r.id', 'r.role_name', 'r.role_code')
            ->orderByDesc('ur.id')
            ->first();
    }

    public function permissionsForUser(int $userId, ?int $companyId): Collection
    {
        return DB::table('user_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $userId)
            ->where('ur.is_active', 1)
            ->where(function ($query) {
                $query
                    ->whereNull('ur.company_id')
                    ->orWhereColumn('ur.company_id', 'u.company_id');
            })
            ->where('rp.is_active', 1)
            ->when(
                $companyId,
                fn ($query) => $query->where(function ($permissionQuery) use ($companyId) {
                    $permissionQuery
                        ->whereNull('rp.company_id')
                        ->orWhere('rp.company_id', $companyId);
                }),
                fn ($query) => $query->whereNull('rp.company_id')
            )
            ->pluck('p.permission_code')
            ->unique()
            ->values();
    }

    public function allPermissions(): Collection
    {
        return DB::table('permissions')
            ->orderBy('id')
            ->pluck('permission_code')
            ->unique()
            ->values();
    }

    public function latestSubscription(int $companyId): ?object
    {
        return DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $companyId)
            ->orderByDesc('s.id')
            ->select(
                's.*',
                'p.plan_name',
                'p.plan_code',
                'p.max_branches',
                'p.max_users',
                'p.max_cars',
                'p.max_invoices'
            )
            ->first();
    }

    public function userPayload(User $user): array
    {
        $role = $this->roleForUser((int) $user->id);
        $isPlatformAdmin = strtoupper((string) ($role->role_code ?? '')) === 'SUPER_ADMIN';

        $company = null;
        $branch = null;
        $companyId = $isPlatformAdmin ? null : ($user->company_id ? (int) $user->company_id : null);
        $branchId = $isPlatformAdmin ? null : ($user->branch_id ? (int) $user->branch_id : null);

        if ($companyId) {
            $company = DB::table('companies')->where('id', $companyId)->first();
        }

        if ($branchId) {
            $branch = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->first();
        }

        $permissions = $role
            ? $this->permissionsForUser((int) $user->id, $companyId)
            : collect();

        return [
            'id' => (int) $user->id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'company_name' => $isPlatformAdmin
                ? 'إدارة منصة صلب'
                : ($company->company_name ?? null),
            'branch_name' => $isPlatformAdmin
                ? 'مركز التحكم'
                : ($branch->branch_name ?? null),
            'role' => $role ? [
                'id' => (int) $role->id,
                'role_name' => $role->role_name,
                'role_code' => $role->role_code,
            ] : null,
            'permissions' => $permissions->all(),
            'is_support_mode' => false,
            'platform_admin_id' => $isPlatformAdmin ? (int) $user->id : null,
        ];
    }

    public function supportPayload(
        User $platformAdmin,
        int $companyId,
        ?int $branchId
    ): array {
        $company = DB::table('companies')->where('id', $companyId)->first();

        $branchQuery = DB::table('branches')
            ->where('company_id', $companyId);

        if ($branchId) {
            $branchQuery->where('id', $branchId);
        } else {
            $branchQuery->orderByDesc('is_active')->orderBy('id');
        }

        $branch = $branchQuery->first();

        return [
            'id' => (int) $platformAdmin->id,
            'company_id' => $companyId,
            'branch_id' => $branch?->id ? (int) $branch->id : null,
            'name' => $platformAdmin->name,
            'username' => $platformAdmin->username,
            'email' => $platformAdmin->email,
            'phone' => $platformAdmin->phone,
            'company_name' => $company->company_name ?? 'شركة غير معروفة',
            'branch_name' => $branch->branch_name ?? 'بدون فرع محدد',
            'role' => [
                'id' => null,
                'role_name' => 'دعم فني للمنصة',
                // واجهة الشركة تتعامل مع الدعم كمدير شركة حتى لا تعرض
                // قوائم جميع الشركات داخل صفحات المستخدمين والفروع القديمة.
                'role_code' => 'MANAGER',
            ],
            'permissions' => $this->allPermissions()->all(),
            'is_support_mode' => true,
            'actual_role_code' => 'SUPER_ADMIN',
            'platform_admin_id' => (int) $platformAdmin->id,
            'support_company_id' => $companyId,
        ];
    }

    public function subscriptionPayload(?object $subscription): ?array
    {
        if (!$subscription) {
            return null;
        }

        return [
            'id' => isset($subscription->id) ? (int) $subscription->id : null,
            'plan_name' => $subscription->plan_name ?? null,
            'plan_code' => $subscription->plan_code ?? null,
            'start_date' => $subscription->start_date ?? null,
            'end_date' => $subscription->end_date ?? null,
            'max_branches' => isset($subscription->max_branches)
                ? (int) $subscription->max_branches
                : null,
            'max_users' => isset($subscription->max_users)
                ? (int) $subscription->max_users
                : null,
            'max_cars' => isset($subscription->max_cars)
                ? (int) $subscription->max_cars
                : null,
            'max_invoices' => isset($subscription->max_invoices)
                ? (int) $subscription->max_invoices
                : null,
            'status' => $subscription->status ?? null,
        ];
    }
}
