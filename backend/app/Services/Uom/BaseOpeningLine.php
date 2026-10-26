<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

final class BaseOpeningLine
{
    public static function save(int $company,int $batch,int $branch,object $item,array $input):void
    {
        if(!DB::getSchemaBuilder()->hasColumn('opening_inventory_lines','uom_version'))throw new \RuntimeException('UOM_OPENING_SCHEMA_REQUIRED');
        $cost=(string)($input['total_cost']??'');
        if(!preg_match('/^\d{1,15}(?:\.\d{1,3})?$/D',$cost))throw new \RuntimeException('UOM_OPENING_MONEY_PRECISION');
        $legacyMoney=(string)round((float)$cost,3);
        if(!preg_match('/^\d+(?:\.\d+)?$/D',$legacyMoney)||bccomp($legacyMoney,$cost,3)!==0)throw new \RuntimeException('UOM_OPENING_GL_PRECISION');
        $prepared=OperationalDocument::prepare($company,$item,array_replace($input,['unit_price'=>'0']),false,true);
        $columns=$prepared['columns'];unset($columns['uom_price_snapshot']);
        DB::table('opening_inventory_lines')->insert(['company_id'=>$company,'batch_id'=>$batch,'branch_id'=>$branch,'item_id'=>$item->id,
            'qty_kg'=>0,'unit_cost_per_kg'=>0,'total_cost'=>$cost,'quantity'=>$prepared['quantity'],'unit_code'=>$prepared['unit_code'],...$columns,
            'lot_number'=>$input['lot_number']??null,'notes'=>$input['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
    }
    public static function receive(int $company,object $batch,object $line,int $user,int $journal):void
    {
        $saved=clone $line;$saved->uom_price_snapshot='0';
        $item=DB::table('items')->where('company_id',$company)->where('id',$line->item_id)->first();
        if(!$item)throw new \RuntimeException('UOM_OPENING_ITEM_SCOPE');
        OperationalDocument::prepareSnapshot($company,$item,$saved);
        $lot=app(\App\Services\InventoryLotService::class)->createInboundLot(['company_id'=>$company,'branch_id'=>$line->branch_id,'item_id'=>$line->item_id,
            ...OperationalDocument::columns($line,$line->base_quantity),'base_cost'=>$line->total_cost,'source_type'=>'OPENING_BALANCE','source_id'=>$batch->id,
            'received_at'=>$batch->opening_date.' 00:00:00','created_by'=>$user,...($line->lot_number?['lot_number'=>$line->lot_number]:[])]);
        DB::table('inventory_lots')->where('company_id',$company)->where('id',$lot)->update(['opening_balance_batch_id'=>$batch->id,'updated_at'=>now()]);
        DB::table('opening_inventory_lines')->where('company_id',$company)->where('id',$line->id)->update(['inventory_lot_id'=>$lot,'updated_at'=>now()]);
        BaseInventory::stock($line,$company,(int)$line->branch_id,'IN',$line->base_quantity,$line->total_cost,'OPENING_BALANCE',(int)$batch->id,$user,$batch->opening_date,$lot);
        DB::table('stock_movements')->where('company_id',$company)->where('inventory_lot_id',$lot)->where('source_type','OPENING_BALANCE')->update(['journal_entry_id'=>$journal]);
    }
}
