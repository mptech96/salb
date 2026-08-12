<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

class PostingSupport
{
    public function setting(int $companyId, string $key): int
    {
        $id = DB::table('accounting_settings')
            ->where('company_id', $companyId)
            ->where('setting_key', $key)
            ->value('account_id');
        if (!$id) throw new \RuntimeException('الحساب الافتراضي غير مضبوط: ' . $key);
        return (int) $id;
    }

    public function cashAccount(int $companyId, ?string $method, ?int $explicitId = null): int
    {
        if ($explicitId) {
            $ok = DB::table('accounts')->where('company_id',$companyId)->where('id',$explicitId)
                ->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->exists();
            if (!$ok) throw new \RuntimeException('حساب النقد/البنك المحدد غير صالح.');
            return $explicitId;
        }
        $method = strtoupper((string) $method);
        return $this->setting($companyId, in_array($method,['BANK','CARD'],true) ? 'BANK_ACCOUNT' : 'CASH_ACCOUNT');
    }

    public function branchCostCenter(int $companyId, ?int $branchId): ?int
    {
        if (!$branchId) return null;
        $id = DB::table('cost_centers')->where('company_id',$companyId)->where('branch_id',$branchId)
            ->where('is_active',1)->value('id');
        return $id ? (int) $id : null;
    }
}
