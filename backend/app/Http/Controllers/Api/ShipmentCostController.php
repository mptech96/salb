<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\ShipmentCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentCostController extends Controller
{
    public function types(Request $r,AccountingContext $c){$cid=$c->companyId($r);return response()->json(['status'=>true,'data'=>DB::table('expense_types')->where('is_active',1)->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->whereIn('usage_type',['SHIPMENT','BOTH','GENERAL'])->orderBy('type_name')->get()]);}
    public function index(Request $r,int $shipmentId,ShipmentCostService $s,AccountingContext $c){try{return response()->json(['status'=>true,'data'=>$s->summary($c->companyId($r),$c->branchFilter($r),$shipmentId)]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],404);}}
    public function store(Request $r,ShipmentCostService $s,AccountingContext $c)
    {
        $v=$this->validateCost($r);$sh=DB::table('shipments')->where('company_id',$c->companyId($r))->where('id',(int)$v['shipment_id'])->first();if(!$sh)return response()->json(['status'=>false,'message'=>'الشحنة غير موجودة.'],404);$bf=$c->branchFilter($r);if($bf!==null&&(int)$sh->branch_id!==$bf)return response()->json(['status'=>false,'message'=>'الشحنة خارج نطاق الفرع.'],403);
        try{$id=$s->storeDraft([...$v,'company_id'=>$c->companyId($r),'branch_id'=>(int)$sh->branch_id,'created_by'=>$c->userId($r)]);return response()->json(['status'=>true,'message'=>'تم حفظ تكلفة الشحنة كمسودة. ستُثبت محاسبيًا عند ترحيل فاتورة الشراء.','id'=>$id,'data'=>$s->summary($c->companyId($r),$c->branchFilter($r),(int)$v['shipment_id'])],201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }
    public function update(Request $r,int $id,ShipmentCostService $s,AccountingContext $c){$v=$this->validateCost($r,false);try{$s->updateDraft($c->companyId($r),$id,$v,$c->branchFilter($r));return response()->json(['status'=>true,'message'=>'تم تحديث تكلفة الشحنة المسودة.']);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function destroy(Request $r,int $id,ShipmentCostService $s,AccountingContext $c){try{$s->deleteDraft($c->companyId($r),$id,$c->branchFilter($r));return response()->json(['status'=>true,'message'=>'تم حذف تكلفة الشحنة المسودة.']);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}

    private function validateCost(Request $r,bool $shipmentRequired=true):array{return $r->validate([
        'shipment_id'=>[$shipmentRequired?'required':'sometimes','integer'],'expense_type_id'=>'required|integer','expense_date'=>'required|date','amount'=>'required|numeric|gt:0','currency_code'=>'nullable|string|max:10','exchange_rate'=>'nullable|numeric|gt:0','payment_status'=>'required|in:PAID,UNPAID','payment_method'=>'nullable|string|max:50','financial_account_id'=>'nullable|integer','capitalizable'=>'nullable|boolean','payee_type'=>'nullable|in:SUPPLIER,CUSTOMER,DRIVER,CARRIER,OTHER','payee_id'=>'nullable|integer','notes'=>'nullable|string|max:2000']);}
}
