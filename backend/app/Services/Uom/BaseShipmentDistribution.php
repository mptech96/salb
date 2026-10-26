<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

/** Mixed shipments consume the same immutable L3 receipt used by the GL writer. */
final class BaseShipmentDistribution
{
    public function distribute(int $company,int $shipment,$items,$pending):array
    {
        OperationalDocument::ready();if(!config('shipment_allocation.enabled')||!config('shipment_cost_read.enabled'))throw new \RuntimeException('UOM_SHIPMENT_L3_REQUIRED');
        app(\App\Services\ShipmentInventoryLockScope::class)->assertPrepared($company,$shipment);
        $header=DB::table('shipments')->where('company_id',$company)->where('id',$shipment)->first();if(!$header)throw new \RuntimeException('UOM_CAPITALIZATION_LOT_SCOPE');
        $shares=app(\App\Services\ShipmentAllocationLedger::class)->sharesForDistribution($company,$shipment,$pending);$new='0.000';foreach($pending as $cost)$new=bcadd($new,$cost->amount,3);$out=[];
        foreach($items as $item){$lot=DB::table('inventory_lots')->where('company_id',$company)->where('id',$item->inventory_lot_id)->lockForUpdate()->first();
            if(!$lot||(int)$lot->item_id!==(int)$item->item_id||(int)$lot->branch_id!==(int)$header->branch_id||(int)$lot->shipment_id!==$shipment||(int)$lot->shipment_item_id!==(int)$item->id)throw new \RuntimeException('UOM_CAPITALIZATION_LOT_SCOPE');
            if(DB::table('inventory_lot_movements')->where('company_id',$company)->where('inventory_lot_id',$lot->id)->where('movement_type','OUT')->exists())throw new \RuntimeException('UOM_CAPITALIZATION_AFTER_ISSUE');
            $amount=$shares[$item->id]??'0.000';
            if(OperationalDocument::isBase($lot)){foreach($pending as $cost)BaseLotCapitalization::apply($company,(int)$lot->id,(int)$cost->id);}
            elseif(bccomp($amount,'0',3)>0){
                $roundTrip=(string)(float)$amount;if(!preg_match('/^\d+(?:\.\d+)?$/D',$roundTrip)||bccomp($roundTrip,$amount,3)!==0)throw new \RuntimeException('UOM_LEGACY_CAPITALIZATION_PRECISION');
                app(\App\Services\InventoryLotService::class)->addCapitalizedCost($company,(int)$lot->id,(float)$amount);
                $actual=DB::table('inventory_lots')->where('company_id',$company)->where('id',$lot->id)->first();if(bccomp($actual->total_cost,bcadd($lot->total_cost,$amount,3),3)!==0)throw new \RuntimeException('UOM_LEGACY_CAPITALIZATION_PRECISION');
            }
            $lot=DB::table('inventory_lots')->where('company_id',$company)->where('id',$lot->id)->first();$base=OperationalDocument::isBase($lot);$perKg=$base?'0.000000':$lot->unit_cost_per_kg;
            DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$shipment)->where('id',$item->id)->update(['allocated_cost'=>$lot->allocated_cost,'distributed_cost'=>$lot->allocated_cost,'final_cost'=>$lot->total_cost,'final_unit_cost_per_kg'=>$perKg,'average_cost'=>$base?0:round((float)$perKg*1000,3),'updated_at'=>now()]);
            if(!$base)DB::table('stock_movements')->where('company_id',$company)->where('inventory_lot_id',$lot->id)->where('source_type','PURCHASE')->where('movement_type','IN')->update(['unit_cost'=>round((float)$perKg*1000,3),'unit_cost_per_kg'=>$perKg,'total_cost'=>$lot->total_cost,'updated_at'=>now()]);
            $out[]=['shipment_item_id'=>(int)$item->id,'allocated_now'=>$amount,'final_cost'=>$lot->total_cost,'quantity_version'=>$base?'BASE_V1':'LEGACY_KG'];
        }
        DB::table('shipment_costs')->where('company_id',$company)->where('shipment_id',$shipment)->whereIn('id',$pending->pluck('id'))->update(['distributed'=>1,'updated_at'=>now()]);
        $total=DB::table('shipment_costs')->where('company_id',$company)->where('shipment_id',$shipment)->where('capitalizable',1)->where('distributed',1)->selectRaw('COALESCE(SUM(amount),0) v')->first()->v;
        DB::table('shipments')->where('company_id',$company)->where('id',$shipment)->update(['distributed_cost'=>$total,'costing_status'=>'COSTED','updated_at'=>now()]);return ['shipment_id'=>$shipment,'new_distributed_cost'=>$new,'total_distributed_cost'=>$total,'items'=>$out];
    }
}
