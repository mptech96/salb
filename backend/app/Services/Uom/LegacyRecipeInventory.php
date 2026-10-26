<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;
use App\Services\InventoryLotValue;
use App\Services\InventoryLotService;

/** Explicit recipe bridge for qualified legacy KG lots; no change of quantity mode. */
final class LegacyRecipeInventory
{
    private static function ready(int $company,int $item):void
    {
        OperationalDocument::ready();
        $row=DB::table('items')->where('company_id',$company)->where('id',$item)->where('is_active',1)->first();
        if(!$row||($row->inventory_quantity_version??'LEGACY_KG')!=='LEGACY_KG'||($row->item_type??'STOCK')!=='STOCK'||!(bool)($row->track_inventory??true))throw new \RuntimeException('UOM_RECIPE_LEGACY_ITEM_SCOPE');
        if(!config('inventory_value.enabled')||!DB::getSchemaBuilder()->hasTable('inventory_lot_value_balances'))throw new \RuntimeException('UOM_RECIPE_LEGACY_VALUE_QUALIFICATION_REQUIRED');
    }
    public static function kilograms(string $quantity,string $factor):string
    {
        $value=DecimalQuantity::convert($quantity,$factor,true);$kg=bcadd($value,'0',3);
        if(bccomp($value,$kg,6)!==0)throw new \RuntimeException('UOM_RECIPE_LEGACY_KG_PRECISION');return $kg;
    }
    public static function consume(int $company,int $branch,int $item,string $quantity,int $operation,int $user,string $date):array
    {
        self::ready($company,$item);$need=$quantity;$total='0.000';$sources=[];
        $lots=DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->where('item_id',$item)->where('uom_version','LEGACY_KG')->where('lot_status','OPEN')->where('qty_remaining_kg','>',0)->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
        foreach($lots as $lot){if(bccomp($need,'0',3)===0)break;$take=bccomp($need,$lot->qty_remaining_kg,3)>0?$lot->qty_remaining_kg:$need;
            $value=InventoryLotValue::issue($lot,$take);$remaining=bcsub($lot->qty_remaining_kg,$take,3);
            DB::table('inventory_lots')->where('company_id',$company)->where('id',$lot->id)->update(['qty_remaining_kg'=>$remaining,'lot_status'=>bccomp($remaining,'0',3)===0?'CLOSED':'OPEN','updated_at'=>now()]);
            self::movement($lot,'OUT',$take,$value,'INVENTORY_OPERATION',$operation,$user,$date);
            $sources[]=['inventory_lot_id'=>(int)$lot->id,'item_id'=>$item,'uom_version'=>'LEGACY_KG','qty_kg'=>$take,'total_cost'=>$value];$need=bcsub($need,$take,3);$total=bcadd($total,$value,3);
        }
        if(bccomp($need,'0',3)!==0)throw new \RuntimeException('UOM_RECIPE_LEGACY_STOCK_SHORTAGE');
        return ['total_cost'=>$total,'allocations'=>$sources];
    }
    public static function receive(array $data):int
    {
        self::ready($data['company_id'],$data['item_id']);$quantity=$data['qty_kg'];$value=$data['base_cost'];
        // Preserve the existing receipt writer while rejecting a lossy float boundary.
        foreach([$quantity,$value] as $exact)if(bccomp(number_format((float)$exact,3,'.',''),$exact,3)!==0)throw new \RuntimeException('UOM_RECIPE_LEGACY_RECEIPT_PRECISION');
        $id=app(InventoryLotService::class)->createInboundLot($data);$lot=DB::table('inventory_lots')->where('company_id',$data['company_id'])->where('id',$id)->first();
        if(!$lot||bccomp($quantity,$lot->qty_received_kg,3)||bccomp($value,$lot->total_cost,3))throw new \RuntimeException('UOM_RECIPE_LEGACY_RECEIPT_PRECISION');return $id;
    }
    public static function reverse(object $lot,string $direction,string $quantity,string $value):void
    {
        self::ready($lot->company_id,$lot->item_id);
        if($direction==='OUT'){InventoryLotValue::removeExact($lot,$quantity,$value);$remaining=bcsub($lot->qty_remaining_kg,$quantity,3);}
        else{InventoryLotValue::restore($lot,$quantity,$value);$remaining=bcadd($lot->qty_remaining_kg,$quantity,3);}
        if(bccomp($remaining,'0',3)<0||bccomp($remaining,$lot->qty_received_kg,3)>0)throw new \RuntimeException('UOM_RECIPE_LEGACY_RESTORE_SCOPE');
        DB::table('inventory_lots')->where('company_id',$lot->company_id)->where('id',$lot->id)->update(['qty_remaining_kg'=>$remaining,'lot_status'=>bccomp($remaining,'0',3)===0?'CLOSED':'OPEN','updated_at'=>now()]);
    }
    public static function movement(object $lot,string $direction,string $quantity,string $value,string $source,int $id,int $user,string $date):void
    {
        DB::table('inventory_lot_movements')->insert(['company_id'=>$lot->company_id,'branch_id'=>$lot->branch_id,'item_id'=>$lot->item_id,'inventory_lot_id'=>$lot->id,'movement_type'=>$direction,'source_type'=>$source,'source_id'=>$id,'movement_at'=>$date,'qty_kg'=>$quantity,'unit_cost_per_kg'=>$lot->unit_cost_per_kg,'total_cost'=>$value,'created_by'=>$user,'created_at'=>now(),'updated_at'=>now()]);
    }
    public static function stock(object $lot,string $direction,string $quantity,string $value,string $source,int $id,int $user,string $date):void
    {
        DB::table('stock_movements')->insert(['company_id'=>$lot->company_id,'branch_id'=>$lot->branch_id,'item_id'=>$lot->item_id,'inventory_lot_id'=>$lot->id,'movement_type'=>$direction,'source_type'=>$source,'source_id'=>$id,'movement_date'=>$date,'qty'=>$quantity,'qty_kg'=>$quantity,'unit_cost'=>$lot->unit_cost_per_kg,'unit_cost_per_kg'=>$lot->unit_cost_per_kg,'total_cost'=>$value,'created_by'=>$user,'created_at'=>now(),'updated_at'=>now()]);
    }
}
