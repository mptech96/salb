<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

final class BaseReturns
{
    public static function snapshot(object $source,array $input):array
    {
        OperationalDocument::ready();
        if(isset($input['qty_kg'])||isset($input['unit_code'])&&$input['unit_code']!==$source->unit_code)throw new \RuntimeException('UOM_RETURN_ORIGINAL_UNIT_REQUIRED');
        if(!is_string($input['quantity']??null))throw new \RuntimeException('UOM_DECIMAL_STRINGS_REQUIRED');
        $quantity=DecimalQuantity::decimal($input['quantity']);
        $mapping=DB::table('item_uom_mappings as m')->join('uom_units as u','u.id','=','m.unit_id')
            ->where('m.company_id',$source->company_id)->where('m.item_id',$source->item_id)->where('m.id',$source->uom_mapping_id)
            ->where('m.base_unit_id',$source->base_uom_id_snapshot)->first(['m.factor_to_base','u.code','u.allow_fractional']);
        if(!$mapping||$mapping->code!==$source->unit_code||bccomp($mapping->factor_to_base,$source->uom_factor_snapshot,6)!==0)throw new \RuntimeException('UOM_RETURN_SNAPSHOT_MISMATCH');
        if(!$mapping->allow_fractional&&bccomp($quantity,bcadd($quantity,'0',0),6)!==0)throw new \RuntimeException('UOM_INDIVISIBLE_ENTERED_UNIT');
        $unit=DB::table('uom_units')->where('id',$source->base_uom_id_snapshot)->first();
        if(!$unit)throw new \RuntimeException('UOM_RETURN_SOURCE_UNIT_MISSING');
        return [...OperationalDocument::columns($source,DecimalQuantity::convert($quantity,$source->uom_factor_snapshot,(bool)$unit->allow_fractional)),
            'uom_factor_snapshot'=>$source->uom_factor_snapshot,'uom_price_snapshot'=>$source->uom_price_snapshot];
    }

    /** Original entered-unit quantity and captured money, never today's mapping or prices. */
    public static function financial(int $company,string $type,object $source,string $quantity):array
    {
        $quantity=DecimalQuantity::decimal($quantity);
        $previous=DB::table('commercial_return_lines as l')->join('commercial_returns as r','r.id','=','l.return_id')
            ->where('l.company_id',$company)->where('r.company_id',$company)->where('r.return_type',$type)
            ->where('l.source_invoice_line_id',$source->id)->whereIn('r.document_status',['DRAFT','POSTED'])
            ->lockForUpdate()->get(['l.quantity','l.total_before_vat','l.vat_amount','l.base_total_before_vat','l.base_vat_amount']);
        $used='0';foreach($previous as $row)$used=bcadd($used,$row->quantity,6);
        $available=bcsub($source->quantity,$used,6);
        if(bccomp($quantity,$available,6)>0)throw new \RuntimeException('UOM_RETURN_EXCEEDS_SOURCE');
        $last=bccomp($quantity,$available,6)===0;$values=[];
        foreach(['total_before_vat','vat_amount','base_total_before_vat','base_vat_amount'] as $field){
            $spent='0';foreach($previous as $row)$spent=bcadd($spent,$row->{$field},3);
            $remaining=bcsub($source->{$field},$spent,3);
            $value=$last?$remaining:bcadd(bcdiv(bcmul($source->{$field},$quantity,18),$source->quantity,18),'0.0005',3);
            if(bccomp($value,'0',3)<0||bccomp($value,$remaining,3)>0)throw new \RuntimeException('UOM_RETURN_FINANCIAL_REVIEW');
            $values[$field]=$value;
        }
        $values['total_after_vat']=bcadd($values['total_before_vat'],$values['vat_amount'],3);
        $values['base_total_after_vat']=bcadd($values['base_total_before_vat'],$values['base_vat_amount'],3);
        return $values;
    }

