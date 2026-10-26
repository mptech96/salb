<?php
namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Accounting\AccountingContext;

/** Isolated reference lifecycle, never bound into operational ShipmentService/routes. */
final class ShipmentLineIdentityPrototype
{
    private function context(Request $r,int $shipment): object
    {
        UomStageAProof::guard();
        if(!$r->user()||(int)$r->attributes->get('authenticated_user_id')!==(int)$r->user()->id)abort(401);
        $c=app(AccountingContext::class);$cid=$c->companyId($r);$branch=$c->branchFilter($r);
        $s=DB::table('shipments')->where('company_id',$cid)->where('id',$shipment)->when($branch!==null,fn($q)=>$q->where('branch_id',$branch))->lockForUpdate()->first();
        if(!$s)abort(404);
        if($s->commercial_status!=='DRAFT'||DB::table('invoice_shipment_links')->where('company_id',$cid)->where('shipment_id',$shipment)->exists()||DB::table('shipment_costs')->where('company_id',$cid)->where('shipment_id',$shipment)->where('cost_status','POSTED')->exists())throw new \RuntimeException('SLI_SOURCE_NOT_EDITABLE');
        return $s;
    }
    private function input(Request $r,bool $new): array
    {
        // Actual tenant middleware injects company/branch. They are NEVER write sources here.
        foreach(array_keys($r->all()) as $key)if(!in_array($key,['business_line_ref','item_id','qty_kg','unit_price_per_kg','sorting_order','company_id','branch_id'],true))throw new \RuntimeException('SLI_READ_ONLY_OR_UNKNOWN_FIELD');
        if(!$new&&$r->exists('business_line_ref'))throw new \RuntimeException('SLI_REFERENCE_CHANGE_FORBIDDEN');
        return $r->validate(['business_line_ref'=>$new?'required|string|regex:/^[A-Z0-9][A-Z0-9._\/-]{0,119}$/D':'prohibited','item_id'=>$new?'required|integer|min:1':'prohibited','qty_kg'=>($new?'required':'sometimes').'|numeric|gt:0','unit_price_per_kg'=>($new?'required':'sometimes').'|numeric|min:0','sorting_order'=>'sometimes|integer|min:0|max:10000']);
    }
    private function amounts(object $s,int $item,array $d,?object $old=null): array
    {
        if(!DB::table('items')->where('company_id',$s->company_id)->where('id',$item)->where('is_active',1)->exists())throw new \RuntimeException('SLI_ITEM_SCOPE');
        $qty=round((float)($d['qty_kg']??$old->qty_kg),3);$price=round((float)($d['unit_price_per_kg']??$old->unit_price_per_kg),6);
        if($qty<=0)throw new \RuntimeException('SLI_QUANTITY');
        // Real tax calculator; this is a draft-line adapter, not a new accounting engine.
        $tax=app(\App\Services\TaxEngineService::class)->line((int)$s->company_id,round($qty*$price,3),0,null,(string)$s->shipment_date,$s->shipment_type==='SALE'?'SALES':'PURCHASE',0);
        $base=round((float)$tax['total_before_vat']*(float)($s->exchange_rate?:1),3);
        return ['qty_kg'=>$qty,'accepted_qty_kg'=>$qty,'gross_qty_kg'=>$qty,'remaining_qty_kg'=>$qty,'inventory_qty_kg'=>$qty,'unit_price_per_kg'=>$price,'base_cost'=>$base,'total_before_vat'=>$tax['total_before_vat'],'total_after_vat'=>$tax['total_after_vat'],'base_total_before_vat'=>$base,'sorting_order'=>$d['sorting_order']??$old?->sorting_order??0,'updated_at'=>now()];
    }
    public function create(Request $r,int $shipment): array
    {
        return DB::transaction(function()use($r,$shipment){$s=$this->context($r,$shipment);$d=$this->input($r,true);
            $ref='BL:'.$d['business_line_ref'];
            $id=DB::table('shipment_items')->insertGetId($this->amounts($s,(int)$d['item_id'],$d)+['company_id'=>$s->company_id,'shipment_id'=>$s->id,'item_id'=>$d['item_id'],'allocation_line_ref'=>$ref,'created_at'=>now()]);
            return (array)DB::table('shipment_items')->where('id',$id)->first();
        });
    }
    public function update(Request $r,int $shipment,int $line): array
    {
        return DB::transaction(function()use($r,$shipment,$line){$s=$this->context($r,$shipment);$d=$this->input($r,false);
            $old=DB::table('shipment_items')->where('company_id',$s->company_id)->where('shipment_id',$shipment)->where('id',$line)->lockForUpdate()->first();if(!$old)abort(404);
            if($old->allocation_line_ref===null)throw new \RuntimeException('SLI_LEGACY_REQUIRES_REVIEW');
            if($old->inventory_created||$old->inventory_lot_id||$old->purchase_line_id)throw new \RuntimeException('SLI_FINANCIAL_LINE');
            DB::table('shipment_items')->where('id',$line)->update($this->amounts($s,(int)$old->item_id,$d,$old));
            return (array)DB::table('shipment_items')->where('id',$line)->first();
        });
    }
}
