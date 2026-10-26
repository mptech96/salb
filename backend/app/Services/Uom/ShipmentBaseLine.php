<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

final class ShipmentBaseLine
{
    public static function invoiceSource(int $company,int $branch,int $invoice,object $line,string $type):?object
    {
        if(!$line->shipment_id&&!$line->shipment_item_id)return null;
        if(!$line->shipment_id||!$line->shipment_item_id||!DB::table('invoice_shipment_links')->where('company_id',$company)->where('branch_id',$branch)->where('invoice_type',$type)->where('invoice_id',$invoice)->where('shipment_id',$line->shipment_id)->exists())throw new \RuntimeException('UOM_SHIPMENT_LINK_SCOPE');
        $head=DB::table('shipments')->where('company_id',$company)->where('branch_id',$branch)->where('id',$line->shipment_id)->where('shipment_type',$type)->where('commercial_status','READY')->first();
        $source=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$line->shipment_id)->where('id',$line->shipment_item_id)->where('item_id',$line->item_id)->lockForUpdate()->first();
        if(!$head||!$source||!OperationalDocument::isBase($source))throw new \RuntimeException('UOM_SHIPMENT_LINK_SCOPE');
        foreach(['quantity','unit_code','base_quantity','uom_factor_snapshot','uom_price_snapshot','uom_mapping_id','base_uom_id_snapshot'] as $field)if((string)$source->$field!==(string)$line->$field)throw new \RuntimeException('UOM_SHIPMENT_SNAPSHOT_CHANGED');
        return $source;
    }
    public static function values(int $company,int $shipment,object $item,array $input,string $type,string $date,string $currency,float $rate,int $position):array
    {
        if(!DB::getSchemaBuilder()->hasColumn('shipment_items','uom_version'))throw new \RuntimeException('UOM_SHIPMENT_SCHEMA_REQUIRED');
        if(!in_array($type,['PURCHASE','SALE','TRANSFER','INTERNAL'],true))throw new \RuntimeException('UOM_SHIPMENT_FLOW_ADAPTER_REQUIRED');
        $snapshot=OperationalDocument::prepare($company,$item,$input,$type==='SALE',in_array($type,['TRANSFER','INTERNAL'],true));
        $weight=$input['actual_weight_kg']??null;
        if($weight!==null){if(!is_string($weight)||!preg_match('/^\d{1,15}(?:\.\d{1,3})?$/D',$weight))throw new \RuntimeException('UOM_SHIPMENT_PHYSICAL_PRECISION');$weight=bcadd($weight,'0',3);}
        foreach(['gross_qty_kg','deduction_qty_kg','accepted_qty_kg','weighed_qty_kg'] as $key)if(isset($input[$key]))throw new \RuntimeException('UOM_SHIPMENT_PHYSICAL_FIELD: استخدم الوزن الفعلي المستقل دون تغيير كمية المخزون.');
        $discount=(float)($input['discount_amount']??0);if($discount<0||$discount>(float)$snapshot['gross'])throw new \RuntimeException('UOM_SHIPMENT_DISCOUNT');
        $tax=app(\App\Services\TaxEngineService::class)->line($company,(float)$snapshot['gross'],$discount,isset($input['tax_code_id'])?(int)$input['tax_code_id']:null,$date,$type==='SALE'?'SALES':'PURCHASE',isset($input['vat_percent'])?(float)$input['vat_percent']:null);
        $base=round((float)$tax['total_before_vat']*$rate,3);
        return ['company_id'=>$company,'shipment_id'=>$shipment,'item_id'=>$item->id,...$snapshot['columns'],'quantity'=>$snapshot['quantity'],'unit_code'=>$snapshot['unit_code'],
            'remaining_base_qty'=>$snapshot['columns']['base_quantity'],'sold_base_qty'=>'0','actual_weight_kg'=>$weight,
            'qty_kg'=>0,'remaining_qty_kg'=>0,'sold_qty_kg'=>0,'inventory_qty_kg'=>0,'price_unit'=>'UNIT','unit_price'=>$snapshot['price'],'unit_price_per_kg'=>0,
            'discount_amount'=>$discount,'vat_percent'=>$tax['tax_rate_snapshot'],'vat_amount'=>$tax['vat_amount'],'total_before_vat'=>$tax['total_before_vat'],'total_after_vat'=>$tax['total_after_vat'],'line_total'=>$tax['line_total'],
            'tax_code_id'=>$tax['tax_code_id'],'tax_code_snapshot'=>$tax['tax_code_snapshot'],'tax_name_snapshot'=>$tax['tax_name_snapshot'],'tax_rate_snapshot'=>$tax['tax_rate_snapshot'],
            'currency_code'=>$currency,'exchange_rate'=>$rate,'base_total_before_vat'=>$base,'base_vat_amount'=>round((float)$tax['vat_amount']*$rate,3),'base_total_after_vat'=>round((float)$tax['total_after_vat']*$rate,3),
            'base_cost'=>$base,'cost_share_percent'=>$input['cost_share_percent']??null,'sorting_order'=>$position,'notes'=>$input['notes']??null,'created_at'=>now(),'updated_at'=>now()];
    }
}
