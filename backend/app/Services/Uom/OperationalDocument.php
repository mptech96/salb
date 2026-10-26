<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

/** Financial entry-point adapter. Does not grant permissions or trust client base quantities. */
final class OperationalDocument
{
    public static function ready(): void
    {
        if(!config('uom.operational_enabled') || !DB::getSchemaBuilder()->hasTable('uom_operational_schema') || !DB::table('uom_operational_schema')->where('version','BASE_DOCUMENT_V1')->exists()) throw new \RuntimeException('UOM_OPERATIONAL_SCHEMA_REQUIRED');
        if(DB::transactionLevel()<1 || !DB::connection()->getPdo()->inTransaction()) throw new \RuntimeException('UOM_ROOT_REQUIRED');
    }

    public static function isBase(object $row): bool { return ($row->uom_version??'LEGACY_KG')==='BASE_V1'; }

    public static function enrichItems(int $company,iterable $items,bool $sale,bool $inventory=false):void
    {
        if(!DB::getSchemaBuilder()->hasTable('uom_operational_schema'))return;
        $versions=DB::table('items')->where('company_id',$company)->pluck('inventory_quantity_version','id');
        $options=DB::table('item_uom_mappings as m')->join('item_uom_heads as h',function($join){$join->on('h.company_id','=','m.company_id')->on('h.item_id','=','m.item_id')->on('h.unit_id','=','m.unit_id')->on('h.revision','=','m.revision');})
            ->join('uom_units as u','u.id','=','m.unit_id')->where('m.company_id',$company)->when(!$inventory,fn($q)=>$q->where($sale?'m.sale_allowed':'m.purchase_allowed',1))->orderBy('u.code')->get(['m.item_id','m.id','m.factor_to_base','u.code','u.name_ar']);
        foreach($items as $item){$item->inventory_quantity_version=$versions[$item->id]??'LEGACY_KG';$item->uom_units=$options->where('item_id',$item->id)->values()->all();}
    }

    public static function prepare(int $company, object $item, array $input, bool $sale, bool $inventory=false): array
    {
        self::ready();
        $query=DB::table('item_uom_mappings as m')->join('item_uom_heads as h',function($join){$join->on('h.company_id','=','m.company_id')->on('h.item_id','=','m.item_id')->on('h.unit_id','=','m.unit_id')->on('h.revision','=','m.revision');})
            ->join('uom_units as u','u.id','=','m.unit_id')->where('m.company_id',$company)->where('m.item_id',$item->id);
        if(!empty($input['uom_mapping_id'])) $query->where('m.id',(int)$input['uom_mapping_id']);
        else $query->where('u.code',(string)($input['unit_code']??$item->base_unit_code));
        $mapping=$query->select('m.*','u.code','u.allow_fractional')->first();
        $base=DB::table('uom_units')->where('id',$item->base_uom_id)->first();
        if(!$mapping||!$base||(!$inventory&&!($sale?$mapping->sale_allowed:$mapping->purchase_allowed)))throw new \RuntimeException('UOM_MAPPING_SCOPE_OR_SIDE');
        // Never accept a dual weight/base interpretation or client-supplied conversion result.
        if(isset($input['qty_kg'])||isset($input['base_quantity'])||isset($input['unit_factor_to_base']))throw new \RuntimeException('UOM_SERVER_QUANTITY_REQUIRED');
        $quantity=$input['quantity']??null;$price=$input['unit_price']??null;
        if(!is_string($quantity)||!is_string($price))throw new \RuntimeException('UOM_DECIMAL_STRINGS_REQUIRED');
        $quantity=DecimalQuantity::decimal($quantity);$price=DecimalQuantity::decimal($price,18,true);
        if(!$mapping->allow_fractional&&bccomp($quantity,bcadd($quantity,'0',0),6)!==0)throw new \RuntimeException('UOM_INDIVISIBLE_ENTERED_UNIT');
        $baseQuantity=DecimalQuantity::convert($quantity,(string)$mapping->factor_to_base,(bool)$base->allow_fractional);
        $gross=bcadd(bcmul($quantity,$price,12),'0.0005',3);
        return ['quantity'=>$quantity,'unit_code'=>$mapping->code,'factor'=>(string)$mapping->factor_to_base,'price'=>$price,'gross'=>$gross,
            'columns'=>['uom_version'=>'BASE_V1','uom_mapping_id'=>$mapping->id,'base_uom_id_snapshot'=>$base->id,'base_quantity'=>$baseQuantity,'uom_factor_snapshot'=>(string)$mapping->factor_to_base,'uom_price_snapshot'=>$price]];
    }

    public static function columns(object $row, string $quantity): array
    {
        return ['uom_version'=>'BASE_V1','uom_mapping_id'=>$row->uom_mapping_id,'base_uom_id_snapshot'=>$row->base_uom_id_snapshot,'base_quantity'=>$quantity];
    }

    public static function prepareSnapshot(int $company,object $item,object $saved):array
    {
        self::ready();
        $mapping=DB::table('item_uom_mappings as m')->join('uom_units as u','u.id','=','m.unit_id')->where('m.company_id',$company)->where('m.item_id',$item->id)->where('m.id',$saved->uom_mapping_id)->select('m.*','u.code','u.allow_fractional')->first();
        $base=DB::table('uom_units')->where('id',$item->base_uom_id)->first();
        if(!$mapping||!$base||(int)$mapping->base_unit_id!==(int)$saved->base_uom_id_snapshot||(int)$base->id!==(int)$saved->base_uom_id_snapshot||$mapping->code!==$saved->unit_code||bccomp($mapping->factor_to_base,$saved->uom_factor_snapshot,6)!==0)throw new \RuntimeException('UOM_CAPTURE_MAPPING_MISMATCH');
        $quantity=DecimalQuantity::decimal($saved->quantity);$price=DecimalQuantity::decimal($saved->uom_price_snapshot,18,true);
        if(!$mapping->allow_fractional&&bccomp($quantity,bcadd($quantity,'0',0),6)!==0)throw new \RuntimeException('UOM_INDIVISIBLE_ENTERED_UNIT');
        $converted=DecimalQuantity::convert($quantity,$saved->uom_factor_snapshot,(bool)$base->allow_fractional);
        if(bccomp($converted,$saved->base_quantity,6)!==0)throw new \RuntimeException('UOM_CAPTURE_QUANTITY_MISMATCH');
        return ['quantity'=>$quantity,'unit_code'=>$saved->unit_code,'factor'=>$saved->uom_factor_snapshot,'price'=>$price,'gross'=>bcadd(bcmul($quantity,$price,12),'0.0005',3),
            'columns'=>self::columns($saved,$converted)+['uom_factor_snapshot'=>$saved->uom_factor_snapshot,'uom_price_snapshot'=>$price]];
    }
}
