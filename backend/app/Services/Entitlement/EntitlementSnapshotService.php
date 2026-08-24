<?php

namespace App\Services\Entitlement;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EntitlementSnapshotService
{
    public function capture(int $subscriptionId): int
    {
        $subscription=DB::table('subscriptions')->where('id',$subscriptionId)->first();
        if(!$subscription) throw new RuntimeException('Subscription not found.');
        $count=0;
        foreach(DB::table('plan_features')->where('plan_id',$subscription->plan_id)->get() as $feature){
            DB::table('subscription_entitlement_snapshots')->updateOrInsert(
                ['subscription_id'=>$subscription->id,'feature_code'=>$feature->feature_code,'effective_from'=>$subscription->start_date,'source'=>'PLAN'],
                ['company_id'=>$subscription->company_id,'plan_id'=>$subscription->plan_id,'is_enabled'=>$feature->is_enabled,'limit_value'=>$feature->limit_value,'effective_to'=>$subscription->end_date,'updated_at'=>now(),'created_at'=>now()]
            );$count++;
        }
        return $count;
    }
}
