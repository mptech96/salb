<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\ShipmentWeighbridgeAllocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ShipmentWeighbridgeAllocationController extends Controller
{
    public function show(Request $r,int $shipmentId,AccountingContext $c,ShipmentWeighbridgeAllocationService $s)
    { try{$bid=$this->branch($r,$shipmentId,$c);return response()->json(['status'=>true,'data'=>$s->summary($c->companyId($r),$shipmentId)]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);} }
    public function update(Request $r,int $shipmentId,AccountingContext $c,ShipmentWeighbridgeAllocationService $s)
    { $v=$r->validate(['allocations'=>'array|max:1000','allocations.*.weighbridge_card_id'=>'required|integer','allocations.*.item_id'=>'required|integer','allocations.*.gross_qty_kg'=>'required|numeric|gt:0','allocations.*.deduction_qty_kg'=>'nullable|numeric|min:0','allocations.*.deduction_reason'=>'nullable|string|max:500','allocations.*.notes'=>'nullable|string|max:1000']);try{$bid=$this->branch($r,$shipmentId,$c);return response()->json(['status'=>true,'message'=>'تم حفظ توزيع كروت الميزان على الأصناف.','data'=>$s->replace($c->companyId($r),$bid,$shipmentId,$v['allocations']??[],$c->userId($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);} }
    private function branch(Request $r,int $sid,AccountingContext $c): int { $s=DB::table('shipments')->where('company_id',$c->companyId($r))->where('id',$sid)->first();if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');$bf=$c->branchFilter($r);if($bf!==null&&(int)$s->branch_id!==$bf)throw new \RuntimeException('الشحنة خارج نطاق فرعك.');return (int)$s->branch_id; }
}
