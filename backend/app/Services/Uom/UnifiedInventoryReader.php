<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

/** Future application read component. No route binding/writes; callers provide trusted scopes. */
final class UnifiedInventoryReader
{
    private function assertSchema():void
    {
        if(!DB::getSchemaBuilder()->hasTable('uom_units'))throw new \RuntimeException('UOM_READ_SCHEMA_NOT_INSTALLED');
    }
    private static function rounded(string $n, int $scale): string
    {
        $negative=bccomp($n,'0',18)<0;
        $absolute=$negative?bcsub('0',$n,18):$n;
        $half='0.'.str_repeat('0',$scale).'5';
        $result=bcadd($absolute,$half,$scale);
        return $negative&&bccomp($result,'0',$scale)!==0?'-'.$result:$result;
    }

    /** Branch list MUST come from trusted authorization, never directly from request input. */
    public function read(int $company, array $allowedBranches, ?int $itemId=null): array
    {
        foreach($allowedBranches as $branch)UomAuthorization::actor($company,false,$branch);
        $this->assertSchema();
        if($company<=0||!$allowedBranches||array_filter($allowedBranches,fn($b)=>!is_int($b)||$b<=0))throw new \RuntimeException('Explicit trusted branch scope required');
        if(DB::table('branches')->where('company_id',$company)->whereIn('id',array_unique($allowedBranches))->count()!==count(array_unique($allowedBranches)))throw new \RuntimeException('Foreign branch scope rejected');
        return DB::transaction(function()use($company,$allowedBranches,$itemId){
            $stockOnly=DB::table('stock_movements')->where('company_id',$company)->whereIn('branch_id',$allowedBranches)->whereNull('inventory_lot_id');
            if($itemId!==null)$stockOnly->where('item_id',$itemId);
            if($stockOnly->where(function($q){$q->where('qty','<>',0)->orWhere('qty_kg','<>',0)->orWhere('total_cost','<>',0);})->exists())throw new \RuntimeException('UNSUPPORTED_STOCK_ONLY: explicit event/lot evidence required');
            foreach(['stock_movements','inventory_lot_movements'] as $table){
                $evidence=DB::table($table.' as e')->where('e.company_id',$company)->whereIn('e.branch_id',$allowedBranches)->whereNotNull('e.inventory_lot_id');
                if($itemId!==null)$evidence->where('e.item_id',$itemId);
                if($evidence->whereNotExists(function($q){$q->selectRaw('1')->from('inventory_lots as l')->whereColumn('l.id','e.inventory_lot_id')->whereColumn('l.company_id','e.company_id')->whereColumn('l.branch_id','e.branch_id')->whereColumn('l.item_id','e.item_id');})->exists())throw new \RuntimeException('Orphan/cross-scope movement evidence');
            }
            $query=DB::table('inventory_lots')->where('company_id',$company)->whereIn('branch_id',$allowedBranches);
            if(!DB::getSchemaBuilder()->hasColumn('inventory_lots','uom_version'))$query->selectRaw("inventory_lots.*, 'LEGACY_KG' as uom_version");
            if($itemId!==null)$query->where('item_id',$itemId);
            $lots=$query->orderBy('received_at')->orderBy('id')->get();
            $groups=[];$events=[];
            foreach($lots as $lot){
                $item=DB::table('items')->where('id',$lot->item_id)->where('company_id',$company)->first();
                if(!$item)throw new \RuntimeException('Item scope evidence missing');
                if($lot->uom_version==='LEGACY_KG'){
                    if($item->base_unit_code!=='KG')throw new \RuntimeException('Unproven legacy kilogram semantics');
                    $unit=DB::table('uom_units')->where('code','KG')->first();
                    if(!$unit||$unit->dimension!=='MASS'||bccomp((string)$unit->standard_factor,'1',6)!==0)throw new \RuntimeException('Invalid kilogram dictionary');
                    $received=(string)$lot->qty_received_kg;$remaining=(string)$lot->qty_remaining_kg;
                    $cost=(string)$lot->unit_cost_per_kg;
                    if(bccomp($received,'0',6)<=0)throw new \RuntimeException('Legacy received basis missing');
                    $value=bcmul((string)$lot->total_cost,bcdiv($remaining,$received,18),18);
                    $balance=LegacyCapitalizationEvidence::balance($lot);if($balance)$value=$balance->remaining_value;
                }elseif($lot->uom_version==='BASE_V1'){
                    $unit=DB::table('uom_units')->where('id',$lot->base_uom_id_snapshot)->first();
                    $mapping=DB::table('item_uom_mappings')->where('id',$lot->uom_mapping_id)->where('company_id',$company)->where('item_id',$lot->item_id)->first();
                    if(!$unit||!$mapping||(int)$mapping->base_unit_id!==(int)$unit->id||($item->base_uom_id!==null&&(int)$item->base_uom_id!==(int)$unit->id)||($item->base_uom_id===null&&($item->base_unit_code!=='KG'||$unit->code!=='KG')))throw new \RuntimeException('Base evidence mismatch');
                    $received=(string)$lot->received_base_qty;$remaining=(string)$lot->remaining_base_qty;$cost=(string)$lot->unit_cost_per_base;
                    if(bccomp((string)$lot->qty_received_kg,'0',6)||bccomp((string)$lot->qty_remaining_kg,'0',6))throw new \RuntimeException('Dual quantity rejected');
                    $value=null; // Read posted movement costs, never invent rounded FIFO valuation.
                }else throw new \RuntimeException('Unknown quantity version');
                if(bccomp($remaining,'0',6)<0||bccomp($remaining,$received,6)>0)throw new \RuntimeException('Invalid remaining quantity');
                // Source number alone cannot deduplicate invoice splits. Ambiguous cross-version evidence fails closed.
                if($lot->source_id!==null){
                    $event=implode(':',[$lot->branch_id,$lot->item_id,$lot->source_type,$lot->source_id,$lot->purchase_invoice_line_id??'unknown-line']);
                    if(isset($events[$event])&&$events[$event]!==$lot->uom_version)throw new \RuntimeException('AMBIGUOUS_DUAL_EVENT: explicit canonical event link required');
                    $events[$event]=$lot->uom_version;
                }
                $mqry=DB::table('inventory_lot_movements')->where('inventory_lot_id',$lot->id)->orderBy('movement_at')->orderBy('id');
                if(!DB::getSchemaBuilder()->hasColumn('inventory_lot_movements','uom_version'))$mqry->selectRaw("inventory_lot_movements.*, 'LEGACY_KG' as uom_version");
                $movements=$mqry->get();
                $capitalized='0.000';
                if($lot->uom_version==='LEGACY_KG')$capitalized=LegacyCapitalizationEvidence::proven($lot)??'0.000';
                if($lot->uom_version==='BASE_V1'){
                    $capitalized=BaseLotCapitalization::audit($lot);
                    $first=$movements->first();
                    if(!$first||$first->movement_type!=='IN'||bccomp((string)$first->base_quantity,$received,6)!==0||bccomp((string)$first->total_cost,(string)$lot->base_cost,6)!==0)throw new \RuntimeException('BASE receipt cost/quantity evidence mismatch');
                }
                $mq='0';$mv=$capitalized;
                foreach($movements as $movement){
                    if((int)$movement->company_id!==$company||(int)$movement->branch_id!==(int)$lot->branch_id||(int)$movement->item_id!==(int)$lot->item_id||$movement->uom_version!==$lot->uom_version||!in_array($movement->movement_type,['IN','OUT'],true))throw new \RuntimeException('Movement scope/version/type evidence mismatch');
                    if($movement->uom_version==='BASE_V1'&&((int)$movement->base_uom_id_snapshot!==(int)$unit->id||(int)$movement->uom_mapping_id!==(int)$lot->uom_mapping_id))throw new \RuntimeException('Movement unit evidence mismatch');
                    $q=$movement->uom_version==='LEGACY_KG'?(string)$movement->qty_kg:(string)$movement->base_quantity;
                    if(bccomp($q,'0',6)<=0||bccomp((string)$movement->total_cost,'0',6)<0)throw new \RuntimeException('Invalid movement amount');
                    $mq=$movement->movement_type==='IN'?bcadd($mq,$q,6):bcsub($mq,$q,6);
                    $mv=$movement->movement_type==='IN'?bcadd($mv,(string)$movement->total_cost,6):bcsub($mv,(string)$movement->total_cost,6);
                }
                if(!$movements->count()||bccomp($mq,$remaining,6)!==0)throw new \RuntimeException('Unreconciled movement quantity');
                if($value===null){if(bccomp($mv,'0',6)<0)throw new \RuntimeException('Negative movement valuation');$value=$mv;}
                $key=$lot->branch_id.':'.$lot->item_id.':'.$unit->id;
                $groups[$key]??=['item_id'=>(int)$lot->item_id,'branch_id'=>(int)$lot->branch_id,'unit'=>$unit->code,'dimension'=>$unit->dimension,'quantity'=>'0.000000','value'=>'0.000000000000000000','movement_value'=>'0.000000','reserved_quantity'=>null,'availability_status'=>'ON_HAND_ONLY_NO_RESERVATION_MODEL','lots'=>[]];
                $groups[$key]['quantity']=bcadd($groups[$key]['quantity'],$remaining,6);
                $groups[$key]['value']=bcadd($groups[$key]['value'],$value,18);
                $groups[$key]['movement_value']=bcadd($groups[$key]['movement_value'],$mv,6);
                $groups[$key]['lots'][]=['id'=>(int)$lot->id,'version'=>$lot->uom_version,'remaining'=>bcadd($remaining,'0',6),'unit_cost'=>bcadd($cost,'0',6),'movement_quantity'=>$mq,'movement_value'=>$mv];
            }
            foreach($groups as &$group){
                $group['valuation_difference']=bcsub($group['value'],$group['movement_value'],18);
                $group['valuation_status']=bccomp($group['valuation_difference'],'0',18)===0?'MATCHED':'REQUIRES_REVIEW_LEGACY_ROUNDING_OR_CAPITALISATION';
                $group['value_unrounded']=$group['value'];$group['value']=self::rounded($group['value'],3);
                $group['average_unit_cost']=bccomp($group['quantity'],'0',6)>0?bcdiv($group['value_unrounded'],$group['quantity'],6):null;
            }
            unset($group);
            return array_values($groups); // No dimensionless quantity grand total; no cross-currency value claim.
        });
    }

