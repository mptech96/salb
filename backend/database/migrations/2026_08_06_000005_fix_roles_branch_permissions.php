<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->grantPermissions('BRANCH_MANAGER', [
            'dashboard.view',
            'branches.view',
            'users.view',
            'audit_logs.view',
        ]);

        /*
         * شاشة المبيعات تحتاج قراءة السيارات والأصناف كقوائم مساعدة.
         * تبقى شاشتا السيارات والأصناف مخفيتين عن دور SALES في القائمة.
         */
        $this->grantPermissions('SALES', [
            'cars.view',
            'items.view',
        ]);
    }

    public function down(): void
    {
        // لا نحذف صلاحيات أمنية تلقائيًا عند الرجوع.
    }

    private function grantPermissions(string $roleCode, array $permissionCodes): void
    {
        $roleId = DB::table('roles')
            ->where('role_code', $roleCode)
            ->value('id');

        if (!$roleId) {
            throw new RuntimeException("الدور {$roleCode} غير موجود.");
        }

        $permissions = DB::table('permissions')
            ->whereIn('permission_code', $permissionCodes)
            ->pluck('id', 'permission_code');

        $missing = array_values(array_diff($permissionCodes, $permissions->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException(
                'الصلاحيات التالية غير موجودة: ' . implode(', ', $missing)
            );
        }

        foreach ($permissionCodes as $permissionCode) {
            DB::table('role_permissions')->updateOrInsert(
                [
                    'company_id' => null,
                    'role_id' => (int) $roleId,
                    'permission_id' => (int) $permissions[$permissionCode],
                ],
                [
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
