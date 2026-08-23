<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarController extends Controller
{
    public function meta(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);$scoped=$ctx->branchFilter($r);
        return response()->json(['status'=>true,'data'=>[
            'branches'=>DB::table('branches')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where('id',$scoped))->orderBy('branch_name')->get(['id','branch_name']),
            'suppliers'=>DB::table('suppliers')->where('company_id',$cid)->where('is_active',1)->orderBy('supplier_name')->get(['id','supplier_name']),
            'customers'=>DB::table('customers')->where('company_id',$cid)->where('is_active',1)->orderBy('customer_name')->get(['id','customer_name']),
            'drivers'=>DB::table('drivers')->where('company_id',$cid)->where('is_active',1)->orderBy('driver_name')->get(['id','driver_name']),
            'ownership_types'=>[['code'=>'COMPANY','name'=>'الشركة'],['code'=>'SUPPLIER','name'=>'مورد'],['code'=>'CUSTOMER','name'=>'عميل'],['code'=>'CARRIER','name'=>'ناقل / شركة نقل'],['code'=>'OTHER','name'=>'طرف آخر']],
        ]]);
    }

    public function index(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);
        $q=DB::table('cars as car')->leftJoin('suppliers as s','s.id','=','car.supplier_id')->leftJoin('drivers as d','d.id','=','car.driver_id')->leftJoin('branches as b','b.id','=','car.branch_id')->where('car.company_id',$cid);
        if($bid!==null)$q->where(fn($x)=>$x->where('car.branch_id',$bid)->orWhereNull('car.branch_id'));
        return response()->json(['status'=>true,'data'=>$q->select('car.*','s.supplier_name','d.driver_name','b.branch_name',DB::raw('(SELECT COUNT(*) FROM weighbridge_cards w WHERE w.company_id=car.company_id AND w.car_id=car.id) weighbridge_count'),DB::raw('(SELECT COUNT(*) FROM shipments sh WHERE sh.company_id=car.company_id AND sh.car_id=car.id) shipment_count'))->orderBy('car.plate_number')->get()]);
    }
    public function show(Request $r,int $id,AccountingContext $ctx){$cid=$ctx->companyId($r);$q=DB::table('cars')->where('company_id',$cid)->where('id',$id);$bid=$ctx->branchFilter($r);if($bid!==null)$q->where(fn($x)=>$x->where('branch_id',$bid)->orWhereNull('branch_id'));$x=$q->first();return$x?response()->json(['status'=>true,'data'=>$x]):response()->json(['status'=>false,'message'=>'السيارة غير موجودة.'],404);}
    public function store(Request $r,AccountingContext $ctx){return$this->save($r,$ctx,null);}
    public function update(Request $r,int $id,AccountingContext $ctx){return$this->save($r,$ctx,$id);}
    public function destroy(Request $r,int $id,AccountingContext $ctx){$cid=$ctx->companyId($r);$car=DB::table('cars')->where('company_id',$cid)->where('id',$id)->first();if(!$car)return response()->json(['status'=>false,'message'=>'السيارة غير موجودة.'],404);if(DB::table('weighbridge_cards')->where('company_id',$cid)->where('car_id',$id)->exists()||DB::table('shipments')->where('company_id',$cid)->where('car_id',$id)->exists()){DB::table('cars')->where('id',$id)->update(['is_active'=>0,'updated_at'=>now()]);return response()->json(['status'=>true,'message'=>'للسيارة سجل تشغيلي؛ تم تعطيلها بدل حذفها.']);}DB::table('cars')->where('id',$id)->delete();return response()->json(['status'=>true,'message'=>'تم حذف السيارة غير المستخدمة.']);}

    private function save(Request $r,AccountingContext $ctx,?int $id)
    {
        $v=$r->validate(['branch_id'=>'nullable|integer','driver_id'=>'nullable|integer','car_number'=>'nullable|string|max:100','plate_number'=>'required|string|max:100','ownership_type'=>'required|in:COMPANY,SUPPLIER,CUSTOMER,CARRIER,OTHER','owner_party_id'=>'nullable|integer','owner_name'=>'nullable|string|max:255','vehicle_type'=>'nullable|string|max:100','make_name'=>'nullable|string|max:120','model_name'=>'nullable|string|max:120','model_year'=>'nullable|integer|min:1900|max:2200','notes'=>'nullable|string','is_active'=>'nullable|boolean']);
        try{
            $cid=$ctx->companyId($r);$scoped=$ctx->branchFilter($r);$branchId=$scoped??(isset($v['branch_id'])&&(int)$v['branch_id']>0?(int)$v['branch_id']:null);
            if($branchId&&!DB::table('branches')->where('company_id',$cid)->where('id',$branchId)->where('is_active',1)->exists())throw new \RuntimeException('الفرع غير صالح.');
            $ownership=strtoupper($v['ownership_type']);$ownerId=isset($v['owner_party_id'])&&(int)$v['owner_party_id']>0?(int)$v['owner_party_id']:null;$ownerType=null;$supplierId=null;$ownerName=trim((string)($v['owner_name']??''))?:null;
            if($ownership==='SUPPLIER'){$ownerType='SUPPLIER';if(!$ownerId||!DB::table('suppliers')->where('company_id',$cid)->where('id',$ownerId)->exists())throw new \RuntimeException('اختر المورد مالك السيارة.');$supplierId=$ownerId;$ownerName=DB::table('suppliers')->where('id',$ownerId)->value('supplier_name');}
            elseif($ownership==='CUSTOMER'){$ownerType='CUSTOMER';if(!$ownerId||!DB::table('customers')->where('company_id',$cid)->where('id',$ownerId)->exists())throw new \RuntimeException('اختر العميل مالك السيارة.');$ownerName=DB::table('customers')->where('id',$ownerId)->value('customer_name');}
            elseif($ownership==='COMPANY'){$ownerType='COMPANY';$ownerId=null;$ownerName=DB::table('companies')->where('id',$cid)->value('company_name')?:'الشركة';}
            elseif($ownership==='CARRIER'){$ownerType='CARRIER';if(!$ownerName)throw new \RuntimeException('اكتب اسم شركة/جهة النقل.');}
            else{$ownerType='OTHER';if(!$ownerName)throw new \RuntimeException('اكتب اسم مالك السيارة.');}
            if(!empty($v['driver_id'])&&!DB::table('drivers')->where('company_id',$cid)->where('id',$v['driver_id'])->where('is_active',1)->exists())throw new \RuntimeException('السائق الافتراضي غير صالح.');
            $plate=trim((string)preg_replace('/\$/u','',$v['plate_number']));$norm=preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtoupper($plate))?:'';if(DB::table('cars')->where('company_id',$cid)->where('normalized_plate_number',$norm)->when($id,fn($q)=>$q->where('id','<>',$id))->exists())throw new \RuntimeException('رقم اللوحة مسجل مسبقًا داخل الشركة.');
            $data=['branch_id'=>$branchId,'supplier_id'=>$supplierId,'driver_id'=>$v['driver_id']??null,'car_number'=>$v['car_number']??null,'plate_number'=>$plate,'normalized_plate_number'=>$norm,'ownership_type'=>$ownership,'owner_party_type'=>$ownerType,'owner_party_id'=>$ownerId,'owner_name'=>$ownerName,'vehicle_type'=>$v['vehicle_type']??null,'make_name'=>$v['make_name']??null,'model_name'=>$v['model_name']??null,'model_year'=>$v['model_year']??null,'notes'=>$v['notes']??null,'is_active'=>(int)($v['is_active']??1),'updated_at'=>now()];
            if($id){if(!DB::table('cars')->where('company_id',$cid)->where('id',$id)->exists())return response()->json(['status'=>false,'message'=>'السيارة غير موجودة.'],404);DB::table('cars')->where('id',$id)->update($data);return response()->json(['status'=>true,'message'=>'تم تحديث السيارة.']);}
            $new=DB::table('cars')->insertGetId(['company_id'=>$cid,...$data,'gross_weight'=>0,'deduction_weight'=>0,'net_weight'=>0,'transport_cost'=>0,'extra_cost'=>0,'car_status'=>'MASTER','created_at'=>now()]);return response()->json(['status'=>true,'message'=>'تمت إضافة السيارة إلى الدليل.','id'=>$new],201);
        }catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }
}
