<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WeighbridgeService
{
    public function openCard(array $d): array
    {
        return DB::transaction(function()use($d){$cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];$sid=(int)$d['shipment_id'];$shipment=DB::table('shipments')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$sid)->lockForUpdate()->first();if(!$shipment)throw new \RuntimeException('الشحنة غير موجودة ضمن الفرع.');if($shipment->status!=='DRAFT')throw new \RuntimeException('كرت الميزان يفتح للشحنة المسودة فقط.');
            $existing=DB::table('weighbridge_cards')->where('company_id',$cid)->where('shipment_id',$sid)->first();if($existing)return $this->details($cid,$bid,(int)$existing->id);
            $no=$this->nextNumber($cid);$id=DB::table('weighbridge_cards')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'shipment_id'=>$sid,'car_id'=>$shipment->car_id,'card_number'=>$no,'flow_type'=>$d['flow_type']??'PURCHASE_INBOUND','status'=>'OPEN','deduction_weight_kg'=>round((float)($d['deduction_weight_kg']??0),3),'scale_name'=>$d['scale_name']??null,'external_ticket_number'=>$d['external_ticket_number']??null,'opened_at'=>now(),'opened_by'=>$d['created_by']??null,'notes'=>$d['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('shipments')->where('id',$sid)->update(['weighbridge_card_id'=>$id,'weight_card_number'=>$no,'flow_type'=>$d['flow_type']??'PURCHASE_INBOUND','updated_at'=>now()]);return $this->details($cid,$bid,$id);
        });
    }

    public function recordWeight(int $cardId,array $d): array
    {
        return DB::transaction(function()use($cardId,$d){$cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];$card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();if(!$card)throw new \RuntimeException('كرت الميزان غير موجود.');if($card->status!=='OPEN')throw new \RuntimeException('كرت الميزان مغلق ولا يقبل أوزانًا جديدة.');
            $event=strtoupper(trim((string)$d['event_type']));if(!in_array($event,['LOADED','EMPTY','RECHECK','CORRECTION'],true))throw new \RuntimeException('نوع حركة الوزن غير صحيح.');$effective=strtoupper(trim((string)($d['effective_weight_type']??'')));if(in_array($event,['LOADED','EMPTY'],true))$effective=$event;if(!in_array($effective,['LOADED','EMPTY'],true))throw new \RuntimeException('حدد هل القراءة محمل أم فارغ.');$kg=round((float)$d['weight_kg'],3);if($kg<=0)throw new \RuntimeException('الوزن يجب أن يكون أكبر من صفر.');if($event==='CORRECTION'&&mb_strlen(trim((string)($d['notes']??'')))<3)throw new \RuntimeException('سبب التصحيح مطلوب.');
            DB::table('shipment_weights')->insert(['company_id'=>$cid,'branch_id'=>$bid,'weighbridge_card_id'=>$cardId,'shipment_id'=>$card->shipment_id,'car_id'=>$card->car_id,'event_type'=>$event,'effective_weight_type'=>$effective,'weight_kg'=>$kg,'recorded_at'=>$d['recorded_at']??now(),'scale_name'=>$d['scale_name']??$card->scale_name,'ticket_number'=>$d['ticket_number']??null,'notes'=>$d['notes']??null,'created_by'=>$d['created_by']??null,'created_at'=>now(),'updated_at'=>now()]);
            $this->recalculate($cardId);return $this->details($cid,$bid,$cardId);
        });
    }

    public function updateDeduction(int $cardId,array $d): array
    {
        return DB::transaction(function()use($cardId,$d){$cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];$card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();if(!$card||$card->status!=='OPEN')throw new \RuntimeException('كرت الميزان غير موجود أو مغلق.');$ded=round((float)($d['deduction_weight_kg']??0),3);if($ded<0)throw new \RuntimeException('خصم الوزن لا يمكن أن يكون سالبًا.');DB::table('weighbridge_cards')->where('id',$cardId)->update(['deduction_weight_kg'=>$ded,'updated_at'=>now()]);$this->recalculate($cardId);return $this->details($cid,$bid,$cardId);});
    }

    public function cancelWeight(int $weightId,array $d): array
    {
        return DB::transaction(function()use($weightId,$d){$reason=trim((string)($d['reason']??''));if(mb_strlen($reason)<5)throw new \RuntimeException('سبب إلغاء قراءة الوزن مطلوب.');$w=DB::table('shipment_weights as w')->join('weighbridge_cards as c','c.id','=','w.weighbridge_card_id')->where('w.company_id',(int)$d['company_id'])->where('w.branch_id',(int)$d['branch_id'])->where('w.id',$weightId)->select('w.*','c.status as card_status')->lockForUpdate()->first();if(!$w)throw new \RuntimeException('قراءة الوزن غير موجودة.');if($w->card_status!=='OPEN')throw new \RuntimeException('لا يمكن تعديل سجل وزن بعد إغلاق الكرت.');if($w->cancelled_at)throw new \RuntimeException('قراءة الوزن ملغاة مسبقًا.');DB::table('shipment_weights')->where('id',$weightId)->update(['cancelled_at'=>now(),'cancelled_by'=>$d['created_by']??null,'cancel_reason'=>$reason,'updated_at'=>now()]);$this->recalculate((int)$w->weighbridge_card_id);return $this->details((int)$d['company_id'],(int)$d['branch_id'],(int)$w->weighbridge_card_id);});
    }

    public function closeCard(int $cardId,array $d): array
    {
        return DB::transaction(function()use($cardId,$d){$cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];$this->recalculate($cardId);$card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();if(!$card)throw new \RuntimeException('كرت الميزان غير موجود.');if($card->status==='CLOSED')return $this->details($cid,$bid,$cardId);if((float)$card->loaded_weight_kg<=0||(float)$card->empty_weight_kg<=0)throw new \RuntimeException('يجب تسجيل وزن محمل ووزن فارغ قبل الإغلاق.');if((float)$card->loaded_weight_kg<=(float)$card->empty_weight_kg)throw new \RuntimeException('الوزن المحمل يجب أن يكون أكبر من الوزن الفارغ.');if((float)$card->net_weight_kg<=0)throw new \RuntimeException('صافي الوزن بعد الخصم يجب أن يكون أكبر من صفر.');DB::table('weighbridge_cards')->where('id',$cardId)->update(['status'=>'CLOSED','closed_at'=>now(),'closed_by'=>$d['created_by']??null,'updated_at'=>now()]);return $this->details($cid,$bid,$cardId);});
    }

    public function listCards(int $cid,?int $bid=null)
    {
        $q=DB::table('weighbridge_cards as w')->leftJoin('shipments as s','s.id','=','w.shipment_id')->leftJoin('cars as c','c.id','=','w.car_id')->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')->where('w.company_id',$cid);if($bid!==null)$q->where('w.branch_id',$bid);return $q->select('w.*','s.shipment_number','s.shipment_date','s.status as shipment_status','c.car_number','c.plate_number','sp.supplier_name')->orderByRaw("CASE WHEN w.status='OPEN' THEN 0 ELSE 1 END")->orderByDesc('w.id')->get();
    }

    public function details(int $cid,int $bid,int $id): array
    {
        $card=DB::table('weighbridge_cards as w')->leftJoin('shipments as s','s.id','=','w.shipment_id')->leftJoin('cars as c','c.id','=','w.car_id')->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')->where('w.company_id',$cid)->where('w.branch_id',$bid)->where('w.id',$id)->select('w.*','s.shipment_number','s.shipment_date','s.status as shipment_status','c.car_number','c.plate_number','sp.supplier_name')->first();if(!$card)throw new \RuntimeException('كرت الميزان غير موجود.');
        $events=DB::table('shipment_weights as w')->leftJoin('users as u','u.id','=','w.created_by')->where('w.company_id',$cid)->where('w.weighbridge_card_id',$id)->select('w.*','u.name as created_by_name')->orderByDesc('w.recorded_at')->orderByDesc('w.id')->get();return ['card'=>$card,'events'=>$events];
    }

    private function recalculate(int $id): void
    {
        $card=DB::table('weighbridge_cards')->where('id',$id)->first();if(!$card)return;$latest=function(string $type)use($id){return DB::table('shipment_weights')->where('weighbridge_card_id',$id)->whereNull('cancelled_at')->where('effective_weight_type',$type)->orderByDesc('recorded_at')->orderByDesc('id')->value('weight_kg');};$loaded=round((float)($latest('LOADED')??0),3);$empty=round((float)($latest('EMPTY')??0),3);$ded=round((float)($card->deduction_weight_kg??0),3);$net=max(0,round($loaded-$empty-$ded,3));DB::table('weighbridge_cards')->where('id',$id)->update(['loaded_weight_kg'=>$loaded,'empty_weight_kg'=>$empty,'net_weight_kg'=>$net,'updated_at'=>now()]);if($card->shipment_id)DB::table('shipments')->where('id',$card->shipment_id)->update(['total_loaded_weight_kg'=>$loaded,'total_empty_weight_kg'=>$empty,'total_deduction_weight_kg'=>$ded,'total_net_weight_kg'=>$net,'total_gross_weight'=>$loaded,'total_tare_weight'=>$empty,'total_deduction_weight'=>$ded,'total_net_weight'=>round($net/1000,3),'updated_at'=>now()]);
    }
    private function nextNumber(int $cid): string {$year=now()->format('Y');$n=DB::table('weighbridge_cards')->where('company_id',$cid)->whereYear('opened_at',$year)->count()+1;do{$no='WB-'.$year.'-'.str_pad($n,6,'0',STR_PAD_LEFT);$exists=DB::table('weighbridge_cards')->where('company_id',$cid)->where('card_number',$no)->exists();$n++;}while($exists);return $no;}
}
