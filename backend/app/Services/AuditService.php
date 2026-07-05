<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AuditService
{
    public static function log(
        string $module,
        string $action,
        ?int $recordId = null,
        ?string $description = null
    ): void {

        try {

            DB::table('audit_logs')->insert([

                'company_id' => request()->header('X-Company-ID'),

                'branch_id' => request()->header('X-Branch-ID'),

                'user_id' => request()->header('X-User-ID'),

                'module_name' => $module,

                'action_type' => strtoupper($action),

                'record_id' => $recordId,

                'description' => $description,

                'ip_address' => request()->ip(),

                'user_agent' => request()->userAgent(),

                'created_at' => now(),

                'updated_at' => now(),

            ]);

        } catch (\Throwable $e) {
            // لا نوقف النظام إذا فشل تسجيل السجل
        }
    }
}