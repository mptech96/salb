<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Entitlement\EffectiveEntitlementService;
use App\Services\Entitlement\EntitlementSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EntitlementAdminController extends Controller
{
    public function catalog(){return response()->json(['status'=>true,'data'=>DB::table('feature_catalog')->where('is_active',1)->orderBy('feature_code')->get()]);}
    public function plan(int $planId){return response()->json(['status'=>true,'data'=>DB::table('plan_features')->where('plan_id',$planId)->orderBy('feature_code')->get()]);}
    public function updatePlan(Request $r,int $planId){$v=$r->validate(['features'=>'required|array','features.*.feature_code'=>'required|string|exists:feature_catalog,feature_code','features.*.is_enabled'=>'nullable|boolean','features.*.limit_value'=>'nullable|integer|min:0']);DB::transaction(function()use($v,$planId){DB::table('plans')->where('id',$planId)->lockForUpdate()->firstOrFail();foreach($v['features'] as$f)DB::table('plan_features')->updateOrInsert(['plan_id'=>$planId,'feature_code'=>$f['feature_code']],['is_enabled'=>$f['is_enabled']??null,'limit_value'=>$f['limit_value']??null,'updated_at'=>now(),'created_at'=>now()]);});return $this->plan($planId);}
    public function effective(EffectiveEntitlementService $service,int $companyId){return response()->json(['status'=>true,'data'=>$service->resolve($companyId)]);}
    public function override(Request $r,int $companyId){$v=$r->validate(['feature_code'=>'required|string|exists:feature_catalog,feature_code','is_enabled'=>'nullable|boolean','limit_value'=>'nullable|integer|min:0','effective_from'=>'required|date','effective_to'=>'nullable|date|after_or_equal:effective_from','reason'=>'required|string|max:1000']);$id=DB::table('company_entitlement_overrides')->insertGetId([...$v,'company_id'=>$companyId,'created_by'=>$r->user()?->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>true,'id'=>$id],201);}
    public function snapshot(EntitlementSnapshotService $service,int $subscriptionId){return response()->json(['status'=>true,'rows'=>$service->capture($subscriptionId)]);}
}