    public function guardLegacy(int $company,array $branches):void
    {
        foreach($branches as $branch)UomAuthorization::actor($company,false,$branch);
        $this->assertSchema();
        if(!$branches)throw new \RuntimeException('Explicit branch scope required');
        if(DB::table('branches')->where('company_id',$company)->whereIn('id',array_unique($branches))->count()!==count(array_unique($branches)))throw new \RuntimeException('Foreign branch scope rejected');
        foreach(['inventory_lots','inventory_lot_movements','purchase_invoice_lines','sales_invoice_lines'] as $table){
            if(!DB::getSchemaBuilder()->hasColumn($table,'uom_version'))continue;
            $q=DB::table($table.' as r')->where('r.company_id',$company)->where('r.uom_version','BASE_V1');
            if(in_array($table,['inventory_lots','inventory_lot_movements'],true))$q->whereIn('r.branch_id',$branches);
            else{
                $sale=$table==='sales_invoice_lines';$parent=$sale?'sales_invoices':'purchase_invoices';$fk=$sale?'sales_invoice_id':'purchase_invoice_id';
                $q->join($parent.' as p','p.id','=','r.'.$fk)->where('p.company_id',$company)->whereIn('p.branch_id',$branches);
            }
            if($q->exists())throw new \RuntimeException('UOM_READER_REQUIRED: legacy report cannot interpret BASE_V1');
        }
    }
}
