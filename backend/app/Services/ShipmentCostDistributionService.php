<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class ShipmentCostDistributionService
{
    public function __construct(private InventoryLotService $lots){}

    public function distributeByShipmentId(int $cid,int $sid): array
    {
        return DB::transaction(function()use($cid,$sid){
            $shipment=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();
            if(!$shipment)throw new \RuntimeException('الشحنة غير موجودة.');
            $items=DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->orderBy('sorting_order')->lockForUpdate()->get();
            if($items->isEmpty())throw new \RuntimeException('لا توجد أصناف في الشحنة.');
            $pending=DB::table('shipment_costs')->where('company_id',$cid)->where('shipment_id',$sid)->where('cost_status','POSTED')->where('capitalizable',1)->where('distributed',0)->lockForUpdate()->get();
            $newCost=round((float)$pending->sum('amount'),3);
            if($newCost<=0)return ['shipment_id'=>$sid,'new_distributed_cost'=>0,'items'=>[]];

            foreach($items as$it){
                if(!$it->inventory_lot_id)throw new \RuntimeException('لم تُنشأ دفعة مخزون لأحد أصناف الشحنة بعد.');
                $lot=DB::table('inventory_lots')->where('company_id',$cid)->where('id',$it->inventory_lot_id)->lockForUpdate()->first();
                if(!$lot)throw new \RuntimeException('دفعة المخزون غير موجودة.');
                if(round((float)$lot->qty_remaining_kg,3)<round((float)$lot->qty_received_kg,3))throw new \RuntimeException('لا يمكن رسملة تكلفة جديدة بعد بدء الصرف من مخزون هذه الشحنة.');
            }

            $method=strtoupper((string)($shipment->cost_allocation_method??'WEIGHT'));
            $basis=[];$basisTotal=0.0;
            foreach($items as$it){
                $v=match($method){
                    'RELATIVE_VALUE'=>(float)($it->base_cost??0),
                    'MANUAL_PERCENT'=>(float)($it->cost_share_percent??0),
                    'MANUAL_COST'=>(float)($it->manual_allocated_cost??0),
                    default=>(float)($it->accepted_qty_kg??$it->qty_kg??0),
                };
                $basis[$it->id]=max(0,$v);$basisTotal+=max(0,$v);
            }
            if($basisTotal<=0){foreach($items as$it){$basis[$it->id]=max(0,(float)($it->accepted_qty_kg??$it->qty_kg??0));$basisTotal+=$basis[$it->id];}}
            if($basisTotal<=0)throw new \RuntimeException('لا يوجد أساس صالح لتوزيع تكلفة الشحنة.');

            $remain=$newCost;$last=(int)$items->last()->id;$out=[];
            foreach($items as$it){
                $share=(int)$it->id===$last?$remain:round($newCost*($basis[$it->id]/$basisTotal),3);$remain=round($remain-$share,3);
                $newAlloc=round((float)($it->allocated_cost??0)+$share,3);$base=round((float)($it->base_cost??0),3);$final=round($base+$newAlloc,3);$kg=(float)($it->accepted_qty_kg??$it->qty_kg??0);$unitKg=$kg>0?round($final/$kg,6):0;
                $this->lots->addCapitalizedCost($cid,(int)$it->inventory_lot_id,$share);
                DB::table('shipment_items')->where('id',$it->id)->update(['allocated_cost'=>$newAlloc,'distributed_cost'=>$newAlloc,'final_cost'=>$final,'final_unit_cost_per_kg'=>$unitKg,'average_cost'=>round($unitKg*1000,3),'updated_at'=>now()]);
                DB::table('stock_movements')->where('company_id',$cid)->where('inventory_lot_id',$it->inventory_lot_id)->where('movement_type','IN')->update(['unit_cost'=>round($unitKg*1000,3),'unit_cost_per_kg'=>$unitKg,'total_cost'=>$final,'updated_at'=>now()]);
                $out[]=['shipment_item_id'=>(int)$it->id,'allocated_now'=>$share,'final_cost'=>$final,'unit_cost_per_kg'=>$unitKg];
            }
            DB::table('shipment_costs')->whereIn('id',$pending->pluck('id'))->update(['distributed'=>1,'updated_at'=>now()]);
            $total=round((float)DB::table('shipment_costs')->where('company_id',$cid)->where('shipment_id',$sid)->where('capitalizable',1)->where('distributed',1)->sum('amount'),3);
            DB::table('shipments')->where('id',$sid)->update(['distributed_cost'=>$total,'costing_status'=>'COSTED','updated_at'=>now()]);
            return ['shipment_id'=>$sid,'allocation_method'=>$method,'new_distributed_cost'=>$newCost,'total_distributed_cost'=>$total,'items'=>$out];
        });
    }
}
