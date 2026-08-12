<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $storeRoleId = DB::table('roles')->where('role_code', 'STORE')->value('id');
        if (!$storeRoleId) {
            throw new RuntimeException('الدور STORE غير موجود.');
        }

        $inventoryPermissionId = DB::table('permissions')
            ->where('permission_code', 'inventory.view')
            ->value('id');

        if (!$inventoryPermissionId) {
            throw new RuntimeException('الصلاحية inventory.view غير موجودة.');
        }

        DB::table('role_permissions')->updateOrInsert(
            [
                'company_id' => null,
                'role_id' => (int) $storeRoleId,
                'permission_id' => (int) $inventoryPermissionId,
            ],
            [
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        // لا نحذف الصلاحية تلقائيًا حتى لا نمحو إعدادًا اعتمده مدير النظام لاحقًا.
    }
};
