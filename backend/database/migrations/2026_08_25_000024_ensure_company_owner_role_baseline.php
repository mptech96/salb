<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_CODE = 'COMPANY_OWNER';
    private const ROLE_NAME = 'مالك الشركة';

    public function up(): void
    {
        $existing = DB::table('roles')
            ->where('role_code', self::ROLE_CODE)
            ->first(['id', 'role_name', 'is_active']);

        if ($existing !== null) {
            if ((int) $existing->is_active !== 1) {
                throw new RuntimeException(
                    'COMPANY_OWNER exists but is inactive; reconcile it explicitly before adopting the provisioning baseline.'
                );
            }

            return;
        }

        $timestamp = now();

        DB::table('roles')->insert([
            'role_name' => self::ROLE_NAME,
            'role_code' => self::ROLE_CODE,
            'is_active' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        $role = DB::table('roles')
            ->where('role_code', self::ROLE_CODE)
            ->where('role_name', self::ROLE_NAME)
            ->where('is_active', 1)
            ->whereColumn('created_at', 'updated_at')
            ->first(['id']);

        if ($role === null) {
            return;
        }

        if (DB::table('user_roles')->where('role_id', $role->id)->exists()) {
            return;
        }

        if (DB::table('role_permissions')->where('role_id', $role->id)->exists()) {
            return;
        }

        DB::table('roles')
            ->where('id', $role->id)
            ->where('role_code', self::ROLE_CODE)
            ->where('role_name', self::ROLE_NAME)
            ->where('is_active', 1)
            ->whereColumn('created_at', 'updated_at')
            ->delete();
    }
};
