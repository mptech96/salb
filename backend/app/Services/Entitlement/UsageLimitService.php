<?php

namespace App\Services\Entitlement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class UsageLimitService
{
    public function __construct(private EffectiveEntitlementService $entitlements) {}

    public function assertCanGrow(int $companyId, string $metric): void
    {
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
        $limit = $this->entitlements->resolve($companyId)['limits'][$metric] ?? null;
        if ($limit === null) return;
        $usage = $this->usage($companyId, $metric);
        if ($usage >= $limit) throw new HttpException(409, "Usage limit {$metric} reached.", null, ['X-SULB-Code'=>'USAGE_LIMIT_REACHED']);
    }

    public function usage(int $companyId, string $metric): int
    {
        return match ($metric) {
            'max_users' => $this->count('users', $companyId),
            'max_branches' => $this->count('branches', $companyId),
            'max_stores' => Schema::hasTable('stores') ? $this->count('stores', $companyId) : 0,
            'max_vehicles' => $this->count('cars', $companyId),
            'max_documents' => array_sum(array_map(fn($t)=>$this->count($t,$companyId), array_filter(['purchase_invoices','sales_invoices','official_documents'], fn($t)=>Schema::hasTable($t)))),
            default => throw new \InvalidArgumentException("Unknown usage metric: {$metric}"),
        };
    }

    private function count(string $table, int $companyId): int { return (int) DB::table($table)->where('company_id',$companyId)->count(); }
}
