<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

/** Physical evidence can corroborate a shipment; it never creates inventory units. */
final class BaseShipmentAdmission
{
    public function captureCostPolicy(int $company,int $shipment,int $user,string $method,array $inputs=[]):void
    {
        if(!in_array($method,['RELATIVE_VALUE','MANUAL_PERCENT'],true))throw new \RuntimeException('UOM_SHIPMENT_COST_BASIS: اختر قيمة البنود أو نسب توزيع معتمدة.');
        $approval=null;
        if($method==='MANUAL_PERCENT'){
            foreach($inputs as $row)if(isset($row['cost_share_percent']))(new \App\Services\ShipmentLargestRemainder)->decimal((string)$row['cost_share_percent'],4);
            if(!DB::table('users')->where('id',$user)->where('company_id',$company)->where('is_active',1)->exists()||!app(\App\Services\Auth\SessionContextService::class)->permissionsForUser($user,$company)->contains('shipments.cost'))throw new \RuntimeException('UOM_SHIPMENT_PERCENT_PERMISSION');
            $rows=$this->basis($company,$shipment);(new ShipmentCostBasis)->plan('0','MANUAL_PERCENT',$rows,true);$approval=$user;
        }
        DB::table('shipments')->where('company_id',$company)->where('id',$shipment)->update(['uom_cost_policy'=>'INDEPENDENT_INVENTORY_V1','uom_cost_basis_approved_by'=>$approval,'uom_cost_basis_approved_at'=>$approval?now():null]);
    }
    private function basis(int $company,int $shipment):array
    {
        $out=[];foreach(DB::table('shipment_items as s')->join('items as i',function($j){$j->on('i.id','=','s.item_id')->on('i.company_id','=','s.company_id');})->where('s.company_id',$company)->where('s.shipment_id',$shipment)->get(['s.id','s.cost_share_percent','s.base_total_before_vat','i.item_code']) as $line)$out[$line->id]=['item_code'=>$line->item_code,'base_value'=>$line->base_total_before_vat,'cost_share_percent'=>$line->cost_share_percent];return $out;
    }
    public function validate(int $company,object $shipment):string
    {
        OperationalDocument::ready();$id=(int)$shipment->id;
        if(($shipment->uom_cost_policy??'')!=='INDEPENDENT_INVENTORY_V1')throw new \RuntimeException('UOM_SHIPMENT_POLICY_REQUIRED');
        if(!in_array($shipment->cost_allocation_method,['RELATIVE_VALUE','MANUAL_PERCENT'],true))throw new \RuntimeException('UOM_SHIPMENT_COST_BASIS');
        if($shipment->cost_allocation_method==='MANUAL_PERCENT'){
            if(!$shipment->uom_cost_basis_approved_by||!$shipment->uom_cost_basis_approved_at)throw new \RuntimeException('UOM_SHIPMENT_PERCENT_PERMISSION');
            (new ShipmentCostBasis)->plan('0','MANUAL_PERCENT',$this->basis($company,$id),true);
        }
        $lines=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$id)->orderBy('id')->lockForUpdate()->get();$physical=[];$missing=[];$legacy=false;
        foreach($lines as $line){
            if(OperationalDocument::isBase($line)){
                $item=DB::table('items')->where('company_id',$company)->where('id',$line->item_id)->where('is_active',1)->first();if(!$item)throw new \RuntimeException('UOM_SHIPMENT_ITEM_SCOPE');OperationalDocument::prepareSnapshot($company,$item,$line);
                if($line->actual_weight_kg===null)$missing[$line->item_id]=true;else $physical[$line->item_id]=bcadd($physical[$line->item_id]??'0',$line->actual_weight_kg,3);
            }else{$legacy=true;$physical[$line->item_id]=bcadd($physical[$line->item_id]??'0',$line->gross_qty_kg,3);}
        }
        $cards=DB::table('weighbridge_cards')->where('company_id',$company)->where('shipment_id',$id)->orderBy('id')->get();if($cards->contains(fn($c)=>$c->status==='OPEN'))throw new \RuntimeException('يوجد كرت ميزان مفتوح. أغلقه قبل تجهيز الشحنة.');$closed=$cards->where('status','CLOSED');
        if($legacy&&$closed->isEmpty())throw new \RuntimeException('الأسطر الوزنية تحتاج قياس ميزان مغلقًا وموثقًا.');
        $tolerance=(string)(DB::table('company_settings')->where('company_id',$company)->value('shipment_item_tolerance_kg')??'5');$expected=[];$loaded='0';$empty='0';$net='0';
        foreach($closed as $card){$loaded=bcadd($loaded,$card->loaded_weight_kg,3);$empty=bcadd($empty,$card->empty_weight_kg,3);$net=bcadd($net,$card->net_weight_kg,3);
            if($card->item_id){$expected[$card->item_id]=bcadd($expected[$card->item_id]??'0',$card->net_weight_kg,3);continue;}
            $allocations=DB::table('weighbridge_card_item_allocations')->where('company_id',$company)->where('shipment_id',$id)->where('weighbridge_card_id',$card->id)->get();$sum='0';foreach($allocations as $a){$sum=bcadd($sum,$a->gross_qty_kg,3);$expected[$a->item_id]=bcadd($expected[$a->item_id]??'0',$a->gross_qty_kg,3);}
            if($allocations->isEmpty()||$this->outside(bcsub($sum,$card->net_weight_kg,3),$tolerance))throw new \RuntimeException('UOM_SHIPMENT_PHYSICAL_ALLOCATION_REQUIRED: وزع القياس الفعلي للكرت دون افتراض وزن.');
        }
        $difference='0';foreach($expected as $item=>$quantity){if(isset($missing[$item])||!array_key_exists($item,$physical))throw new \RuntimeException('UOM_SHIPMENT_PHYSICAL_EVIDENCE_REQUIRED: وثق وزن البند المرتبط بالكرت دون تغيير كميته المخزنية.');$diff=bcsub($physical[$item],$quantity,3);if($this->outside($diff,$tolerance))throw new \RuntimeException('UOM_SHIPMENT_PHYSICAL_MISMATCH');$difference=bcadd($difference,$diff,3);}
        foreach($lines as $line)if(!OperationalDocument::isBase($line)&&!array_key_exists($line->item_id,$expected))throw new \RuntimeException('UOM_SHIPMENT_PHYSICAL_EVIDENCE_REQUIRED');
        DB::table('shipments')->where('company_id',$company)->where('id',$id)->update(['total_loaded_weight_kg'=>$loaded,'total_empty_weight_kg'=>$empty,'total_net_weight_kg'=>$net,'physical_net_weight_kg'=>$net,'weight_variance_kg'=>$difference,'updated_at'=>now()]);return $difference;
    }
    private function outside(string $difference,string $tolerance):bool {return bccomp($difference,$tolerance,3)>0||bccomp($difference,bcsub('0',$tolerance,3),3)<0;}
}
