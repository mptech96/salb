<?php

namespace App\Services\Demo;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoExpenseTypeService
{
    private const ACCOUNT_SETTING_BY_TYPE = [
        'DRIVER_TRIP' => 'TRANSPORT_EXPENSE_ACCOUNT',
        'WORKERS' => 'SALARY_EXPENSE_ACCOUNT',
        'WEIGHT_DIFF' => 'INVENTORY_ADJUSTMENT_ACCOUNT',
    ];

    /** @return array<int, int> */
    public function ensure(int $companyId): array
    {
        return DB::transaction(function () use ($companyId): array {
            $globalTypes = DB::table('expense_types')
                ->whereNull('company_id')
                ->where('is_active', 1)
                ->orderBy('id')
                ->get();

            if ($globalTypes->isEmpty()) {
                throw new RuntimeException('The global expense-type baseline is missing.');
            }

            $settings = DB::table('accounting_settings')
                ->where('company_id', $companyId)
                ->pluck('account_id', 'setting_key');
            $ids = [];

            foreach ($globalTypes as $globalType) {
                $settingKey = self::ACCOUNT_SETTING_BY_TYPE[(string) $globalType->type_code]
                    ?? 'GENERAL_EXPENSE_ACCOUNT';
                $accountId = (int) ($settings[$settingKey] ?? $settings['GENERAL_EXPENSE_ACCOUNT'] ?? 0);
                $this->assertPostableExpenseAccount($companyId, $accountId, $settingKey);

                $existing = DB::table('expense_types')
                    ->where('company_id', $companyId)
                    ->where('type_code', $globalType->type_code)
                    ->first();

                $values = [
                    'type_name' => $globalType->type_name,
                    'account_id' => $accountId,
                    'default_scope' => $globalType->default_scope,
                    'affects_cost' => $globalType->affects_cost,
                    'usage_type' => $globalType->usage_type,
                    'description' => $globalType->description,
                    'is_active' => 1,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('expense_types')->where('id', $existing->id)->update($values);
                    $ids[] = (int) $existing->id;
                    continue;
                }

                $ids[] = DB::table('expense_types')->insertGetId([
                    'company_id' => $companyId,
                    'type_code' => $globalType->type_code,
                    ...$values,
                    'created_at' => now(),
                ]);
            }

            return $ids;
        }, 3);
    }

    private function assertPostableExpenseAccount(int $companyId, int $accountId, string $settingKey): void
    {
        $valid = $accountId > 0 && DB::table('accounts')
            ->where('id', $accountId)
            ->where('company_id', $companyId)
            ->where('account_type', 'EXPENSE')
            ->where('is_active', 1)
            ->where('is_group', 0)
            ->where('allow_posting', 1)
            ->exists();

        if (! $valid) {
            throw new RuntimeException("Demo accounting setting {$settingKey} does not reference a valid postable company expense account.");
        }
    }
}
