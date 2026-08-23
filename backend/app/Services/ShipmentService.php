<?php

namespace App\Services;

use App\Services\Accounting\AccountingContext;
use Illuminate\Support\Facades\DB;

class ShipmentService
{
    public function __construct(
        private PartyBranchScopeService $parties,
        private TaxEngineService $taxes,
        private FinancialAccountService $money,
        private WeighbridgeService $weighbridge,
        private ShipmentWeighbridgeAllocationService $weighbridgeAllocations,
        private DefaultPartyService $defaultParties,
        private SulbDocumentSequenceService $sequences,
    ) {}

    public function save(array $d, int $cid, int $bid, int $uid, ?int $shipmentId = null): int
    {
        return DB::transaction(function () use ($d,$cid,$bid,$uid,$shipmentId) {
            $old = $shipmentId
                ? DB::table('shipments')->where('company_id',$cid)->where('id',$shipmentId)->lockForUpdate()->first()
                : null;
            if ($shipmentId && !$old) throw new \RuntimeException('الشحنة غير موجودة.');
            if ($old && ($old->commercial_status ?? 'DRAFT') !== 'DRAFT') {
                throw new \RuntimeException('لا يمكن تعديل شحنة جاهزة أو مفوترة. أعد فتحها أولاً إن كانت غير مفوترة.');
            }

            $type = strtoupper(trim((string)($d['shipment_type'] ?? ($old->shipment_type ?? 'PURCHASE'))));
            if (!in_array($type,['PURCHASE','SALE','TRANSFER','INTERNAL'],true)) {
                throw new \RuntimeException('نوع الشحنة غير صحيح.');
            }

            $supplierId = isset($d['supplier_id']) && (int)$d['supplier_id']>0 ? (int)$d['supplier_id'] : null;
            $customerId = isset($d['customer_id']) && (int)$d['customer_id']>0 ? (int)$d['customer_id'] : null;
            if ($type === 'PURCHASE') {
                if ($supplierId) $this->parties->assertAccessible($cid,'SUPPLIER',$supplierId,$bid);
                $customerId = null;
            } elseif ($type === 'SALE') {
                if ($customerId) $this->parties->assertAccessible($cid,'CUSTOMER',$customerId,$bid);
                $supplierId = null;
            }

            $carId = isset($d['car_id']) && (int)$d['car_id']>0 ? (int)$d['car_id'] : null;
            $driverId = isset($d['driver_id']) && (int)$d['driver_id']>0 ? (int)$d['driver_id'] : null;
            $car = null; $driver = null;
            if ($carId) {
                $car = DB::table('cars')->where('company_id',$cid)->where('id',$carId)->where('is_active',1)->first();
                if (!$car) throw new \RuntimeException('السيارة الافتراضية غير موجودة أو غير نشطة.');
                // السيارة في الشحنة مرنة: قد تكون للمورد أو مستأجرة/بديلة/طرف ثالث.
                // علاقة السيارة بالطرف تستخدم للترتيب والاقتراح في الواجهة فقط، وليست قيدًا على الحفظ.
                if (!$driverId && !empty($car->driver_id)) $driverId=(int)$car->driver_id;
            }
            if ($driverId) {
                $driver=DB::table('drivers')->where('company_id',$cid)->where('id',$driverId)->where('is_active',1)->first();
                if (!$driver) throw new \RuntimeException('السائق غير موجود أو غير نشط.');
            }

            $date=(string)($d['shipment_date'] ?? ($old->shipment_date ?? date('Y-m-d')));
            $currency=strtoupper(trim((string)($d['currency_code'] ?? ($old->currency_code ?? $this->money->baseCurrency($cid)))));
            $rate=isset($d['exchange_rate']) && $d['exchange_rate']!==''
                ? (float)$d['exchange_rate']
                : $this->money->rate($cid,$currency,$date);
            if($rate<=0) throw new \RuntimeException('سعر الصرف يجب أن يكون أكبر من صفر.');

            $number=$old?->shipment_number ?: $this->sequences->next($cid,$bid,'SHIPMENT',$date,'SHP');
            $header=[
                'company_id'=>$cid,'branch_id'=>$bid,'shipment_number'=>$number,'shipment_date'=>$date,
                'shipment_type'=>$type,'supplier_id'=>$supplierId,'customer_id'=>$customerId,'car_id'=>$carId,'driver_id'=>$driverId,
                'plate_number'=>$car?->plate_number,'flow_type'=>$type==='SALE'?'SALE_OUTBOUND':($type==='PURCHASE'?'PURCHASE_INBOUND':'INTERNAL_TRANSFER'),
                'currency_code'=>$currency,'exchange_rate'=>$rate,'cost_allocation_method'=>strtoupper((string)($d['cost_allocation_method']??($old->cost_allocation_method??'WEIGHT'))),
                'commercial_status'=>'DRAFT','status'=>'DRAFT','notes'=>$d['notes']??null,'updated_at'=>now(),
            ];
            if($shipmentId){
                DB::table('shipments')->where('id',$shipmentId)->update($header);
                $sid=$shipmentId;
            }else{
                $header['created_by']=$uid;$header['created_at']=now();
                $sid=DB::table('shipments')->insertGetId($header);
            }

            if(array_key_exists('items',$d)) $this->saveItems($sid,$cid,$type,$date,$currency,$rate,$d['items']??[]);
            $this->recalculateShipment($sid,$cid);
            return $sid;
        });
    }

