<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

/** Recognizes recorded L3 evidence only; missing legacy values remain under review. */
final class LegacyCapitalizationEvidence
{
    public static function proven(object $lot):?string
    {
        if(bccomp((string)$lot->allocated_cost,'0',3)===0)return '0.000';
        if(!$lot->shipment_id||!DB::getSchemaBuilder()->hasTable('shipment_cost_allocation_receipts'))return null;
        try{
            $sum='0';$costs=[];
            foreach(DB::table('shipment_cost_allocation_receipts')->where('company_id',$lot->company_id)->where('shipment_id',$lot->shipment_id)->get() as $receipt){
                $payload=json_decode($receipt->payload,true,512,JSON_THROW_ON_ERROR);if(!collect($payload['recipients'])->contains(fn($row)=>(int)$row['inventory_lot_id']===(int)$lot->id))continue;
                if(isset($costs[$receipt->shipment_cost_id]))return null;$costs[$receipt->shipment_cost_id]=true;[, $amount]=BaseLotCapitalization::receipt($lot,(int)$receipt->shipment_cost_id);$sum=bcadd($sum,$amount,3);
            }
            if(bccomp($sum,$lot->allocated_cost,3)!==0||bccomp(bcadd($lot->base_cost,$sum,3),$lot->total_cost,3)!==0)return null;return $sum;
        }catch(\Throwable){return null;}
    }
    public static function balance(object $lot):?object
    {
        if(!config('inventory_value.enabled')||!DB::getSchemaBuilder()->hasTable('inventory_lot_value_balances'))return null;
        $row=DB::table('inventory_lot_value_balances')->where('company_id',$lot->company_id)->where('inventory_lot_id',$lot->id)->first();if(!$row)return null;
        if($row->policy_version!=='FINAL_RESIDUAL_V1'||bccomp($row->original_quantity,$lot->qty_received_kg,3)||bccomp($row->remaining_quantity,$lot->qty_remaining_kg,3)||bccomp($row->original_value,$lot->total_cost,3)||bccomp($row->remaining_value,'0',3)<0||bccomp($row->remaining_value,$row->original_value,3)>0||(bccomp($row->remaining_quantity,'0',3)===0&&bccomp($row->remaining_value,'0',3)!==0))throw new \RuntimeException('LOT_VALUE_BALANCE_MISMATCH');return $row;
    }
}
