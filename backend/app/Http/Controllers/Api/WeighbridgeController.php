<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\WeighbridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WeighbridgeController extends Controller
{
    public function index(Request $r, AccountingContext $c, WeighbridgeService $s)
    { return response()->json(['status'=>true,'data'=>$s->listCards($c->companyId($r),$c->branchFilter($r))]); }

    public function availableShipments(Request $r, AccountingContext $c)
    {
        $cid=$c->companyId($r);$bid=$c->branchFilter($r);
        $q=DB::table('shipments as s')->leftJoin('cars as c','c.id','=','s.car_id')->leftJoin('drivers as d','d.id','=','s.driver_id')
            ->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')->leftJoin('customers as cu','cu.id','=','s.customer_id')->leftJoin('branches as b','b.id','=','s.branch_id')
            ->where('s.company_id',$cid)->where('s.commercial_status','DRAFT');
        if($bid!==null)$q->where('s.branch_id',$bid);
        return response()->json(['status'=>true,'data'=>$q->select(
            's.id','s.shipment_number','s.shipment_date','s.shipment_type','s.branch_id','s.car_id','s.driver_id','b.branch_name','c.car_number','c.plate_number','d.driver_name','sp.supplier_name','cu.customer_name',
            DB::raw('(SELECT COUNT(*) FROM weighbridge_cards wc WHERE wc.company_id=s.company_id AND wc.shipment_id=s.id) card_count')
        )->orderByDesc('s.id')->get()]);
    }

    public function meta(Request $r, AccountingContext $c)
    {
        $cid=$c->companyId($r);$bf=$c->branchFilter($r);
        $branches=DB::table('branches')->where('company_id',$cid)->when($bf!==null,fn($q)=>$q->where('id',$bf))->orderBy('branch_name')->get(['id','branch_name']);
        $items=DB::table('items')->where('company_id',$cid)->where('is_active',1)
            ->whereRaw("UPPER(COALESCE(item_type,'STOCK')) <> 'SERVICE'")
            ->orderBy('item_name')->get(['id','item_code','item_name','item_type','track_inventory','can_purchase','can_sell']);
        return response()->json(['status'=>true,'data'=>[
            'branches'=>$branches,'items'=>$items,
            'flows'=>[
                ['code'=>'PURCHASE_INBOUND','name'=>'وارد شراء'],['code'=>'SALE_OUTBOUND','name'=>'صادر بيع'],['code'=>'INTERNAL_TRANSFER','name'=>'حركة داخلية'],
            ],
            'transport_modes'=>[
                ['code'=>'VEHICLE','name'=>'سيارة مسجلة'],
                ['code'=>'YARD_SCALE','name'=>'ميزان الحوش / بدون سيارة'],
                ['code'=>'SMALL_SCALE','name'=>'الميزان الصغير'],
                ['code'=>'NO_VEHICLE','name'=>'بدون سيارة'],
            ],
        ]]);
    }

    public function open(Request $r, AccountingContext $c, WeighbridgeService $s)
    {
        $v=$r->validate([
            'shipment_id'=>'nullable|integer','branch_id'=>'nullable|integer','flow_type'=>'nullable|in:PURCHASE_INBOUND,SALE_OUTBOUND,INTERNAL_TRANSFER',
            'item_id'=>'required|integer','car_id'=>'nullable|integer','transport_mode'=>'nullable|in:VEHICLE,YARD_SCALE,SMALL_SCALE,NO_VEHICLE','transport_label'=>'nullable|string|max:150',
            'driver_id'=>'nullable|integer','entry_at'=>'nullable|date','deduction_weight_kg'=>'nullable|numeric|min:0',
            'scale_name'=>'nullable|string|max:120','external_ticket_number'=>'nullable|string|max:120','notes'=>'nullable|string','unassigned_reason'=>'nullable|string|max:1000',
        ]);
        try {
            $cid=$c->companyId($r);$branchId=null;
            if(!empty($v['shipment_id'])){
                $sh=DB::table('shipments')->where('company_id',$cid)->where('id',(int)$v['shipment_id'])->first();
                if(!$sh)throw new \RuntimeException('الشحنة غير موجودة.');
                $bf=$c->branchFilter($r);if($bf!==null&&(int)$sh->branch_id!==$bf)throw new \RuntimeException('الشحنة خارج نطاق فرعك.');
                $branchId=(int)$sh->branch_id;
            } else {
                $branchId=$c->branchForOperation($r);
                if(!empty($v['branch_id'])&&(int)$v['branch_id']!==$branchId && $c->branchFilter($r)!==null) throw new \RuntimeException('الفرع المحدد خارج نطاقك.');
                if($c->branchFilter($r)===null && !empty($v['branch_id'])) $branchId=(int)$v['branch_id'];
            }
            return response()->json([
                'status'=>true,
                'message'=>'تم فتح كرت الميزان وتثبيت الصنف ووقت الدخول. الخصم والتسعير سيبقيان داخل الشحنة.',
                'data'=>$s->openCard([...$v,'company_id'=>$cid,'branch_id'=>$branchId,'created_by'=>$c->userId($r)],$c->branchFilter($r))
            ],201);
        } catch(\Throwable $e){ return response()->json(['status'=>false,'message'=>$e->getMessage()],422); }
    }

    public function linkShipment(Request $r, int $id, AccountingContext $c, WeighbridgeService $s)
    {
        $v=$r->validate(['shipment_id'=>'required|integer']);
        try {
            $bid=$this->branch($r,$id,$c);
            return response()->json(['status'=>true,'message'=>'تم ربط كرت الميزان بالشحنة، وتم تحديث صنف/وزن الشحنة تلقائيًا.','data'=>$s->linkShipment($id,(int)$v['shipment_id'],['company_id'=>$c->companyId($r),'branch_id'=>$bid,'created_by'=>$c->userId($r)])]);
        } catch(\Throwable $e){ return response()->json(['status'=>false,'message'=>$e->getMessage()],422); }
    }

    public function material(Request $r,int $id,AccountingContext $c,WeighbridgeService $s)
    {
        $v=$r->validate(['item_id'=>'required|integer','reason'=>'nullable|string|max:500']);
        try{$bid=$this->branch($r,$id,$c);return response()->json(['status'=>true,'message'=>'تم تثبيت/تصحيح صنف كرت الميزان.','data'=>$s->assignItem($id,[...$v,'company_id'=>$c->companyId($r),'branch_id'=>$bid,'created_by'=>$c->userId($r)])]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function show(Request $r,int $id,AccountingContext $c,WeighbridgeService $s)
    { try{$bid=$this->branch($r,$id,$c);return response()->json(['status'=>true,'data'=>$s->details($c->companyId($r),$bid,$id)]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],404);} }

    public function recordWeight(Request $r,int $id,AccountingContext $c,WeighbridgeService $s)
    {
        $v=$r->validate(['event_type'=>'required|in:LOADED,EMPTY,RECHECK,CORRECTION','effective_weight_type'=>'nullable|in:LOADED,EMPTY','weight_kg'=>'required|numeric|gt:0','recorded_at'=>'nullable|date','scale_name'=>'nullable|string|max:120','ticket_number'=>'nullable|string|max:120','notes'=>'nullable|string']);
        try{$bid=$this->branch($r,$id,$c);return response()->json(['status'=>true,'message'=>'تم تسجيل قراءة الوزن.','data'=>$s->recordWeight($id,[...$v,'company_id'=>$c->companyId($r),'branch_id'=>$bid,'created_by'=>$c->userId($r)])],201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function deduction(Request $r,int $id,AccountingContext $c,WeighbridgeService $s)
    { $v=$r->validate(['deduction_weight_kg'=>'required|numeric|min:0']);try{$bid=$this->branch($r,$id,$c);return response()->json(['status'=>true,'message'=>'تم تحديث الخصم العام للكرت.','data'=>$s->updateDeduction($id,[...$v,'company_id'=>$c->companyId($r),'branch_id'=>$bid])]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);} }

    public function close(Request $r,int $id,AccountingContext $c,WeighbridgeService $s)
    { $v=$r->validate(['exit_at'=>'nullable|date']);try{$bid=$this->branch($r,$id,$c);return response()->json(['status'=>true,'message'=>'تم إغلاق الكرت وتسجيل الخروج وربط وزنه بصنف الشحنة.','data'=>$s->closeCard($id,[...$v,'company_id'=>$c->companyId($r),'branch_id'=>$bid,'created_by'=>$c->userId($r)])]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);} }

    public function cancelWeight(Request $r,int $weightId,AccountingContext $c,WeighbridgeService $s)
    { $v=$r->validate(['reason'=>'required|string|min:5|max:1000']);$w=DB::table('shipment_weights')->where('company_id',$c->companyId($r))->where('id',$weightId)->first();if(!$w)return response()->json(['status'=>false,'message'=>'قراءة الوزن غير موجودة.'],404);$bf=$c->branchFilter($r);if($bf!==null&&(int)$w->branch_id!==$bf)return response()->json(['status'=>false,'message'=>'القراءة خارج نطاق فرعك.'],403);try{return response()->json(['status'=>true,'message'=>'تم إلغاء القراءة مع الاحتفاظ بها في الأثر.','data'=>$s->cancelWeight($weightId,[...$v,'company_id'=>$c->companyId($r),'branch_id'=>(int)$w->branch_id,'created_by'=>$c->userId($r)])]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);} }

    private function branch(Request $r,int $id,AccountingContext $c): int
    { $card=DB::table('weighbridge_cards')->where('company_id',$c->companyId($r))->where('id',$id)->first();if(!$card)throw new \RuntimeException('كرت الميزان غير موجود.');$bf=$c->branchFilter($r);if($bf!==null&&(int)$card->branch_id!==$bf)throw new \RuntimeException('كرت الميزان خارج نطاق فرعك.');return (int)$card->branch_id; }
}
