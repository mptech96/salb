<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

/** Internal definition/snapshot preparation only; no public routes, stock writes or posting. */
final class ItemUnitService
{
    private function item(int $company,int $item):object
    {
        $row=DB::table('items')->where('company_id',$company)->where('id',$item)->lockForUpdate()->first();
        if(!$row)throw new \RuntimeException('UOM_ITEM_SCOPE');return $row;
    }
    public function registerNew(int $company,int $item,int $base):void
    {
        UomAuthorization::actor($company,true);
        DB::transaction(function()use($company,$item,$base){
            $this->item($company,$item);
            foreach(['inventory_lots','inventory_lot_movements','stock_movements','purchase_invoice_lines','sales_invoice_lines','shipment_items','opening_inventory_lines','inventory_operation_lines','commercial_return_lines','sales_quotation_lines','purchase_order_lines'] as $table)if(DB::table($table)->where('company_id',$company)->where('item_id',$item)->lockForUpdate()->first())throw new \RuntimeException('UOM_NEW_ITEM_REQUIRED');
            if(DB::table('uom_item_modes')->where('company_id',$company)->where('item_id',$item)->exists())throw new \RuntimeException('UOM_MODE_IMMUTABLE');
            $unit=DB::table('uom_units')->where('id',$base)->first();if(!$unit||$unit->kind!=='STANDARD')throw new \RuntimeException('UOM_STANDARD_BASE_REQUIRED');
            DB::table('items')->where('id',$item)->update(['base_uom_id'=>$base,'base_unit_code'=>$unit->code,'inventory_quantity_version'=>'BASE_V1']);
            DB::table('uom_item_modes')->insert(['company_id'=>$company,'item_id'=>$item,'mode'=>'BASE_ONLY']);
        });
    }
    public function reviseMapping(int $company,int $item,int $unit,string $factor):int
    {
        UomAuthorization::actor($company,true);
        $factor=DecimalQuantity::decimal($factor);
        return DB::transaction(function()use($company,$item,$unit,$factor){
            $i=$this->item($company,$item);$mode=DB::table('uom_item_modes')->where('company_id',$company)->where('item_id',$item)->value('mode');if($mode!=='BASE_ONLY')throw new \RuntimeException('UOM_BASE_ONLY_REQUIRED');
            $u=DB::table('uom_units')->where('id',$unit)->first();$b=DB::table('uom_units')->where('id',$i->base_uom_id)->first();
            if(!$u||!$b||$u->dimension!==$b->dimension||($u->kind==='STANDARD'&&bccomp(bcmul($factor,(string)$b->standard_factor,12),(string)$u->standard_factor,12)!==0))throw new \RuntimeException('UOM_DIMENSION_OR_FACTOR');
            $rev=(int)DB::table('item_uom_mappings')->where('company_id',$company)->where('item_id',$item)->where('unit_id',$unit)->max('revision')+1;
            $id=DB::table('item_uom_mappings')->insertGetId(['company_id'=>$company,'item_id'=>$item,'unit_id'=>$unit,'base_unit_id'=>$i->base_uom_id,'revision'=>$rev,'factor_to_base'=>$factor]);
            DB::table('item_uom_heads')->updateOrInsert(['company_id'=>$company,'item_id'=>$item,'unit_id'=>$unit],['revision'=>$rev]);return $id;
        });
    }
    public function revisePrice(int $company,int $item,int $mapping,string $currency,string $price,?string $salePrice=null,bool $currentOnly=false):int
    {
        UomAuthorization::actor($company,true);
        $price=DecimalQuantity::decimal($price,18,true);
        $salePrice=DecimalQuantity::decimal($salePrice??$price,18,true);
        return DB::transaction(function()use($company,$item,$mapping,$currency,$price,$salePrice,$currentOnly){
            $this->item($company,$item);if(!DB::table('item_uom_mappings')->where('company_id',$company)->where('item_id',$item)->where('id',$mapping)->exists())throw new \RuntimeException('UOM_MAPPING_SCOPE');
            if($currentOnly){
                $m=DB::table('item_uom_mappings')->where('company_id',$company)->where('item_id',$item)->where('id',$mapping)->first();
                if((int)DB::table('item_uom_heads')->where('company_id',$company)->where('item_id',$item)->where('unit_id',$m->unit_id)->value('revision')!==(int)$m->revision)throw new \RuntimeException('UOM_STALE_MAPPING');
            }
            $rev=(int)DB::table('item_uom_prices')->where('company_id',$company)->where('mapping_id',$mapping)->where('currency_code',$currency)->max('revision')+1;
            $id=DB::table('item_uom_prices')->insertGetId(['company_id'=>$company,'mapping_id'=>$mapping,'currency_code'=>$currency,'revision'=>$rev,'purchase_price'=>$price,'sale_price'=>$salePrice]);
            DB::table('item_uom_price_heads')->updateOrInsert(['company_id'=>$company,'mapping_id'=>$mapping,'currency_code'=>$currency],['revision'=>$rev]);return $id;
        });
    }
    public function snapshot(int $company,int $item,int $mapping,int $price,string $quantity,string $side='PURCHASE'):array
    {
        UomAuthorization::actor($company);
        if(!in_array($side,['PURCHASE','SALE'],true))throw new \RuntimeException('UOM_PRICE_SIDE');
        return DB::transaction(function()use($company,$item,$mapping,$price,$quantity,$side){
            $this->item($company,$item);$m=DB::table('item_uom_mappings')->where('company_id',$company)->where('item_id',$item)->where('id',$mapping)->first();
            $p=DB::table('item_uom_prices')->where('company_id',$company)->where('mapping_id',$mapping)->where('id',$price)->first();if(!$m||!$p)throw new \RuntimeException('UOM_SNAPSHOT_SCOPE');
            $b=DB::table('uom_units')->where('id',$m->base_unit_id)->first();$u=DB::table('uom_units')->where('id',$m->unit_id)->first();$base=DecimalQuantity::convert($quantity,(string)$m->factor_to_base,(bool)$b->allow_fractional);
            if(!($side==='SALE'?$m->sale_allowed:$m->purchase_allowed))throw new \RuntimeException('UOM_UNIT_NOT_ALLOWED');
            $amount=$side==='SALE'?$p->sale_price:$p->purchase_price;if($amount===null)throw new \RuntimeException('UOM_PRICE_UNAVAILABLE');
            return ['version'=>'BASE_V1','mapping_id'=>$mapping,'mapping_revision'=>(int)$m->revision,'price_id'=>$price,'price_revision'=>(int)$p->revision,'quantity'=>DecimalQuantity::decimal($quantity),'unit'=>$u->code,'factor'=>(string)$m->factor_to_base,'base_quantity'=>$base,'base_unit_id'=>(int)$b->id,'unit_price'=>(string)$amount,'price_per_base'=>bcdiv((string)$amount,(string)$m->factor_to_base,6),'currency'=>$p->currency_code,'side'=>$side];
        }); // Value DTO, not a write to a draft/posted invoice.
    }
}
