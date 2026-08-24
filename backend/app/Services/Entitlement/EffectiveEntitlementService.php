<?php

namespace App\Services\Entitlement;

use App\Services\Subscription\SubscriptionLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EffectiveEntitlementService
{
    public function __construct(private SubscriptionLifecycleService $subscriptions, private FeatureCatalogService $catalog) {}

    public function resolve(int $companyId, mixed $at = null): array
    {
        $date = CarbonImmutable::parse($at ?? now())->startOfDay();
        $subscription = $this->subscriptions->effectiveForCompany($companyId, $date);
        $features = array_fill_keys(array_keys($this->catalog->all()), false);
        $limits = array_fill_keys($this->catalog->limits(), null);
        if (!$subscription) return compact('features', 'limits') + ['subscription_id' => null, 'source' => 'none'];

        $source = 'plan';
        if (Schema::hasTable('plan_features')) {
            foreach (DB::table('plan_features')->where('plan_id', $subscription->plan_id)->get() as $row) {
                if (str_starts_with($row->feature_code, 'max_')) $limits[$row->feature_code] = $row->limit_value === null ? null : (int) $row->limit_value;
                elseif (array_key_exists($row->feature_code, $features)) $features[$row->feature_code] = (bool) $row->is_enabled;
            }
        }
        foreach (['max_users'=>'max_users','max_branches'=>'max_branches','max_vehicles'=>'max_cars','max_documents'=>'max_invoices'] as $key=>$legacy) {
            if ($limits[$key] === null && isset($subscription->{$legacy})) $limits[$key] = (int) $subscription->{$legacy};
        }
        if (Schema::hasTable('subscription_entitlement_snapshots')) {
            $rows = DB::table('subscription_entitlement_snapshots')->where('subscription_id', $subscription->id)
                ->whereDate('effective_from', '<=', $date)->where(fn($q)=>$q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->orderBy('id')->get();
            if ($rows->isNotEmpty()) { $source = 'snapshot'; foreach ($rows as $row) $this->apply($row, $features, $limits); }
        }
        if (Schema::hasTable('company_entitlement_overrides')) {
            $rows = DB::table('company_entitlement_overrides')->where('company_id', $companyId)
                ->whereDate('effective_from', '<=', $date)->where(fn($q)=>$q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->orderBy('id')->get();
            if ($rows->isNotEmpty()) { $source = 'company_override'; foreach ($rows as $row) $this->apply($row, $features, $limits); }
        }
        return compact('features', 'limits', 'source') + ['subscription_id'=>(int)$subscription->id,'plan_id'=>(int)$subscription->plan_id,'effective_at'=>$date->toDateString()];
    }

    public function allows(int $companyId, string $feature, mixed $at = null): bool { return ($this->resolve($companyId, $at)['features'][$feature] ?? false) === true; }

    private function apply(object $row, array &$features, array &$limits): void
    {
        if (str_starts_with($row->feature_code, 'max_')) $limits[$row->feature_code] = $row->limit_value === null ? null : (int)$row->limit_value;
        elseif (array_key_exists($row->feature_code, $features) && $row->is_enabled !== null) $features[$row->feature_code] = (bool)$row->is_enabled;
    }
}
