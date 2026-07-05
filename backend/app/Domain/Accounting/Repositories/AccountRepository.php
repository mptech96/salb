<?php

namespace App\Domain\Accounting\Repositories;

use Illuminate\Support\Facades\DB;

class AccountRepository
{
    public function find(int $companyId, int $accountId)
    {
        return DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('id', $accountId)
            ->first();
    }

    public function findByCode(int $companyId, string $code)
    {
        return DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('account_code', $code)
            ->first();
    }

    public function movementAccounts(int $companyId)
    {
        return DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('is_group', 0)
            ->orderBy('account_code')
            ->get();
    }

    public function tree(int $companyId)
    {
        return DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->orderBy('account_code')
            ->get();
    }

    public function create(array $data): int
    {
        return DB::table('accounts')->insertGetId([
            'company_id' => $data['company_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'account_code' => $data['account_code'],
            'account_name' => $data['account_name'],
            'account_type' => $data['account_type'],
            'normal_side' => $data['normal_side'],
            'is_group' => $data['is_group'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}