    public function ready(int $cid,int $sid,int $uid,?int $branchFilter=null): array
    {
        return DB::transaction(function()use($cid,$sid,$uid,$branchFilter){
            $s=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();
            if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');
            if($branchFilter!==null&&(int)$s->branch_id!==$branchFilter)throw new \RuntimeException('الشحنة خارج نطاق فرعك.');
            if(($s->commercial_status??'DRAFT')==='INVOICED')throw new \RuntimeException('الشحنة مفوترة ولا يمكن تغيير حالتها.');

            $open=DB::table('weighbridge_cards')->where('company_id',$cid)->where('shipment_id',$sid)->where('status','OPEN')->count();
            $closed=DB::table('weighbridge_cards')->where('company_id',$cid)->where('shipment_id',$sid)->where('status','CLOSED')->count();
            if($open>0)throw new \RuntimeException('يوجد كرت ميزان مفتوح. أغلق جميع كروت الشحنة أولاً.');
            if($closed<1)throw new \RuntimeException('الشحنة تحتاج كرت ميزان مغلق واحدًا على الأقل.');
            $this->weighbridge->aggregateShipment($sid);
            $this->recalculateShipment($sid,$cid);
            $s=DB::table('shipments')->where('id',$sid)->first();
            $count=DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->count();
            if($count<1)throw new \RuntimeException('وزّع وزن الشحنة على صنف واحد على الأقل.');
            $tol=(float)(DB::table('company_settings')->where('company_id',$cid)->value('shipment_item_tolerance_kg')??5);
            $physical=round((float)($s->physical_net_weight_kg??0),3);
            $gross=round((float)DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->sum('gross_qty_kg'),3);
            $diff=round($gross-$physical,3);
            if(abs($diff)>$tol)throw new \RuntimeException('مجموع الوزن الموزع على الأصناف لا يطابق صافي كروت الميزان. الفرق '.number_format($diff,3).' كجم، والسماحية '.number_format($tol,3).' كجم.');
            $accepted=round((float)DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->sum('accepted_qty_kg'),3);
            if($accepted<=0)throw new \RuntimeException('الكمية المقبولة للشحنة يجب أن تكون أكبر من صفر.');
            $partyDefaults=$this->defaultParties->ensure($cid,$uid);
            if(strtoupper((string)$s->shipment_type)==='PURCHASE'&&!$s->supplier_id){DB::table('shipments')->where('id',$sid)->update(['supplier_id'=>$partyDefaults['supplier']['id'],'updated_at'=>now()]);$s=DB::table('shipments')->where('id',$sid)->first();}
            if(strtoupper((string)$s->shipment_type)==='SALE'&&!$s->customer_id){DB::table('shipments')->where('id',$sid)->update(['customer_id'=>$partyDefaults['customer']['id'],'updated_at'=>now()]);$s=DB::table('shipments')->where('id',$sid)->first();}
            $this->weighbridgeAllocations->assertReady($cid,$sid);
            DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'READY','status'=>'READY','ready_at'=>now(),'ready_by'=>$uid,'weight_variance_kg'=>$diff,'updated_at'=>now()]);
            return $this->details($cid,$sid,$branchFilter);
        });
    }

    public function reopen(int $cid,int $sid,?int $branchFilter=null): array
    {
        return DB::transaction(function()use($cid,$sid,$branchFilter){
            $s=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();
            if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');
            if($branchFilter!==null&&(int)$s->branch_id!==$branchFilter)throw new \RuntimeException('الشحنة خارج نطاق فرعك.');
            if(($s->commercial_status??'DRAFT')==='INVOICED'||DB::table('invoice_shipment_links')->where('company_id',$cid)->where('shipment_id',$sid)->exists())throw new \RuntimeException('لا يمكن إعادة فتح شحنة مرتبطة بفاتورة.');
            DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'DRAFT','status'=>'DRAFT','ready_at'=>null,'ready_by'=>null,'updated_at'=>now()]);
            return $this->details($cid,$sid,$branchFilter);
        });
    }

    public function deleteDraft(int $cid,int $sid,?int $branchFilter=null): void
    {
        DB::transaction(function()use($cid,$sid,$branchFilter){
            $s=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();
            if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');
            if($branchFilter!==null&&(int)$s->branch_id!==$branchFilter)throw new \RuntimeException('الشحنة خارج نطاق فرعك.');
            if(($s->commercial_status??'DRAFT')!=='DRAFT')throw new \RuntimeException('يمكن حذف الشحنة المسودة فقط.');
            if(DB::table('weighbridge_cards')->where('company_id',$cid)->where('shipment_id',$sid)->exists())throw new \RuntimeException('لا نحذف شحنة لها كروت ميزان حفاظًا على سجل التدقيق. يمكنك إبقاؤها مسودة أو إلغاء قراءات الكروت حسب الصلاحية.');
            if(DB::table('shipment_costs')->where('company_id',$cid)->where('shipment_id',$sid)->where('cost_status','POSTED')->exists())throw new \RuntimeException('للشحنة تكاليف مرحلة ولا يمكن حذفها.');
            DB::table('shipment_costs')->where('company_id',$cid)->where('shipment_id',$sid)->delete();
            DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->delete();
            DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->delete();
        });
    }

    public function list(int $cid,?int $branchFilter=null,?string $type=null)
    {
        $q=DB::table('shipments as s')->leftJoin('branches as b','b.id','=','s.branch_id')->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')->leftJoin('customers as cu','cu.id','=','s.customer_id')->leftJoin('cars as c','c.id','=','s.car_id')->where('s.company_id',$cid);
        if($branchFilter!==null)$q->where('s.branch_id',$branchFilter);
        if($type)$q->where('s.shipment_type',strtoupper($type));
        return $q->select('s.*','b.branch_name','sp.supplier_name','cu.customer_name','c.car_number','c.plate_number',
            DB::raw('(SELECT COUNT(*) FROM weighbridge_cards w WHERE w.company_id=s.company_id AND w.shipment_id=s.id) weighbridge_cards_count'),
            DB::raw("(SELECT COUNT(*) FROM weighbridge_cards w WHERE w.company_id=s.company_id AND w.shipment_id=s.id AND w.status='OPEN') open_cards_count"),
            DB::raw('(SELECT COALESCE(SUM(sc.amount),0) FROM shipment_costs sc WHERE sc.company_id=s.company_id AND sc.shipment_id=s.id) shipment_cost_total'),
            DB::raw('(SELECT COUNT(*) FROM invoice_shipment_links il WHERE il.company_id=s.company_id AND il.shipment_id=s.id) invoice_links_count')
        )->orderByDesc('s.shipment_date')->orderByDesc('s.id')->get();
    }

    public function details(int $cid,int $sid,?int $branchFilter=null): ?array
    {
        $q=DB::table('shipments as s')->leftJoin('branches as b','b.id','=','s.branch_id')->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')->leftJoin('customers as cu','cu.id','=','s.customer_id')->leftJoin('cars as c','c.id','=','s.car_id')->leftJoin('drivers as d','d.id','=','s.driver_id')->where('s.company_id',$cid)->where('s.id',$sid);
        if($branchFilter!==null)$q->where('s.branch_id',$branchFilter);
        $s=$q->select('s.*','b.branch_name','sp.supplier_name','cu.customer_name','c.car_number','c.plate_number','d.driver_name')->first();
        if(!$s)return null;
        $items=DB::table('shipment_items as si')->join('items as i','i.id','=','si.item_id')->leftJoin('tax_codes as t','t.id','=','si.tax_code_id')->where('si.company_id',$cid)->where('si.shipment_id',$sid)->select('si.*','i.item_code','i.item_name','i.item_type','i.track_inventory','t.tax_code','t.tax_name')->orderBy('si.sorting_order')->orderBy('si.id')->get();
        $cards=DB::table('weighbridge_cards as w')->leftJoin('items as wi','wi.id','=','w.item_id')->leftJoin('cars as c','c.id','=','w.car_id')->leftJoin('drivers as d','d.id','=','w.driver_id')->where('w.company_id',$cid)->where('w.shipment_id',$sid)->select('w.*','wi.item_code as weighed_item_code','wi.item_name as weighed_item_name','c.car_number','c.plate_number','d.driver_name')->orderBy('w.entry_at')->orderBy('w.id')->get();
        $costs=DB::table('shipment_costs as sc')->leftJoin('expense_types as et','et.id','=','sc.expense_type_id')->leftJoin('financial_accounts as fa','fa.id','=','sc.financial_account_id')->where('sc.company_id',$cid)->where('sc.shipment_id',$sid)->select('sc.*','et.type_name','fa.account_name as financial_account_name')->orderBy('sc.expense_date')->orderBy('sc.id')->get();
        $links=DB::table('invoice_shipment_links')->where('company_id',$cid)->where('shipment_id',$sid)->orderBy('id')->get();
        return ['shipment'=>$s,'items'=>$items,'cards'=>$cards,'weighbridge_allocations'=>$this->weighbridgeAllocations->summary($cid,$sid),'costs'=>$costs,'invoice_links'=>$links];
    }

    public function recalculateShipment(int $sid,int $cid): void
    {
        $s=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->first();if(!$s)return;
        $rows=DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->get();
        $gross=0;$ded=0;$accepted=0;$before=0;$vat=0;$after=0;$taxLines=[];
        foreach($rows as$r){$gross+=(float)($r->gross_qty_kg??0);$ded+=(float)($r->deduction_qty_kg??0);$accepted+=(float)($r->accepted_qty_kg??0);$before+=(float)($r->total_before_vat??0);$vat+=(float)($r->vat_amount??0);$after+=(float)($r->total_after_vat??0);$taxLines[]=['tax_code_id'=>$r->tax_code_id??null,'tax_code_snapshot'=>$r->tax_code_snapshot??null,'tax_name_snapshot'=>$r->tax_name_snapshot??null,'tax_rate_snapshot'=>(float)($r->tax_rate_snapshot??0),'vat_amount'=>(float)($r->vat_amount??0),'total_before_vat'=>(float)($r->total_before_vat??0),'total_after_vat'=>(float)($r->total_after_vat??0)];}
        $rate=(float)($s->exchange_rate?:1);$physical=(float)($s->physical_net_weight_kg??0);
        DB::table('shipments')->where('id',$sid)->update([
            'accepted_weight_kg'=>round($accepted,3),'item_deduction_weight_kg'=>round($ded,3),'weight_variance_kg'=>round($gross-$physical,3),
            'total_before_discount'=>round($before,3),'discount_amount'=>round((float)DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->sum('discount_amount'),3),
            'vat_amount'=>round($vat,3),'total_amount'=>round($after,3),'base_total_before_vat'=>round($before*$rate,3),'base_vat_amount'=>round($vat*$rate,3),'base_total_amount'=>round($after*$rate,3),
            'tax_summary_json'=>json_encode($this->taxes->summary($taxLines),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>now(),
        ]);
    }

    private function saveItems(int $sid,int $cid,string $type,string $date,string $currency,float $rate,array $items): void
    {
        DB::table('shipment_items')->where('company_id',$cid)->where('shipment_id',$sid)->delete();
        foreach($items as$i=>$r){
            $itemId=(int)($r['item_id']??0);$gross=round((float)($r['gross_qty_kg']??$r['qty_kg']??0),3);$ded=round((float)($r['deduction_qty_kg']??0),3);$accepted=round((float)($r['accepted_qty_kg']??max(0,$gross-$ded)),3);
            if(!$itemId||$gross<=0)continue;
            if($ded<0||$accepted<0||abs(($gross-$ded)-$accepted)>0.011)throw new \RuntimeException('السطر '.($i+1).': الكمية المقبولة يجب أن تساوي الموزع ناقص الخصم.');
            $item=DB::table('items')->where('company_id',$cid)->where('id',$itemId)->where('is_active',1)->first();if(!$item)throw new \RuntimeException('الصنف في السطر '.($i+1).' غير صالح.');
            if($type==='PURCHASE'&&!($item->can_purchase??1))throw new \RuntimeException('الصنف '.$item->item_name.' غير متاح للشراء.');
            if($type==='SALE'&&!($item->can_sell??1))throw new \RuntimeException('الصنف '.$item->item_name.' غير متاح للبيع.');
            $priceUnit=strtoupper((string)($r['price_unit']??'KG'));if(!in_array($priceUnit,['KG','TON'],true))$priceUnit='KG';
            $entered=round((float)($r['unit_price']??$r['unit_price_per_kg']??0),6);$perKg=$priceUnit==='TON'?round($entered/1000,6):$entered;$legacyTon=round($perKg*1000,3);
            $disc=round((float)($r['discount_amount']??0),3);$grossValue=round($accepted*$perKg,3);
            $tax=$this->taxes->line($cid,$grossValue,$disc,isset($r['tax_code_id'])&&$r['tax_code_id']!==''?(int)$r['tax_code_id']:null,$date,$type==='SALE'?'SALES':'PURCHASE',isset($r['vat_percent'])?(float)$r['vat_percent']:null);
            $baseBefore=round((float)$tax['total_before_vat']*$rate,3);$baseVat=round((float)$tax['vat_amount']*$rate,3);$baseAfter=round((float)$tax['total_after_vat']*$rate,3);
            DB::table('shipment_items')->insert([
                'company_id'=>$cid,'shipment_id'=>$sid,'item_id'=>$itemId,'gross_weight'=>0,'tare_weight'=>0,'deduction_weight'=>round($ded/1000,6),'net_weight'=>round($accepted/1000,6),
                'remaining_qty'=>round($accepted/1000,6),'sold_qty'=>0,'qty_kg'=>$accepted,'remaining_qty_kg'=>$accepted,'sold_qty_kg'=>0,
                'gross_qty_kg'=>$gross,'deduction_qty_kg'=>$ded,'accepted_qty_kg'=>$accepted,'inventory_qty_kg'=>$accepted,'deduction_reason'=>$r['deduction_reason']??null,
                'price_unit'=>$priceUnit,'unit_price_per_kg'=>$perKg,'unit_price'=>$legacyTon,'discount_amount'=>$disc,'vat_percent'=>$tax['tax_rate_snapshot'],'vat_amount'=>$tax['vat_amount'],'total_before_vat'=>$tax['total_before_vat'],'total_after_vat'=>$tax['total_after_vat'],'line_total'=>$tax['line_total'],
                'tax_code_id'=>$tax['tax_code_id'],'tax_code_snapshot'=>$tax['tax_code_snapshot'],'tax_name_snapshot'=>$tax['tax_name_snapshot'],'tax_rate_snapshot'=>$tax['tax_rate_snapshot'],'currency_code'=>$currency,'exchange_rate'=>$rate,
                'base_total_before_vat'=>$baseBefore,'base_vat_amount'=>$baseVat,'base_total_after_vat'=>$baseAfter,'base_cost'=>$baseBefore,'allocated_cost'=>0,'final_unit_cost_per_kg'=>$accepted>0?round($baseBefore/$accepted,6):0,
                'cost_share_percent'=>$r['cost_share_percent']??null,'manual_allocated_cost'=>$r['manual_allocated_cost']??null,'average_cost'=>$accepted>0?round($baseBefore/($accepted/1000),3):0,'distributed_cost'=>0,'profit'=>0,'inventory_created'=>0,'sorting_order'=>$i+1,'status'=>'OPEN','notes'=>$r['notes']??null,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }

} 
