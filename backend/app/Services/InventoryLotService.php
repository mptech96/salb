<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryLotService
{
    public function createInboundLot(array $d): int
    {
        $cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];$item=(int)$d['item_id'];$kg=round((float)$d['qty_kg'],3);$base=round((float)($d['base_cost']??0),3);$alloc=round((float)($d['allocated_cost']??0),3);$total=round($base+$alloc,3);
        if($kg<=0)throw new \RuntimeException('كمية الدفعة يجب أن تكون أكبر من صفر.'); if($total<0)throw new \RuntimeException('تكلفة الدفعة غير صحيحة.');
        $lotNo=$d['lot_number']??$this->nextLotNumber($cid,(string)($d['received_at']??now()));$unit=$kg>0?round($total/$kg,6):0;
        $id=DB::table('inventory_lots')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'item_id'=>$item,'car_id'=>$d['car_id']??null,'shipment_id'=>$d['shipment_id']??null,'shipment_item_id'=>$d['shipment_item_id']??null,'purchase_invoice_id'=>$d['purchase_invoice_id']??null,'purchase_invoice_line_id'=>$d['purchase_invoice_line_id']??null,'lot_number'=>$lotNo,'source_type'=>$d['source_type']??'PURCHASE','source_id'=>$d['source_id']??null,'received_at'=>$d['received_at']??now(),'qty_received_kg'=>$kg,'qty_remaining_kg'=>$kg,'qty_sold_kg'=>0,'base_cost'=>$base,'allocated_cost'=>$alloc,'total_cost'=>$total,'unit_cost_per_kg'=>$unit,'lot_status'=>'OPEN','notes'=>$d['notes']??null,'created_by'=>$d['created_by']??null,'created_at'=>now(),'updated_at'=>now()]);
        $this->movement(['company_id'=>$cid,'branch_id'=>$bid,'inventory_lot_id'=>$id,'item_id'=>$item,'movement_type'=>'IN','source_type'=>$d['source_type']??'PURCHASE','source_id'=>$d['source_id']??null,'movement_at'=>$d['received_at']??now(),'qty_kg'=>$kg,'unit_cost_per_kg'=>$unit,'total_cost'=>$total,'notes'=>$d['notes']??null,'created_by'=>$d['created_by']??null]);
        return $id;
    }

    public function consumeFifo(int $cid,int $bid,int $itemId,float $qtyKg,string $sourceType,int $sourceId,?int $carId=null,?int $userId=null): array
    {
        $qtyKg=round($qtyKg,3); if($qtyKg<=0)throw new \RuntimeException('كمية الصرف يجب أن تكون أكبر من صفر.');
        $lots=DB::table('inventory_lots')->where('company_id',$cid)->where('branch_id',$bid)->where('item_id',$itemId)->where('lot_status','OPEN')->where('qty_remaining_kg','>',0)->when($carId,fn($q)=>$q->where('car_id',$carId))->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
        $available=round((float)$lots->sum('qty_remaining_kg'),3); if($available+0.0001<$qtyKg)throw new \RuntimeException('الكمية المطلوبة أكبر من المخزون المتاح. المتوفر: '.number_format($available,3).' كجم.');
        $remaining=$qtyKg;$totalCost=0.0;$allocations=[];
        foreach($lots as $lot){if($remaining<=0)break;$take=min($remaining,(float)$lot->qty_remaining_kg);$unit=round((float)$lot->unit_cost_per_kg,6);$cost=round($take*$unit,3);$newRemain=round((float)$lot->qty_remaining_kg-$take,3);$newSold=round((float)$lot->qty_sold_kg+$take,3);
            DB::table('inventory_lots')->where('id',$lot->id)->update(['qty_remaining_kg'=>$newRemain,'qty_sold_kg'=>$newSold,'lot_status'=>$newRemain<=0.0001?'CLOSED':'OPEN','updated_at'=>now()]);
            $this->movement(['company_id'=>$cid,'branch_id'=>$bid,'inventory_lot_id'=>$lot->id,'item_id'=>$itemId,'movement_type'=>'OUT','source_type'=>$sourceType,'source_id'=>$sourceId,'movement_at'=>now(),'qty_kg'=>$take,'unit_cost_per_kg'=>$unit,'total_cost'=>$cost,'notes'=>'صرف FIFO من '.$lot->lot_number,'created_by'=>$userId]);
            $allocations[]=['inventory_lot_id'=>(int)$lot->id,'shipment_id'=>$lot->shipment_id?(int)$lot->shipment_id:null,'shipment_item_id'=>$lot->shipment_item_id?(int)$lot->shipment_item_id:null,'qty_kg'=>round($take,3),'unit_cost_per_kg'=>$unit,'total_cost'=>$cost];$totalCost+=$cost;$remaining=round($remaining-$take,3);
        }
        return ['qty_kg'=>$qtyKg,'total_cost'=>round($totalCost,3),'unit_cost_per_kg'=>$qtyKg>0?round($totalCost/$qtyKg,6):0,'allocations'=>$allocations];
    }

    public function addCapitalizedCost(int $cid,int $lotId,float $amount): void
    {
        $amount=round($amount,3);if($amount<=0)return;$lot=DB::table('inventory_lots')->where('company_id',$cid)->where('id',$lotId)->lockForUpdate()->first();if(!$lot)throw new \RuntimeException('دفعة المخزون غير موجودة.');
        if(round((float)$lot->qty_remaining_kg,3)<round((float)$lot->qty_received_kg,3))throw new \RuntimeException('لا يمكن إضافة تكلفة جديدة بعد بدء الصرف من الدفعة. سجّل تكاليف الحمولة قبل أول بيع.');
        $newAlloc=round((float)$lot->allocated_cost+$amount,3);$newTotal=round((float)$lot->base_cost+$newAlloc,3);$unit=(float)$lot->qty_received_kg>0?round($newTotal/(float)$lot->qty_received_kg,6):0;
        DB::table('inventory_lots')->where('id',$lotId)->update(['allocated_cost'=>$newAlloc,'total_cost'=>$newTotal,'unit_cost_per_kg'=>$unit,'updated_at'=>now()]);
    }

    public function summary(int $cid,?int $bid=null)
    {
        $q=DB::table('inventory_lots as l')->join('items as i','i.id','=','l.item_id')->leftJoin('branches as b','b.id','=','l.branch_id')->where('l.company_id',$cid);if($bid!==null)$q->where('l.branch_id',$bid);
        return $q->select('l.item_id','l.branch_id','i.item_code','i.item_name','i.item_grade','b.branch_name',DB::raw('SUM(l.qty_received_kg) qty_received_kg'),DB::raw('SUM(l.qty_remaining_kg) balance_kg'),DB::raw('SUM(l.qty_sold_kg) qty_sold_kg'),DB::raw('SUM(CASE WHEN l.qty_received_kg>0 THEN l.total_cost*(l.qty_remaining_kg/l.qty_received_kg) ELSE 0 END) stock_value'),DB::raw('SUM(CASE WHEN l.qty_remaining_kg>0 THEN 1 ELSE 0 END) open_lots'))->groupBy('l.item_id','l.branch_id','i.item_code','i.item_name','i.item_grade','b.branch_name')->orderBy('i.item_name')->get()->map(function($r){$bal=(float)$r->balance_kg;$val=(float)$r->stock_value;$r->balance_ton=round($bal/1000,3);$r->avg_cost_per_kg=$bal>0?round($val/$bal,6):0;$r->avg_cost_per_ton=$bal>0?round(($val/$bal)*1000,3):0;return $r;});
    }

    public function lots(int $cid,?int $bid=null,?int $itemId=null)
    {
        $q=DB::table('inventory_lots as l')->join('items as i','i.id','=','l.item_id')->leftJoin('branches as b','b.id','=','l.branch_id')->leftJoin('shipments as s','s.id','=','l.shipment_id')->leftJoin('cars as c','c.id','=','l.car_id')->where('l.company_id',$cid);if($bid!==null)$q->where('l.branch_id',$bid);if($itemId)$q->where('l.item_id',$itemId);
        return $q->select('l.*','i.item_code','i.item_name','b.branch_name','s.shipment_number','c.car_number','c.plate_number')->orderByDesc('l.received_at')->orderByDesc('l.id')->get();
    }

    private function movement(array $d): void { DB::table('inventory_lot_movements')->insert(['company_id'=>$d['company_id'],'branch_id'=>$d['branch_id'],'inventory_lot_id'=>$d['inventory_lot_id'],'item_id'=>$d['item_id'],'movement_type'=>$d['movement_type'],'source_type'=>$d['source_type'],'source_id'=>$d['source_id']??null,'movement_at'=>$d['movement_at']??now(),'qty_kg'=>round((float)$d['qty_kg'],3),'unit_cost_per_kg'=>round((float)($d['unit_cost_per_kg']??0),6),'total_cost'=>round((float)($d['total_cost']??0),3),'notes'=>$d['notes']??null,'created_by'=>$d['created_by']??null,'created_at'=>now(),'updated_at'=>now()]); }
    private function nextLotNumber(int $cid,string $date): string {$year=date('Y',strtotime($date));$n=DB::table('inventory_lots')->where('company_id',$cid)->whereYear('received_at',$year)->count()+1;do{$no='LOT-'.$year.'-'.str_pad($n,6,'0',STR_PAD_LEFT);$exists=DB::table('inventory_lots')->where('company_id',$cid)->where('lot_number',$no)->exists();$n++;}while($exists);return $no;}
}
