<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $branchManagerId = DB::table('roles')->where('role_code', 'BRANCH_MANAGER')->value('id');
        $managerId = DB::table('roles')->where('role_code', 'MANAGER')->value('id');
        $salesId = DB::table('roles')->where('role_code', 'SALES')->value('id');

        if (!$branchManagerId) {
            throw new RuntimeException('الدور BRANCH_MANAGER غير موجود.');
        }

        // مدير الفرع مدير تشغيل كامل داخل فرعه. الحماية الحقيقية من Tenant/Branch Scope في الخادم.
        $permissionIds = DB::table('permissions')->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['company_id' => null, 'role_id' => (int) $branchManagerId, 'permission_id' => (int) $permissionId],
                ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // مدير الشركة يجب أن يملك كامل صلاحيات بوابة الشركة أيضًا.
        if ($managerId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['company_id' => null, 'role_id' => (int) $managerId, 'permission_id' => (int) $permissionId],
                    ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // شاشة المبيعات تحتاج قراءة العملاء + السيارات + الأصناف لتكوين الفاتورة، بدون إظهار وحداتها في القائمة.
        if ($salesId) {
            $codes = ['sales.view','sales.create','sales.update','sales.delete','customers.view','cars.view','items.view','reports.view'];
            $ids = DB::table('permissions')->whereIn('permission_code', $codes)->pluck('id');
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['company_id' => null, 'role_id' => (int) $salesId, 'permission_id' => (int) $permissionId],
                    ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // لا نحذف صلاحيات قائمة تلقائيًا حتى لا نمحو إعدادات اعتمدها مدير النظام لاحقًا.
    }
};
