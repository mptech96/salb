<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WeighbridgeService
{
    public function __construct(private SulbDocumentSequenceService $sequences) {}

    /**
     * Stage 9 rule:
     * - Every new card identifies ONE physical material/item at the scale.
     * - Pricing, quality deductions and accepted quantity are still handled in the shipment.
     * - Vehicle is optional; non-vehicle weighing is represented explicitly, never by a fake fleet car.
     */
    public function openCard(array $d, ?int $branchFilter = null): array
    {
        return DB::transaction(function () use ($d, $branchFilter) {
            $cid=(int)$d['company_id'];
            $sid=isset($d['shipment_id'])&&(int)$d['shipment_id']>0?(int)$d['shipment_id']:null;
            $shipment=null;$bid=(int)($d['branch_id']??0);

            if($sid){
                $shipment=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();
                if(!$shipment)throw new \RuntimeException('الشحنة غير موجودة.');
                $bid=(int)$shipment->branch_id;
                if(($shipment->commercial_status??'DRAFT')!=='DRAFT')throw new \RuntimeException('لا يمكن إضافة كرت ميزان إلى شحنة جاهزة أو مفوترة. أعد فتحها أولاً.');
            }
            if($bid<=0)throw new \RuntimeException('حدد الفرع عند فتح كرت الميزان.');
            if($branchFilter!==null&&$bid!==$branchFilter)throw new \RuntimeException('الكرت خارج نطاق فرعك.');

            if($shipment){
                $type=strtoupper((string)($shipment->shipment_type??'PURCHASE'));
                $flow=match($type){'SALE'=>'SALE_OUTBOUND','TRANSFER','INTERNAL'=>'INTERNAL_TRANSFER',default=>'PURCHASE_INBOUND'};
            }else{
                $flow=strtoupper((string)($d['flow_type']??'PURCHASE_INBOUND'));
                if(!in_array($flow,['PURCHASE_INBOUND','SALE_OUTBOUND','INTERNAL_TRANSFER'],true))throw new \RuntimeException('حدد نوع الحركة: وارد شراء أو صادر بيع أو حركة داخلية.');
                $type=$flow==='SALE_OUTBOUND'?'SALE':($flow==='INTERNAL_TRANSFER'?'TRANSFER':'PURCHASE');
            }
            $direction=$flow==='SALE_OUTBOUND'?'OUTBOUND':($flow==='INTERNAL_TRANSFER'?'INTERNAL':'INBOUND');

            // Physical material is mandatory for every new card.
            $itemId=(int)($d['item_id']??0);
            if($itemId<=0)throw new \RuntimeException('حدد الصنف / المادة التي يتم وزنها في هذا الكرت.');
            $item=DB::table('items')->where('company_id',$cid)->where('id',$itemId)->where('is_active',1)->first();
            if(!$item)throw new \RuntimeException('الصنف المحدد غير موجود أو غير نشط.');
            if(strtoupper((string)($item->item_type??'STOCK'))==='SERVICE')throw new \RuntimeException('الخدمات لا تُوزن على الميزان. اختر صنفًا مخزنيًا أو مادة فعلية.');
            if($flow==='PURCHASE_INBOUND' && !(int)($item->can_purchase??1))throw new \RuntimeException('الصنف '.$item->item_name.' غير متاح للشراء.');
            if($flow==='SALE_OUTBOUND' && !(int)($item->can_sell??1))throw new \RuntimeException('الصنف '.$item->item_name.' غير متاح للبيع.');
            if($flow==='INTERNAL_TRANSFER' && !(int)($item->track_inventory??1))throw new \RuntimeException('الحركة الداخلية تتطلب صنفًا يتبع المخزون.');

            // Vehicle is optional. Do not create a fake car because it pollutes fleet reports.
            $transportMode=strtoupper(trim((string)($d['transport_mode']??'')));
            $carId=isset($d['car_id'])&&(int)$d['car_id']>0?(int)$d['car_id']:null;
            if($carId)$transportMode='VEHICLE';
            if($transportMode==='')$transportMode=$carId?'VEHICLE':'YARD_SCALE';
            if(!in_array($transportMode,['VEHICLE','YARD_SCALE','SMALL_SCALE','NO_VEHICLE'],true))throw new \RuntimeException('طريقة الوزن / الحركة غير صحيحة.');

            $car=null;
            if($transportMode==='VEHICLE'){
                if(!$carId)throw new \RuntimeException('اختر السيارة أو غيّر طريقة الوزن إلى ميزان الحوش / الميزان الصغير / بدون سيارة.');
                $car=DB::table('cars')->where('company_id',$cid)->where('id',$carId)->where('is_active',1)->first();
                if(!$car)throw new \RuntimeException('السيارة غير موجودة أو غير نشطة.');
                if(!empty($car->branch_id)&&(int)$car->branch_id!==$bid)throw new \RuntimeException('السيارة مرتبطة بفرع آخر.');
            }else{
                $carId=null;
            }

            $transportLabel=trim((string)($d['transport_label']??''));
            if($transportLabel===''){
                $transportLabel=match($transportMode){
                    'YARD_SCALE'=>'ميزان الحوش / بدون سيارة',
                    'SMALL_SCALE'=>'الميزان الصغير',
                    'NO_VEHICLE'=>'بدون سيارة',
                    default=>($car?->plate_number?:($car?->car_number?:'سيارة مسجلة')),
                };
            }

            $driverId=isset($d['driver_id'])&&(int)$d['driver_id']>0?(int)$d['driver_id']:
                (($shipment->driver_id??null)?(int)$shipment->driver_id:(($car->driver_id??null)?(int)$car->driver_id:null));
            $driver=$driverId?DB::table('drivers')->where('company_id',$cid)->where('id',$driverId)->first():null;
            if($driverId&&!$driver)throw new \RuntimeException('السائق غير موجود ضمن الشركة.');

            $partyType=null;$partyId=null;$partySnapshot=null;
            if($shipment&&$type==='SALE'&&!empty($shipment->customer_id)){
                $partyType='CUSTOMER';$partyId=(int)$shipment->customer_id;
                $partySnapshot=DB::table('customers')->where('company_id',$cid)->where('id',$partyId)->value('customer_name');
            }elseif($shipment&&!empty($shipment->supplier_id)){
                $partyType='SUPPLIER';$partyId=(int)$shipment->supplier_id;
                $partySnapshot=DB::table('suppliers')->where('company_id',$cid)->where('id',$partyId)->value('supplier_name');
            }

            $entryAt=!empty($d['entry_at'])?Carbon::parse($d['entry_at']):now();
            $no=$this->nextUniqueWeighbridgeNumber($cid,$entryAt);

            $id=DB::table('weighbridge_cards')->insertGetId([
                'company_id'=>$cid,'branch_id'=>$bid,'shipment_id'=>$sid,
                'item_id'=>$itemId,'item_code_snapshot'=>$item->item_code,'item_name_snapshot'=>$item->item_name,
                'item_assigned_at'=>now(),'item_assigned_by'=>$d['created_by']??null,'item_assignment_note'=>'تم تحديد المادة عند فتح كرت الميزان.',
                'car_id'=>$carId,'transport_mode'=>$transportMode,'transport_label'=>$transportLabel,'driver_id'=>$driverId,
                'party_type'=>$partyType,'party_id'=>$partyId,'card_number'=>$no,'flow_type'=>$flow,'direction'=>$direction,'status'=>'OPEN',
                'loaded_weight_kg'=>0,'empty_weight_kg'=>0,'deduction_weight_kg'=>0,'net_weight_kg'=>0,
                'scale_name'=>$d['scale_name']??null,'external_ticket_number'=>$d['external_ticket_number']??null,
                'opened_at'=>$entryAt,'entry_at'=>$entryAt,'opened_by'=>$d['created_by']??null,'notes'=>$d['notes']??null,
                'unassigned_reason'=>$sid?null:($d['unassigned_reason']??'تم فتح الكرت قبل إنشاء/اختيار الشحنة.'),
                'plate_snapshot'=>$car?($car->plate_number?:$car->car_number):null,
                'driver_snapshot'=>$driver?->driver_name,'party_snapshot'=>$partySnapshot,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            if($sid){
                DB::table('shipments')->where('id',$sid)->update([
                    'weighbridge_card_id'=>$id,'weight_card_number'=>$no,'flow_type'=>$flow,'updated_at'=>now(),
                ]);
                // Seed the material line immediately; weight becomes final when the card closes.
                $this->syncShipmentItemsFromCards($sid);
            }
            return $this->details($cid,$bid,$id);
        });
    }

    public function linkShipment(int $cardId,int $shipmentId,array $d): array
    {
        return DB::transaction(function()use($cardId,$shipmentId,$d){
            $cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];
            $card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();
            if(!$card)throw new \RuntimeException('كرت الميزان غير موجود.');
            $shipment=DB::table('shipments')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$shipmentId)->lockForUpdate()->first();
            if(!$shipment)throw new \RuntimeException('الشحنة غير موجودة في نفس الفرع.');
            if(($shipment->commercial_status??'DRAFT')!=='DRAFT')throw new \RuntimeException('يمكن ربط الكرت بشحنة تحت التجهيز فقط.');
            $expected=match(strtoupper((string)$shipment->shipment_type)){'SALE'=>'SALE_OUTBOUND','TRANSFER','INTERNAL'=>'INTERNAL_TRANSFER',default=>'PURCHASE_INBOUND'};
            if($card->flow_type!==$expected)throw new \RuntimeException('نوع حركة كرت الميزان لا يطابق نوع الشحنة.');
            if($card->shipment_id&&(int)$card->shipment_id!==$shipmentId)throw new \RuntimeException('الكرت مرتبط بالفعل بشحنة أخرى. صحح الارتباط بصلاحية إدارية قبل إعادة الربط.');

            $item=DB::table('items')->where('company_id',$cid)->where('id',(int)$card->item_id)->first();
            if(!$item)throw new \RuntimeException('الصنف المثبت على كرت الميزان غير موجود.');
            if($expected==='PURCHASE_INBOUND'&&!(int)($item->can_purchase??1))throw new \RuntimeException('صنف الكرت غير متاح للشراء.');
            if($expected==='SALE_OUTBOUND'&&!(int)($item->can_sell??1))throw new \RuntimeException('صنف الكرت غير متاح للبيع.');

            DB::table('weighbridge_cards')->where('id',$cardId)->update([
                'shipment_id'=>$shipmentId,'linked_at'=>now(),'linked_by'=>$d['created_by']??null,'unassigned_reason'=>null,'updated_at'=>now(),
            ]);
            DB::table('shipment_weights')->where('company_id',$cid)->where('weighbridge_card_id',$cardId)->update(['shipment_id'=>$shipmentId,'updated_at'=>now()]);
            DB::table('shipments')->where('id',$shipmentId)->update([
                'weighbridge_card_id'=>$cardId,'weight_card_number'=>$card->card_number,'flow_type'=>$card->flow_type,'updated_at'=>now(),
            ]);
            $this->aggregateShipment($shipmentId);
            $this->syncShipmentItemsFromCards($shipmentId);
            return $this->details($cid,$bid,$cardId);
        });
    }

    public function assignItem(int $cardId, array $d): array
    {
        return DB::transaction(function() use($cardId,$d){
            $cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];$itemId=(int)($d['item_id']??0);
            $card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();
            if(!$card)throw new \RuntimeException('كرت الميزان غير موجود.');
            if(strtoupper((string)$card->status)!=='OPEN')throw new \RuntimeException('لا يمكن تغيير صنف كرت ميزان مغلق. استخدم معالجة الكروت القديمة داخل الشحنة عند الحاجة.');
            if($itemId<=0)throw new \RuntimeException('حدد الصنف / المادة.');
            $item=DB::table('items')->where('company_id',$cid)->where('id',$itemId)->where('is_active',1)->first();
            if(!$item)throw new \RuntimeException('الصنف المحدد غير موجود أو غير نشط.');
            if(strtoupper((string)($item->item_type??'STOCK'))==='SERVICE')throw new \RuntimeException('الخدمات لا تُوزن على الميزان.');
            if($card->flow_type==='PURCHASE_INBOUND'&&!(int)($item->can_purchase??1))throw new \RuntimeException('الصنف غير متاح للشراء.');
            if($card->flow_type==='SALE_OUTBOUND'&&!(int)($item->can_sell??1))throw new \RuntimeException('الصنف غير متاح للبيع.');
            if($card->flow_type==='INTERNAL_TRANSFER'&&!(int)($item->track_inventory??1))throw new \RuntimeException('الحركة الداخلية تتطلب صنفًا يتبع المخزون.');
            $oldId=(int)($card->item_id??0);$reason=trim((string)($d['reason']??''));
            if($oldId>0&&$oldId!==$itemId&&mb_strlen($reason)<3)throw new \RuntimeException('اكتب سبب تصحيح الصنف حتى يبقى أثر العملية واضحًا.');
            if($oldId===$itemId)return $this->details($cid,$bid,$cardId);
            $oldName=$oldId?DB::table('items')->where('id',$oldId)->value('item_name'):null;
            $note=$oldId?('تصحيح المادة من '.($oldName?:('#'.$oldId)).' إلى '.$item->item_name.'. السبب: '.$reason):('تحديد المادة للكرت القديم: '.$item->item_name.'.');
            DB::table('weighbridge_cards')->where('id',$cardId)->update([
                'item_id'=>$itemId,'item_code_snapshot'=>$item->item_code,'item_name_snapshot'=>$item->item_name,
                'item_assigned_at'=>now(),'item_assigned_by'=>$d['created_by']??null,'item_assignment_note'=>$note,'updated_at'=>now(),
            ]);
            if($card->shipment_id)$this->syncShipmentItemsFromCards((int)$card->shipment_id);
            return $this->details($cid,$bid,$cardId);
        });
    }

    public function recordWeight(int $cardId, array $d): array
    {
        return DB::transaction(function () use ($cardId,$d) {
            $cid=(int)$d['company_id']; $bid=(int)$d['branch_id'];
            $card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();
            if(!$card) throw new \RuntimeException('كرت الميزان غير موجود.');
            if($card->status!=='OPEN') throw new \RuntimeException('كرت الميزان مغلق ولا يقبل أوزاناً جديدة.');

            $event=strtoupper(trim((string)$d['event_type']));
            if(!in_array($event,['LOADED','EMPTY','RECHECK','CORRECTION'],true)) throw new \RuntimeException('نوع قراءة الوزن غير صحيح.');
            $effective=strtoupper(trim((string)($d['effective_weight_type']??'')));
            if(in_array($event,['LOADED','EMPTY'],true)) $effective=$event;
            if(!in_array($effective,['LOADED','EMPTY'],true)) throw new \RuntimeException('حدد هل القراءة الفعالة للمحمل أم للفارغ.');
            $kg=round((float)$d['weight_kg'],3);
            if($kg<=0) throw new \RuntimeException('الوزن يجب أن يكون أكبر من صفر.');
            if($event==='CORRECTION' && mb_strlen(trim((string)($d['notes']??'')))<3) throw new \RuntimeException('سبب التصحيح مطلوب.');

            $latestActive=function(string $type) use($cardId){
                return DB::table('shipment_weights')->where('weighbridge_card_id',$cardId)->whereNull('cancelled_at')
                    ->where('effective_weight_type',$type)->orderByDesc('recorded_at')->orderByDesc('id')->first();
            };
            $previousTarget=$latestActive($effective);
            if(in_array($event,['RECHECK','CORRECTION'],true) && !$previousTarget){
                throw new \RuntimeException('لا توجد قراءة سابقة لـ '.($effective==='LOADED'?'المحمل / قبل التفريغ':'الفارغ / بعد التفريغ').' حتى يتم إعادة وزنها أو تصحيحها. سجل القراءة الأساسية أولاً.');
            }
            if(in_array($event,['LOADED','EMPTY'],true) && $previousTarget){
                throw new \RuntimeException('توجد قراءة فعالة مسبقًا لـ '.($effective==='LOADED'?'المحمل / قبل التفريغ':'الفارغ / بعد التفريغ').'. استخدم «إعادة وزن» أو «تصحيح» حتى يبقى الأثر واضحًا.');
            }

            $loadedRow=$effective==='LOADED' ? null : $latestActive('LOADED');
            $emptyRow=$effective==='EMPTY' ? null : $latestActive('EMPTY');
            $prospectiveLoaded=$effective==='LOADED' ? $kg : round((float)($loadedRow->weight_kg??0),3);
            $prospectiveEmpty=$effective==='EMPTY' ? $kg : round((float)($emptyRow->weight_kg??0),3);
            if($prospectiveLoaded>0 && $prospectiveEmpty>0 && $prospectiveLoaded<=$prospectiveEmpty){
                throw new \RuntimeException('القراءة غير منطقية: بعد الحفظ سيصبح وزن المحمل '.number_format($prospectiveLoaded,3,'.',',').' كجم ووزن الفارغ / بعد التفريغ '.number_format($prospectiveEmpty,3,'.',',').' كجم. يجب أن يكون المحمل أكبر من الفارغ. اختر القراءة المستهدفة الصحيحة أو أعد الوزن.');
            }

            $recordedAt=!empty($d['recorded_at'])?Carbon::parse($d['recorded_at']):now();
            $entryAt=Carbon::parse($card->entry_at?:$card->opened_at);
            if($recordedAt->lt($entryAt))throw new \RuntimeException('وقت الوزنة لا يمكن أن يسبق وقت دخول الحركة.');

            DB::table('shipment_weights')->insert([
                'company_id'=>$cid,'branch_id'=>$bid,'weighbridge_card_id'=>$cardId,'shipment_id'=>$card->shipment_id,'car_id'=>$card->car_id,
                'event_type'=>$event,'effective_weight_type'=>$effective,'weight_kg'=>$kg,'recorded_at'=>$recordedAt,
                'scale_name'=>$d['scale_name']??$card->scale_name,'ticket_number'=>$d['ticket_number']??null,'notes'=>$d['notes']??null,
                'created_by'=>$d['created_by']??null,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->recalculateCard($cardId);
            return $this->details($cid,$bid,$cardId);
        });
    }

    public function updateDeduction(int $cardId,array $d): array
    {
        throw new \RuntimeException('خصومات الشوائب والرطوبة لا تُسجل على كرت الميزان. الكرت يثبت الصنف والوزن الفيزيائي فقط؛ الخصم والتسعير يتمان داخل الشحنة.');
    }

    public function cancelWeight(int $weightId,array $d): array
    {
        return DB::transaction(function() use($weightId,$d){
            $reason=trim((string)($d['reason']??'')); if(mb_strlen($reason)<5) throw new \RuntimeException('سبب إلغاء القراءة مطلوب.');
            $w=DB::table('shipment_weights as w')->join('weighbridge_cards as c','c.id','=','w.weighbridge_card_id')
                ->where('w.company_id',(int)$d['company_id'])->where('w.branch_id',(int)$d['branch_id'])->where('w.id',$weightId)
                ->select('w.*','c.status as card_status')->lockForUpdate()->first();
            if(!$w) throw new \RuntimeException('قراءة الوزن غير موجودة.');
            if($w->card_status!=='OPEN') throw new \RuntimeException('لا يمكن إلغاء قراءة بعد إغلاق الكرت.');
            if($w->cancelled_at) throw new \RuntimeException('القراءة ملغاة مسبقاً.');
            DB::table('shipment_weights')->where('id',$weightId)->update([
                'cancelled_at'=>now(),'cancelled_by'=>$d['created_by']??null,'cancel_reason'=>$reason,'updated_at'=>now(),
            ]);
            $this->recalculateCard((int)$w->weighbridge_card_id);
            return $this->details((int)$d['company_id'],(int)$d['branch_id'],(int)$w->weighbridge_card_id);
        });
    }

    public function closeCard(int $cardId,array $d): array
    {
        return DB::transaction(function() use($cardId,$d){
            $cid=(int)$d['company_id'];$bid=(int)$d['branch_id'];
            $this->recalculateCard($cardId);
            $card=DB::table('weighbridge_cards')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$cardId)->lockForUpdate()->first();
            if(!$card) throw new \RuntimeException('كرت الميزان غير موجود.');
            if($card->status==='CLOSED') return $this->details($cid,$bid,$cardId);
            if(!$card->item_id)throw new \RuntimeException('حدد الصنف على كرت الميزان قبل الإغلاق.');
            if((float)$card->loaded_weight_kg<=0||(float)$card->empty_weight_kg<=0) throw new \RuntimeException('سجل وزن المحمل ووزن الفارغ/بعد التفريغ قبل إغلاق الكرت.');
            if((float)$card->loaded_weight_kg<=(float)$card->empty_weight_kg) throw new \RuntimeException('الوزن المحمل يجب أن يكون أكبر من الوزن الفارغ/بعد التفريغ.');
            if((float)$card->net_weight_kg<=0) throw new \RuntimeException('صافي وزن الكرت يجب أن يكون أكبر من صفر.');
            $exitAt=!empty($d['exit_at'])?Carbon::parse($d['exit_at']):now();
            $entryAt=Carbon::parse($card->entry_at ?: $card->opened_at);
            if($exitAt->lt($entryAt)) throw new \RuntimeException('وقت الخروج لا يمكن أن يسبق وقت الدخول.');

            $lastReading=DB::table('shipment_weights')->where('weighbridge_card_id',$cardId)->whereNull('cancelled_at')->max('recorded_at');
            if($lastReading && $exitAt->lt(Carbon::parse($lastReading)))throw new \RuntimeException('وقت الخروج لا يمكن أن يسبق آخر قراءة ميزان.');

            DB::table('weighbridge_cards')->where('id',$cardId)->update([
                'status'=>'CLOSED','closed_at'=>$exitAt,'exit_at'=>$exitAt,'duration_minutes'=>$entryAt->diffInMinutes($exitAt),
                'closed_by'=>$d['created_by']??null,'updated_at'=>now(),
            ]);
            if($card->shipment_id){
                $this->aggregateShipment((int)$card->shipment_id);
                $this->syncShipmentItemsFromCards((int)$card->shipment_id);
            }
            return $this->details($cid,$bid,$cardId);
        });
    }

    public function listCards(int $cid,?int $bid=null)
    {
        $q=DB::table('weighbridge_cards as w')
            ->leftJoin('shipments as s','s.id','=','w.shipment_id')
            ->leftJoin('items as i','i.id','=','w.item_id')
            ->leftJoin('cars as c','c.id','=','w.car_id')
            ->leftJoin('drivers as d','d.id','=','w.driver_id')
            ->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')
            ->leftJoin('customers as cu','cu.id','=','s.customer_id')
            ->leftJoin('branches as b','b.id','=','w.branch_id')
            ->where('w.company_id',$cid);
        if($bid!==null)$q->where('w.branch_id',$bid);
        return $q->select(
                'w.*','s.shipment_number','s.shipment_date','s.shipment_type','s.commercial_status as shipment_status',
                'i.item_code','i.item_name','c.car_number','c.plate_number','c.vehicle_type','c.make_name','c.model_name','c.model_year','c.owner_name','d.driver_name','sp.supplier_name','cu.customer_name','b.branch_name'
            )
            ->orderByRaw("CASE WHEN w.status='OPEN' THEN 0 ELSE 1 END")->orderByDesc('w.id')->get();
    }

    public function details(int $cid,int $bid,int $id): array
    {
        $card=DB::table('weighbridge_cards as w')
            ->leftJoin('shipments as s','s.id','=','w.shipment_id')
            ->leftJoin('items as i','i.id','=','w.item_id')
            ->leftJoin('cars as c','c.id','=','w.car_id')
            ->leftJoin('drivers as d','d.id','=','w.driver_id')
            ->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')
            ->leftJoin('customers as cu','cu.id','=','s.customer_id')
            ->leftJoin('branches as b','b.id','=','w.branch_id')
            ->where('w.company_id',$cid)->where('w.branch_id',$bid)->where('w.id',$id)
            ->select(
                'w.*','s.shipment_number','s.shipment_date','s.shipment_type','s.commercial_status as shipment_status',
                'i.item_code','i.item_name','c.car_number','c.plate_number','c.vehicle_type','c.make_name','c.model_name','c.model_year','c.owner_name','d.driver_name','sp.supplier_name','cu.customer_name','b.branch_name'
            )->first();
        if(!$card) throw new \RuntimeException('كرت الميزان غير موجود.');
        $events=DB::table('shipment_weights as w')->leftJoin('users as u','u.id','=','w.created_by')
            ->where('w.company_id',$cid)->where('w.weighbridge_card_id',$id)
            ->select('w.*','u.name as created_by_name')->orderByDesc('w.recorded_at')->orderByDesc('w.id')->get();
        return ['card'=>$card,'events'=>$events];
    }

    public function aggregateShipment(int $shipmentId): void
    {
        if($shipmentId<=0)return;
        $sum=DB::table('weighbridge_cards')->where('shipment_id',$shipmentId)->where('status','CLOSED')->selectRaw(
            'COALESCE(SUM(loaded_weight_kg),0) loaded,COALESCE(SUM(empty_weight_kg),0) empty_w,COALESCE(SUM(net_weight_kg),0) net_w'
        )->first();
        $physical=round((float)$sum->net_w,3);
        DB::table('shipments')->where('id',$shipmentId)->update([
            'total_loaded_weight_kg'=>round((float)$sum->loaded,3),'total_empty_weight_kg'=>round((float)$sum->empty_w,3),
            'total_deduction_weight_kg'=>0,'total_net_weight_kg'=>$physical,'physical_net_weight_kg'=>$physical,
            'total_gross_weight'=>round((float)$sum->loaded,3),'total_tare_weight'=>round((float)$sum->empty_w,3),
            'total_deduction_weight'=>0,'total_net_weight'=>round($physical/1000,6),'updated_at'=>now(),
        ]);
    }

    /**
     * Converts scale evidence into shipment preparation lines:
     * closed cards of the same item are summed. The accountant still owns
     * deductions, accepted qty, prices, VAT and operating costs in shipment UI.
     */
    public function syncShipmentItemsFromCards(int $shipmentId): void
    {
        if($shipmentId<=0)return;
        $shipment=DB::table('shipments')->where('id',$shipmentId)->first();
        if(!$shipment || strtoupper((string)($shipment->commercial_status??'DRAFT'))!=='DRAFT')return;
        $cid=(int)$shipment->company_id;

        $groups=DB::table('weighbridge_cards')
            ->where('company_id',$cid)->where('shipment_id',$shipmentId)->where('status','CLOSED')->whereNotNull('item_id')
            ->select('item_id',DB::raw('SUM(net_weight_kg) gross_kg'))->groupBy('item_id')->get();

        foreach($groups as $g){
            $itemId=(int)$g->item_id;$gross=round((float)$g->gross_kg,3);
            if($gross<=0)continue;
            $item=DB::table('items')->where('company_id',$cid)->where('id',$itemId)->first();
            if(!$item)continue;
            $row=DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$shipmentId)->where('item_id',$itemId)->first();
            $ded=min($gross,max(0,round((float)($row->deduction_qty_kg??0),3)));
            $accepted=max(0,round($gross-$ded,3));

            if($row){
                DB::table('shipment_items')->where('id',$row->id)->update([
                    'weighed_qty_kg'=>$gross,'gross_qty_kg'=>$gross,'gross_weight'=>round($gross/1000,6),
                    'deduction_qty_kg'=>$ded,'deduction_weight'=>round($ded/1000,6),
                    'accepted_qty_kg'=>$accepted,'qty_kg'=>$accepted,'inventory_qty_kg'=>$accepted,
                    'remaining_qty_kg'=>$accepted,'remaining_qty'=>round($accepted/1000,6),'net_weight'=>round($accepted/1000,6),
                    'updated_at'=>now(),
                ]);
            }else{
                $perKg=(float)(strtoupper((string)$shipment->shipment_type)==='SALE'?($item->default_sell_price??0):($item->default_buy_price??0));
                $sort=(int)DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$shipmentId)->max('sorting_order')+1;
                DB::table('shipment_items')->insert([
                    'company_id'=>$cid,'shipment_id'=>$shipmentId,'item_id'=>$itemId,
                    'gross_weight'=>round($gross/1000,6),'tare_weight'=>0,'deduction_weight'=>0,'net_weight'=>round($gross/1000,6),
                    'remaining_qty'=>round($gross/1000,6),'sold_qty'=>0,'qty_kg'=>$gross,'remaining_qty_kg'=>$gross,'sold_qty_kg'=>0,
                    'weighed_qty_kg'=>$gross,'gross_qty_kg'=>$gross,'deduction_qty_kg'=>0,'accepted_qty_kg'=>$gross,'inventory_qty_kg'=>$gross,
                    'price_unit'=>'KG','unit_price_per_kg'=>$perKg,'unit_price'=>round($perKg*1000,3),
                    'discount_amount'=>0,'vat_percent'=>0,'vat_amount'=>0,'total_before_vat'=>0,'total_after_vat'=>0,'line_total'=>0,
                    'exchange_rate'=>(float)($shipment->exchange_rate?:1),'base_cost'=>0,'allocated_cost'=>0,'final_unit_cost_per_kg'=>0,
                    'average_cost'=>0,'distributed_cost'=>0,'profit'=>0,'inventory_created'=>0,'sorting_order'=>$sort,'status'=>'OPEN',
                    'notes'=>'أُنشئ تلقائيًا من كرت/كروت الميزان للمادة '.$item->item_name.'. الخصم والتسعير يتمان في تجهيز الشحنة.',
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }

        $totals=DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$shipmentId)
            ->selectRaw('COALESCE(SUM(accepted_qty_kg),0) accepted, COALESCE(SUM(deduction_qty_kg),0) ded')->first();
        DB::table('shipments')->where('id',$shipmentId)->update([
            'accepted_weight_kg'=>round((float)$totals->accepted,3),'item_deduction_weight_kg'=>round((float)$totals->ded,3),'updated_at'=>now(),
        ]);
    }

    private function recalculateCard(int $id): void
    {
        $card=DB::table('weighbridge_cards')->where('id',$id)->first();if(!$card)return;
        $latest=function(string $type)use($id){
            return DB::table('shipment_weights')->where('weighbridge_card_id',$id)->whereNull('cancelled_at')->where('effective_weight_type',$type)
                ->orderByDesc('recorded_at')->orderByDesc('id')->value('weight_kg');
        };
        $loaded=round((float)($latest('LOADED')??0),3);$empty=round((float)($latest('EMPTY')??0),3);$net=max(0,round($loaded-$empty,3));
        DB::table('weighbridge_cards')->where('id',$id)->update(['loaded_weight_kg'=>$loaded,'empty_weight_kg'=>$empty,'net_weight_kg'=>$net,'updated_at'=>now()]);
        // لا نحسب الشحنة أثناء كون الكرت مفتوحًا. التجميع يتم عند الإغلاق فقط.
    }

    private function nextUniqueWeighbridgeNumber(int $companyId, Carbon $date): string
    {
        // uq_weighbridge_card_number is company-wide, so use branch_id=0 for this document sequence.
        for($i=0;$i<50;$i++){
            $no=$this->sequences->next($companyId,0,'WEIGHBRIDGE_CARD',$date->format('Y-m-d'),'WB');
            if(!DB::table('weighbridge_cards')->where('company_id',$companyId)->where('card_number',$no)->exists())return $no;
        }
        throw new \RuntimeException('تعذر توليد رقم كرت ميزان فريد. شغّل Migration Stage 9 لمزامنة تسلسل الأرقام.');
    }
}