    public static function post(int $company,object $document,object $line,int $user,bool $sale):float
    {
        OperationalDocument::ready();$need=$line->base_quantity;$total='0';
        $sources=$sale?DB::table('sales_line_lot_sources')->where('company_id',$company)->where('sales_invoice_line_id',$line->source_invoice_line_id)->orderBy('id')->lockForUpdate()->get():
            DB::table('inventory_lots')->where('company_id',$company)->where('purchase_invoice_line_id',$line->source_invoice_line_id)->where('uom_version','BASE_V1')->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
        foreach($sources as $source){
            if(bccomp($need,'0',6)===0)break;
            $lot=$sale?DB::table('inventory_lots')->where('company_id',$company)->where('id',$source->inventory_lot_id)->lockForUpdate()->first():$source;
            if(!$lot||!OperationalDocument::isBase($lot))throw new \RuntimeException('UOM_RETURN_LOT_SCOPE');
            $previousQuantity='0';$previousValue='0';
            if($sale){
                $previous=DB::table('commercial_return_lot_sources as s')->join('commercial_return_lines as l','l.id','=','s.return_line_id')->join('commercial_returns as r','r.id','=','l.return_id')
                    ->where('s.company_id',$company)->where('l.source_invoice_line_id',$line->source_invoice_line_id)->where('s.inventory_lot_id',$lot->id)->where('r.return_type','SALES_RETURN')->where(fn($query)=>$query->where('r.document_status','POSTED')->orWhere('r.id',$document->id))->lockForUpdate()->get(['s.base_quantity','s.total_cost']);
                foreach($previous as $old){$previousQuantity=bcadd($previousQuantity,$old->base_quantity,6);$previousValue=bcadd($previousValue,$old->total_cost,3);}
            }
            $available=$sale?bcsub($source->base_quantity,$previousQuantity,6):$lot->remaining_base_qty;
            if(bccomp($available,'0',6)<=0)continue;
            $take=bccomp($need,$available,6)>0?$available:$need;
            if($sale){
                $remainingValue=bcsub($source->total_cost,$previousValue,3);
                $value=bccomp($take,$available,6)===0?$remainingValue:bcadd(bcmul($take,$source->uom_unit_cost,12),'0.0005',3);
                if(bccomp($value,$remainingValue,3)>0)throw new \RuntimeException('UOM_RETURN_VALUE_REVIEW');
                BaseInventory::restore($lot,$take,$value);
            }else $value=BaseInventory::remove($lot,$take);
            DB::table('commercial_return_lot_sources')->insert([
                'company_id'=>$company,'return_line_id'=>$line->id,'inventory_lot_id'=>$lot->id,'qty_kg'=>0,'unit_cost_per_kg'=>0,'total_cost'=>$value,
                ...OperationalDocument::columns($lot,$take),'uom_unit_cost'=>$sale?$source->uom_unit_cost:$lot->unit_cost_per_base,'created_at'=>now(),'updated_at'=>now(),
            ]);
            self::event($lot,$sale?'IN':'OUT',$take,$value,$document,$user);
            $total=bcadd($total,$value,3);$need=bcsub($need,$take,6);
        }
        if(bccomp($need,'0',6)!==0)throw new \RuntimeException('UOM_RETURN_SOURCE_SHORTAGE');
        return (float)$total;
    }

    public static function reverse(object $lot,object $source,object $document,int $user,bool $sale):void
    {
        if($sale)BaseInventory::remove($lot,$source->base_quantity,$source->total_cost,true);
        else BaseInventory::restore($lot,$source->base_quantity,$source->total_cost,false);
        self::event($lot,$sale?'OUT':'IN',$source->base_quantity,$source->total_cost,(object)[...((array)$document),'return_type'=>$document->return_type.'_REVERSAL','return_date'=>date('Y-m-d')],$user);
    }

    private static function event(object $lot,string $direction,string $quantity,string $value,object $document,int $user):void
    {
        BaseInventory::movement($lot,$direction,$quantity,$value,$document->return_type,(int)$document->id,$user,$document->return_date);
        BaseInventory::stock($lot,(int)$lot->company_id,(int)$lot->branch_id,$direction,$quantity,$value,$document->return_type,(int)$document->id,$user,$document->return_date,(int)$lot->id);
    }
}
