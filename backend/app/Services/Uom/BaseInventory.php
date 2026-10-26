<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

/** Versioned base quantities; identical received_at/id FIFO ordering, no kilogram surrogate. */
final class BaseInventory
{
    private static function money(string $amount): string
    {
        if(!preg_match('/^\d+(?:\.\d{1,3})?$/D',$amount))throw new \RuntimeException('UOM_MONEY_PRECISION');
        return bcadd($amount,'0',3);
    }

    public static function receive(array $data,string $number): int
    {
        OperationalDocument::ready();
        $quantity=DecimalQuantity::decimal((string)$data['base_quantity'],18);
        $value=self::money((string)$data['base_cost']);
        $unit=bcadd(bcdiv($value,$quantity,18),'0.0000005',6);
        $id=DB::table('inventory_lots')->insertGetId([
            'company_id'=>$data['company_id'],'branch_id'=>$data['branch_id'],'item_id'=>$data['item_id'],
            'car_id'=>$data['car_id']??null,'shipment_id'=>$data['shipment_id']??null,'shipment_item_id'=>$data['shipment_item_id']??null,
            'lot_number'=>$number,'source_type'=>$data['source_type'],'source_id'=>$data['source_id'],
            'purchase_invoice_id'=>$data['purchase_invoice_id']??null,'purchase_invoice_line_id'=>$data['purchase_invoice_line_id']??null,
            'parent_lot_id'=>$data['parent_lot_id']??null,'origin_lot_id'=>$data['origin_lot_id']??null,
            'received_at'=>$data['received_at'],'qty_received_kg'=>0,'qty_remaining_kg'=>0,'qty_sold_kg'=>0,
            'base_cost'=>$value,'allocated_cost'=>0,'total_cost'=>$value,'unit_cost_per_kg'=>0,'lot_status'=>'OPEN',
            'uom_version'=>'BASE_V1','uom_mapping_id'=>$data['uom_mapping_id'],'base_uom_id_snapshot'=>$data['base_uom_id_snapshot'],
            'base_quantity'=>$quantity,'received_base_qty'=>$quantity,'remaining_base_qty'=>$quantity,'sold_base_qty'=>0,
            'unit_cost_per_base'=>$unit,'uom_remaining_value'=>$value,'created_by'=>$data['created_by'],
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $lot=DB::table('inventory_lots')->where('id',$id)->first();
        self::movement($lot,'IN',$quantity,$value,$data['source_type'],(int)$data['source_id'],(int)$data['created_by'],$data['received_at']);
        return $id;
    }

    public static function consume(int $company,int $branch,int $item,string $quantity,string $source,int $id,int $user): array
    {
        OperationalDocument::ready();$quantity=DecimalQuantity::decimal($quantity,18);
        $lots=DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->where('item_id',$item)
            ->where('uom_version','BASE_V1')->where('lot_status','OPEN')->where('remaining_base_qty','>',0)
            ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
        $available='0';foreach($lots as $lot)$available=bcadd($available,$lot->remaining_base_qty,6);
        if(bccomp($available,$quantity,6)<0)throw new \RuntimeException('UOM_STOCK_SHORTAGE');
        $need=$quantity;$total='0';$allocations=[];
        foreach($lots as $lot){
            if(bccomp($need,'0',6)===0)break;
            $take=bccomp($need,$lot->remaining_base_qty,6)>0?$lot->remaining_base_qty:$need;
            $value=self::remove($lot,$take,null,$source==='SALE');
            self::movement($lot,'OUT',$take,$value,$source,$id,$user,date('Y-m-d'));
            $allocations[]=['inventory_lot_id'=>$lot->id,'shipment_id'=>$lot->shipment_id,'shipment_item_id'=>$lot->shipment_item_id,'qty_kg'=>0,'unit_cost_per_kg'=>0,
                ...OperationalDocument::columns($lot,$take),'uom_unit_cost'=>$lot->unit_cost_per_base,'total_cost'=>$value];
            $total=bcadd($total,$value,3);$need=bcsub($need,$take,6);
        }
        return ['total_cost'=>$total,'allocations'=>$allocations];
    }

    public static function remove(object $lot,string $quantity,?string $exact=null,bool $sale=false): string
    {
        OperationalDocument::ready();$quantity=DecimalQuantity::decimal($quantity,18);
        if(!OperationalDocument::isBase($lot)||bccomp($quantity,$lot->remaining_base_qty,6)>0)throw new \RuntimeException('UOM_LOT_SHORTAGE');
        $value=$exact!==null?self::money($exact):(bccomp($quantity,$lot->remaining_base_qty,6)===0?$lot->uom_remaining_value:bcadd(bcmul($quantity,$lot->unit_cost_per_base,12),'0.0005',3));
        $remaining=bcsub($lot->remaining_base_qty,$quantity,6);$balance=bcsub($lot->uom_remaining_value,$value,3);
        if(bccomp($balance,'0',3)<0||(bccomp($remaining,'0',6)===0&&bccomp($balance,'0',3)!==0))throw new \RuntimeException('UOM_LOT_VALUE_REVIEW');
        DB::table('inventory_lots')->where('company_id',$lot->company_id)->where('id',$lot->id)->update([
            'remaining_base_qty'=>$remaining,'uom_remaining_value'=>$balance,'sold_base_qty'=>$sale?bcadd($lot->sold_base_qty,$quantity,6):$lot->sold_base_qty,
            'lot_status'=>bccomp($remaining,'0',6)===0?'CLOSED':'OPEN','updated_at'=>now(),
        ]);
        return $value;
    }

    public static function restore(object $lot,string $quantity,string $value,bool $sale=true): void
    {
        OperationalDocument::ready();$quantity=DecimalQuantity::decimal($quantity,18);$value=self::money($value);
        $remaining=bcadd($lot->remaining_base_qty,$quantity,6);$balance=bcadd($lot->uom_remaining_value,$value,3);
        if(bccomp($remaining,$lot->received_base_qty,6)>0||bccomp($balance,$lot->total_cost,3)>0||($sale&&bccomp($quantity,$lot->sold_base_qty,6)>0))throw new \RuntimeException('UOM_RESTORE_EXCEEDS_SOURCE');
        DB::table('inventory_lots')->where('company_id',$lot->company_id)->where('id',$lot->id)->update([
            'remaining_base_qty'=>$remaining,'uom_remaining_value'=>$balance,'sold_base_qty'=>$sale?bcsub($lot->sold_base_qty,$quantity,6):$lot->sold_base_qty,'lot_status'=>'OPEN','updated_at'=>now(),
        ]);
    }

    public static function movement(object $lot,string $direction,string $quantity,string $value,string $source,int $id,int $user,string $date): void
    {
        $columns=OperationalDocument::columns($lot,$quantity);
        DB::table('inventory_lot_movements')->insert([
            'company_id'=>$lot->company_id,'branch_id'=>$lot->branch_id,'item_id'=>$lot->item_id,'inventory_lot_id'=>$lot->id,
            'movement_type'=>$direction,'source_type'=>$source,'source_id'=>$id,'movement_at'=>$date,'qty_kg'=>0,'unit_cost_per_kg'=>0,'total_cost'=>$value,
            ...$columns,'created_by'=>$user,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public static function stock(object $line,int $company,int $branch,string $direction,string $quantity,string $value,string $source,int $id,int $user,string $date,?int $lot=null):void
    {
        DB::table('stock_movements')->insert([
            'company_id'=>$company,'branch_id'=>$branch,'item_id'=>$line->item_id,'inventory_lot_id'=>$lot,
            'movement_type'=>$direction,'source_type'=>$source,'source_id'=>$id,'movement_date'=>$date,'qty'=>0,'qty_kg'=>0,'unit_cost'=>0,'unit_cost_per_kg'=>0,'total_cost'=>$value,
            ...OperationalDocument::columns($line,$quantity),'created_by'=>$user,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
