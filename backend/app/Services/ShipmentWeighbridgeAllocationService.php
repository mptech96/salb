<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stage 9:
 * New cards carry item_id directly at the scale, therefore normal operation
 * no longer requires a second allocation matrix. The old allocation table is
 * kept only for legacy/exceptional cards that do not have a material.
 */
class ShipmentWeighbridgeAllocationService
{
    public function summary(int $companyId, int $shipmentId): array
    {
        $cards=DB::table('weighbridge_cards as w')
            ->leftJoin('items as i','i.id','=','w.item_id')
            ->leftJoin('cars as c','c.id','=','w.car_id')->leftJoin('drivers as d','d.id','=','w.driver_id')
            ->where('w.company_id',$companyId)->where('w.shipment_id',$shipmentId)
            ->select(
                'w.id','w.card_number','w.status','w.item_id','w.item_code_snapshot','w.item_name_snapshot','w.net_weight_kg','w.entry_at','w.exit_at',
                'w.transport_mode','w.transport_label','w.plate_snapshot','c.plate_number','c.car_number','d.driver_name','i.item_code','i.item_name'
            )->orderBy('w.entry_at')->orderBy('w.id')->get();

        $items=DB::table('shipment_items as si')->join('items as i','i.id','=','si.item_id')
            ->where('si.company_id',$companyId)->where('si.shipment_id',$shipmentId)
            ->select('si.id as shipment_item_id','si.item_id','i.item_code','i.item_name','si.gross_qty_kg','si.deduction_qty_kg','si.accepted_qty_kg')
            ->orderBy('si.sorting_order')->get();

        $alloc=DB::table('weighbridge_card_item_allocations as a')->join('items as i','i.id','=','a.item_id')
            ->where('a.company_id',$companyId)->where('a.shipment_id',$shipmentId)
            ->select('a.*','i.item_code','i.item_name')->orderBy('a.weighbridge_card_id')->orderBy('a.id')->get();

        $byCard=[];foreach($alloc as $a){$byCard[(int)$a->weighbridge_card_id][]=$a;}
        $requires=false;
        foreach($cards as $c){
            if($c->item_id){
                $c->allocated_gross_qty_kg=round((float)($c->net_weight_kg??0),3);
                $c->unallocated_qty_kg=0;
                $c->material_source='CARD';
            }else{
                $requires=true;$rows=$byCard[(int)$c->id]??[];
                $gross=array_sum(array_map(fn($x)=>(float)$x->gross_qty_kg,$rows));
                $c->allocated_gross_qty_kg=round($gross,3);
                $c->unallocated_qty_kg=round((float)($c->net_weight_kg??0)-$gross,3);
                $c->material_source='MANUAL_ALLOCATION';
            }
        }
        return ['cards'=>$cards,'items'=>$items,'allocations'=>$alloc,'requires_manual_allocation'=>$requires];
    }

