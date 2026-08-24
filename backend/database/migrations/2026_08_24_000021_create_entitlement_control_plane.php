<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('feature_catalog')) Schema::create('feature_catalog',function(Blueprint $t){$t->id();$t->string('feature_code',100)->unique();$t->string('feature_name',150);$t->string('feature_type',20)->default('BOOLEAN');$t->string('module_name',100)->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        if (!Schema::hasTable('plan_features')) Schema::create('plan_features',function(Blueprint $t){$t->id();$t->bigInteger('plan_id');$t->string('feature_code',100);$t->boolean('is_enabled')->nullable();$t->unsignedBigInteger('limit_value')->nullable();$t->timestamps();$t->unique(['plan_id','feature_code'],'uq_plan_feature');$t->index('feature_code','idx_plan_feature_code');});
        if (!Schema::hasTable('subscription_entitlement_snapshots')) Schema::create('subscription_entitlement_snapshots',function(Blueprint $t){$t->id();$t->bigInteger('subscription_id');$t->bigInteger('company_id');$t->bigInteger('plan_id');$t->string('feature_code',100);$t->boolean('is_enabled')->nullable();$t->unsignedBigInteger('limit_value')->nullable();$t->dateTime('effective_from');$t->dateTime('effective_to')->nullable();$t->string('source',30)->default('PLAN');$t->json('metadata_json')->nullable();$t->timestamps();$t->index(['company_id','effective_from','effective_to'],'idx_snapshot_company_effective');$t->unique(['subscription_id','feature_code','effective_from','source'],'uq_snapshot_effective_source');});
        if (!Schema::hasTable('company_entitlement_overrides')) Schema::create('company_entitlement_overrides',function(Blueprint $t){$t->id();$t->bigInteger('company_id');$t->string('feature_code',100);$t->boolean('is_enabled')->nullable();$t->unsignedBigInteger('limit_value')->nullable();$t->dateTime('effective_from');$t->dateTime('effective_to')->nullable();$t->text('reason')->nullable();$t->bigInteger('created_by')->nullable();$t->timestamps();$t->index(['company_id','feature_code','effective_from'],'idx_override_company_feature_effective');});

        $now=now();$features=config('sulb_features.features',[]);$limits=config('sulb_features.limits',[]);
        foreach($features as $code=>$meta) DB::table('feature_catalog')->updateOrInsert(['feature_code'=>$code],['feature_name'=>$meta['name'],'feature_type'=>'BOOLEAN','module_name'=>$meta['module'],'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
        foreach($limits as $code) DB::table('feature_catalog')->updateOrInsert(['feature_code'=>$code],['feature_name'=>$code,'feature_type'=>'LIMIT','module_name'=>'limits','is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
        foreach(DB::table('plans')->get() as $plan){foreach(array_keys($features) as $code)DB::table('plan_features')->updateOrInsert(['plan_id'=>$plan->id,'feature_code'=>$code],['is_enabled'=>1,'updated_at'=>$now,'created_at'=>$now]);foreach(['max_users'=>'max_users','max_branches'=>'max_branches','max_vehicles'=>'max_cars','max_documents'=>'max_invoices'] as $code=>$legacy)DB::table('plan_features')->updateOrInsert(['plan_id'=>$plan->id,'feature_code'=>$code],['limit_value'=>$plan->{$legacy}??null,'updated_at'=>$now,'created_at'=>$now]);}
        foreach(DB::table('subscriptions')->get() as $subscription){foreach(DB::table('plan_features')->where('plan_id',$subscription->plan_id)->get() as $feature)DB::table('subscription_entitlement_snapshots')->updateOrInsert(['subscription_id'=>$subscription->id,'feature_code'=>$feature->feature_code,'effective_from'=>$subscription->start_date,'source'=>'PLAN'],['company_id'=>$subscription->company_id,'plan_id'=>$subscription->plan_id,'is_enabled'=>$feature->is_enabled,'limit_value'=>$feature->limit_value,'effective_to'=>$subscription->end_date,'created_at'=>$now,'updated_at'=>$now]);}
    }
    public function down(): void {Schema::dropIfExists('company_entitlement_overrides');Schema::dropIfExists('subscription_entitlement_snapshots');Schema::dropIfExists('plan_features');Schema::dropIfExists('feature_catalog');}
};
