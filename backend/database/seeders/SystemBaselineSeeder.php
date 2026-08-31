<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SystemBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/system-baseline.json');
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $expected = [
            'roles' => 8,
            'permissions' => 109,
            'role_permissions' => 231,
            'feature_catalog' => 19,
            'plans' => 3,
            'plan_features' => 54,
        ];

        foreach ($expected as $key => $count) {
            if (count($data[$key] ?? []) !== $count) {
                throw new RuntimeException("Invalid system baseline count for {$key}.");
            }
        }

        DB::transaction(function () use ($data): void {
            foreach ($data['roles'] as $row) {
                DB::table('roles')->insertOrIgnore([...$row, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($data['permissions'] as $row) {
                DB::table('permissions')->insertOrIgnore([...$row, 'created_at' => now()]);
            }
            foreach ($data['feature_catalog'] as $row) {
                DB::table('feature_catalog')->insertOrIgnore([...$row, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($data['plans'] as $row) {
                DB::table('plans')->insertOrIgnore([...$row, 'created_at' => now(), 'updated_at' => now()]);
            }

            $roleIds = DB::table('roles')
                ->whereIn('role_code', array_column($data['roles'], 'role_code'))
                ->pluck('id', 'role_code');
            $permissionIds = DB::table('permissions')
                ->whereIn('permission_code', array_column($data['permissions'], 'permission_code'))
                ->pluck('id', 'permission_code');

            foreach ($data['role_permissions'] as $row) {
                $roleId = $roleIds[$row['role_code']] ?? null;
                $permissionId = $permissionIds[$row['permission_code']] ?? null;
                if (! $roleId || ! $permissionId) {
                    throw new RuntimeException('System role-permission dependency is missing.');
                }

                $exists = DB::table('role_permissions')
                    ->whereNull('company_id')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'company_id' => null,
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'is_active' => $row['is_active'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $planIds = DB::table('plans')
                ->whereIn('plan_code', array_column($data['plans'], 'plan_code'))
                ->pluck('id', 'plan_code');
            foreach ($data['plan_features'] as $row) {
                $planId = $planIds[$row['plan_code']] ?? null;
                if (! $planId) {
                    throw new RuntimeException('System plan dependency is missing.');
                }

                DB::table('plan_features')->insertOrIgnore([
                    'plan_id' => $planId,
                    'feature_code' => $row['feature_code'],
                    'is_enabled' => $row['is_enabled'],
                    'limit_value' => $row['limit_value'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