    /** Only legacy cards without item_id are manually distributed. */
    public function replace(int $companyId,int $branchId,int $shipmentId,array $rows,int $userId): array
    {
        return DB::transaction(function()use($companyId,$branchId,$shipmentId,$rows,$userId){
            $s=DB::table('shipments')->where('company_id',$companyId)->where('id',$shipmentId)->lockForUpdate()->first();
            if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');
            if((int)$s->branch_id!==$branchId)throw new \RuntimeException('الشحنة خارج نطاق الفرع.');
            if(strtoupper((string)($s->commercial_status??'DRAFT'))!=='DRAFT')throw new \RuntimeException('توزيع الكروت القديمة متاح للشحنة المسودة فقط.');

            $cards=DB::table('weighbridge_cards')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->lockForUpdate()->get()->keyBy('id');
            $items=DB::table('shipment_items')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->get()->keyBy('item_id');
            if($cards->isEmpty())throw new \RuntimeException('لا توجد كروت ميزان مرتبطة بالشحنة.');
            if($items->isEmpty())throw new \RuntimeException('احفظ أصناف الشحنة أولاً.');

            $clean=[];$totals=[];
            foreach($rows as $n=>$r){
                $cardId=(int)($r['weighbridge_card_id']??0);$itemId=(int)($r['item_id']??0);$gross=round((float)($r['gross_qty_kg']??0),3);
                if(!$cardId||!$itemId||$gross<=0)continue;
                $card=$cards->get($cardId);if(!$card)throw new \RuntimeException('السطر '.($n+1).': كرت الميزان لا يتبع هذه الشحنة.');
                if($card->item_id)continue; // New cards already know their material at the scale.
                if(strtoupper((string)$card->status)!=='CLOSED')throw new \RuntimeException('السطر '.($n+1).': أغلق كرت الميزان قبل توزيعه.');
                $si=$items->get($itemId);if(!$si)throw new \RuntimeException('السطر '.($n+1).': الصنف غير موجود في تجهيز الشحنة.');
                $clean[]=[
                    'company_id'=>$companyId,'branch_id'=>$branchId,'shipment_id'=>$shipmentId,'weighbridge_card_id'=>$cardId,
                    'shipment_item_id'=>(int)$si->id,'item_id'=>$itemId,'gross_qty_kg'=>$gross,'deduction_qty_kg'=>0,'accepted_qty_kg'=>$gross,
                    'deduction_reason'=>null,'notes'=>$r['notes']??'توزيع كرت قديم بلا صنف مثبت على الميزان.','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
                ];
                $totals[$cardId]=($totals[$cardId]??0)+$gross;
            }
            $tol=(float)(DB::table('company_settings')->where('company_id',$companyId)->value('shipment_item_tolerance_kg')??5);
            foreach($cards as $card){
                if($card->item_id || strtoupper((string)$card->status)!=='CLOSED')continue;
                $gross=round((float)($totals[(int)$card->id]??0),3);$net=round((float)($card->net_weight_kg??0),3);
                if(abs($gross-$net)>$tol)throw new \RuntimeException('توزيع الكرت '.$card->card_number.' غير مكتمل. صافي الكرت '.number_format($net,3).' كجم والموزع '.number_format($gross,3).' كجم.');
            }
            DB::table('weighbridge_card_item_allocations')->where('company_id',$companyId)->where('shipment_id',$shipmentId)
                ->whereIn('weighbridge_card_id',$cards->filter(fn($c)=>!$c->item_id)->keys()->all())->delete();
            foreach($clean as $x)DB::table('weighbridge_card_item_allocations')->insert($x);
            return $this->summary($companyId,$shipmentId);
        });
    }

    public function assertReady(int $companyId,int $shipmentId): void
    {
        $cards=DB::table('weighbridge_cards')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->where('status','CLOSED')->get(['id','card_number','item_id','net_weight_kg']);
        if($cards->isEmpty())return;
        $tol=(float)(DB::table('company_settings')->where('company_id',$companyId)->value('shipment_item_tolerance_kg')??5);
        $alloc=DB::table('weighbridge_card_item_allocations')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->get();

        $expected=[];
        foreach($cards as $c){
            $net=round((float)$c->net_weight_kg,3);
            if($c->item_id){
                $expected[(int)$c->item_id]=($expected[(int)$c->item_id]??0)+$net;
                continue;
            }
            $rows=$alloc->where('weighbridge_card_id',$c->id);
            if($rows->isEmpty())throw new \RuntimeException('الكرت القديم '.$c->card_number.' لا يحتوي صنفًا. وزعه على أصناف الشحنة قبل جعلها جاهزة.');
            $sum=round((float)$rows->sum('gross_qty_kg'),3);
            if(abs($sum-$net)>$tol)throw new \RuntimeException('توزيع الكرت '.$c->card_number.' غير مكتمل. صافي الكرت '.number_format($net,3).' كجم والموزع '.number_format($sum,3).' كجم.');
            foreach($rows as $r)$expected[(int)$r->item_id]=($expected[(int)$r->item_id]??0)+(float)$r->gross_qty_kg;
        }

        $shipmentItems=DB::table('shipment_items')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->get(['item_id','gross_qty_kg']);
        foreach($expected as $itemId=>$expectedGross){
            $si=$shipmentItems->firstWhere('item_id',$itemId);
            if(!$si)throw new \RuntimeException('يوجد وزن مثبت على كرت ميزان لصنف غير موجود في تجهيز الشحنة.');
            $actual=round((float)$si->gross_qty_kg,3);$expectedGross=round((float)$expectedGross,3);
            if(abs($actual-$expectedGross)>$tol)throw new \RuntimeException('وزن أحد الأصناف في تجهيز الشحنة لا يطابق مجموع كروت الميزان الخاصة به.');
        }
    }
}
