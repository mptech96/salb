<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

/** Immutable L3 receipt, posted GL and an unissued purchase LOT are required. */
final class BaseLotCapitalization
{
    public static function receipt(object $lot,int $costId):array
    {
        $cost=DB::table('shipment_costs')->where('company_id',$lot->company_id)->where('shipment_id',$lot->shipment_id)->where('id',$costId)->first();
        $receipt=DB::table('shipment_cost_allocation_receipts')->where('company_id',$lot->company_id)->where('shipment_id',$lot->shipment_id)->where('shipment_cost_id',$costId)->first();
        if(!$cost||!$receipt||!in_array($cost->cost_status,['POSTED','VOID'],true)||!(int)$cost->capitalizable||$receipt->policy_version!=='LR_MILLI_V1'||(int)$receipt->journal_entry_id!==(int)$cost->journal_entry_id||!hash_equals($receipt->payload_hash,hash('sha256',$receipt->payload))||bccomp($cost->amount,$receipt->base_amount,3)!==0)throw new \RuntimeException('UOM_CAPITALIZATION_RECEIPT_REQUIRED');
        $entry=DB::table('journal_entries')->where('company_id',$lot->company_id)->where('branch_id',$lot->branch_id)->where('id',$receipt->journal_entry_id)->where('source_type','SHIPMENT_COST')->where('source_id',$costId)->where('status','POSTED')->first();
        if(!$entry)throw new \RuntimeException('UOM_CAPITALIZATION_GL_SOURCE');
        $reversal=null;
        if($cost->cost_status==='VOID'){
            $reversal=DB::table('journal_entries')->where('company_id',$lot->company_id)->where('branch_id',$lot->branch_id)->where('id',$entry->reversed_by_id)->where('reversal_of_id',$entry->id)->where('source_type','SHIPMENT_COST_REVERSAL')->where('status','POSTED')->first();if(!$entry->reversed_at||!$reversal)throw new \RuntimeException('UOM_CAPITALIZATION_GL_REVERSAL');
        }elseif($entry->reversed_at)throw new \RuntimeException('UOM_CAPITALIZATION_GL_SOURCE');
        $payload=json_decode($receipt->payload,true,512,JSON_THROW_ON_ERROR);$found=[];$accounts=[];$sum='0';
        foreach($payload['recipients'] as $row){$sum=bcadd($sum,$row['amount'],3);$account=(int)$row['account_id'];$accounts[$account]=bcadd($accounts[$account]??'0',$row['amount'],3);if((int)$row['inventory_lot_id']===(int)$lot->id)$found[]=$row;}
        if(count($found)!==1||(int)$found[0]['item_id']!==(int)$lot->item_id||bccomp($sum,$receipt->base_amount,3)!==0)throw new \RuntimeException('UOM_CAPITALIZATION_RECIPIENT');
        foreach($accounts as $account=>$value){$actual=DB::table('journal_entry_lines')->where('company_id',$lot->company_id)->where('journal_entry_id',$entry->id)->where('account_id',$account)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first()->v;if(bccomp($actual,$value,3)!==0)throw new \RuntimeException('UOM_CAPITALIZATION_GL_VALUE');if($reversal){$opposite=DB::table('journal_entry_lines')->where('company_id',$lot->company_id)->where('journal_entry_id',$reversal->id)->where('account_id',$account)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first()->v;if(bccomp($opposite,bcsub('0',$value,3),3)!==0)throw new \RuntimeException('UOM_CAPITALIZATION_GL_REVERSAL');}}
        return [$receipt,$found[0]['amount']];
    }
    public static function apply(int $company,int $lotId,int $costId):void
    {
        OperationalDocument::ready();if(!DB::getSchemaBuilder()->hasTable('uom_lot_capitalization_events'))throw new \RuntimeException('UOM_CAPITALIZATION_SCHEMA_REQUIRED');
        $lot=DB::table('inventory_lots')->where('company_id',$company)->where('id',$lotId)->lockForUpdate()->first();
        if(!$lot||!OperationalDocument::isBase($lot)||$lot->source_type!=='PURCHASE'||!$lot->shipment_id)throw new \RuntimeException('UOM_CAPITALIZATION_LOT_SCOPE');
        app(\App\Services\ShipmentInventoryLockScope::class)->assertPrepared($company,(int)$lot->shipment_id);
        if(!DB::table('shipment_costs')->where('company_id',$company)->where('id',$costId)->where('cost_status','POSTED')->exists())throw new \RuntimeException('UOM_CAPITALIZATION_SOURCE_INACTIVE');
        [$receipt,$amount]=self::receipt($lot,$costId);if(bccomp($amount,'0',3)===0)return;
        $old=DB::table('uom_lot_capitalization_events')->where('company_id',$company)->where('inventory_lot_id',$lotId)->where('shipment_cost_id',$costId)->first();
        if($old){if(!hash_equals($old->receipt_hash,$receipt->payload_hash)||bccomp($old->amount,$amount,3)!==0)throw new \RuntimeException('UOM_CAPITALIZATION_CONFLICT');self::audit($lot);return;}
        if(bccomp($lot->remaining_base_qty,$lot->received_base_qty,6)!==0||DB::table('inventory_lot_movements')->where('company_id',$company)->where('inventory_lot_id',$lotId)->where('movement_type','OUT')->exists())throw new \RuntimeException('UOM_CAPITALIZATION_AFTER_ISSUE');
        self::audit($lot);$value=bcadd($lot->uom_remaining_value,$amount,3);$total=bcadd($lot->total_cost,$amount,3);$unit=bcadd(bcdiv($total,$lot->received_base_qty,18),'0.0000005',6);
        $water=(int)DB::table('inventory_lot_movements')->where('company_id',$company)->where('inventory_lot_id',$lotId)->max('id');
        DB::table('uom_lot_capitalization_events')->insert(['company_id'=>$company,'branch_id'=>$lot->branch_id,'inventory_lot_id'=>$lotId,'shipment_id'=>$lot->shipment_id,'shipment_cost_id'=>$costId,'receipt_id'=>$receipt->id,'receipt_hash'=>$receipt->payload_hash,'amount'=>$amount,'before_value'=>$lot->uom_remaining_value,'after_value'=>$value,'quantity_movement_high_water'=>$water,'created_at'=>now()]);
        DB::table('inventory_lots')->where('company_id',$company)->where('id',$lotId)->update(['allocated_cost'=>bcadd($lot->allocated_cost,$amount,3),'total_cost'=>$total,'uom_remaining_value'=>$value,'unit_cost_per_base'=>$unit,'updated_at'=>now()]);
        $stock=DB::table('stock_movements')->where('company_id',$company)->where('inventory_lot_id',$lotId)->where('source_type','PURCHASE')->where('movement_type','IN')->where('uom_version','BASE_V1');
        if((clone $stock)->count()!==1)throw new \RuntimeException('UOM_CAPITALIZATION_STOCK_SOURCE');$stock->update(['total_cost'=>$total,'updated_at'=>now()]);
    }
    /** Read-only proof of allocated value; it never reconstructs missing money. */
    public static function audit(object $lot):string
    {
        $events=DB::getSchemaBuilder()->hasTable('uom_lot_capitalization_events')?DB::table('uom_lot_capitalization_events')->where('company_id',$lot->company_id)->where('inventory_lot_id',$lot->id)->orderBy('id')->get():collect();
        $sum='0';$previous=(string)$lot->base_cost;
        foreach($events as $event){[$receipt,$amount]=self::receipt($lot,(int)$event->shipment_cost_id);
            $first=DB::table('inventory_lot_movements')->where('company_id',$lot->company_id)->where('inventory_lot_id',$lot->id)->orderBy('id')->first();
            $firstOut=DB::table('inventory_lot_movements')->where('company_id',$lot->company_id)->where('inventory_lot_id',$lot->id)->where('movement_type','OUT')->orderBy('id')->first();
            if(!$first||$first->movement_type!=='IN'||$first->source_type!=='PURCHASE'||(int)$event->quantity_movement_high_water!==(int)$first->id||($firstOut&&$event->created_at>$firstOut->created_at))throw new \RuntimeException('UOM_CAPITALIZATION_EVIDENCE_REVIEW');
            if((int)$event->branch_id!==(int)$lot->branch_id||(int)$event->shipment_id!==(int)$lot->shipment_id||(int)$event->receipt_id!==(int)$receipt->id||!hash_equals($event->receipt_hash,$receipt->payload_hash)||bccomp($event->amount,$amount,3)!==0||bccomp($event->before_value,$previous,3)!==0||bccomp($event->after_value,bcadd($previous,$amount,3),3)!==0||DB::table('inventory_lot_movements')->where('company_id',$lot->company_id)->where('inventory_lot_id',$lot->id)->where('id','<=',$event->quantity_movement_high_water)->where('movement_type','OUT')->exists())throw new \RuntimeException('UOM_CAPITALIZATION_EVIDENCE_REVIEW');
            $previous=$event->after_value;$sum=bcadd($sum,$amount,3);
        }
        if(bccomp($sum,$lot->allocated_cost,3)!==0||bccomp(bcadd($lot->base_cost,$sum,3),$lot->total_cost,3)!==0)throw new \RuntimeException('UOM_CAPITALIZATION_EVIDENCE_REVIEW');return $sum;
    }
}
