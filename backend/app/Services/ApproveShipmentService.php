<?php

namespace App\Services;

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class ApproveShipmentService
{
    public function __construct(
        private FinancialAccountService $money,
    ) {}

    /**
     * اعتماد الشحنة في Stage 6 يعني:
     * - تثبيت الوزن المقبول وتوزيع الأصناف.
     * - تجهيز التكلفة الأساسية.
     * - جعل الشحنة جاهزة للفوترة.
     * لا ينشئ فاتورة شراء ولا مخزون ولا قيداً محاسبياً.
     * أثر المخزون والمحاسبة يحدث عند ترحيل فاتورة المشتريات.
     */
    public function approve(Shipment $shipment,int $uid): array
    {
        return DB::transaction(function()use($shipment,$uid){
            $shipment=Shipment::where('id',$shipment->id)->lockForUpdate()->firstOrFail();
            if($shipment->status!=='DRAFT') throw new \RuntimeException('لا يمكن اعتماد شحنة ليست مسودة.');

            $card=DB::table('weighbridge_cards')
                ->where('company_id',$shipment->company_id)
                ->where('branch_id',$shipment->branch_id)
                ->where('shipment_id',$shipment->id)
                ->first();

            if(!$card || $card->status!=='CLOSED')
                throw new \RuntimeException('يجب إكمال وإغلاق كرت الميزان قبل اعتماد الشحنة.');

            $netKg=round((float)$card->net_weight_kg,3);
            if($netKg<=0) throw new \RuntimeException('صافي وزن كرت الميزان غير صحيح.');

            $items=DB::table('shipment_items')
                ->where('company_id',$shipment->company_id)
                ->where('shipment_id',$shipment->id)
                ->orderBy('sorting_order')
                ->lockForUpdate()
                ->get();

            if($items->isEmpty())
                throw new \RuntimeException('وزّع صافي الشحنة على صنف واحد على الأقل.');

            // الوزن الموزع يطابق صافي كرت الميزان قبل خصومات الأصناف.
            $distributedWeighed=round((float)$items->sum(fn($x)=>(float)($x->weighed_qty_kg ?: $x->qty_kg)),3);
            if(abs($distributedWeighed-$netKg)>1.0){
                throw new \RuntimeException(
                    'مجموع الوزن الموزع على الأصناف لا يطابق صافي كرت الميزان. '.
                    'صافي الكرت: '.number_format($netKg,3).' كجم، الموزع: '.number_format($distributedWeighed,3).' كجم.'
                );
            }

            $currency=strtoupper((string)($shipment->currency_code ?: $this->money->baseCurrency((int)$shipment->company_id)));
            $rate=(float)($shipment->exchange_rate ?: $this->money->rate((int)$shipment->company_id,$currency,$shipment->shipment_date));
            if($rate<=0) throw new \RuntimeException('سعر صرف الشحنة غير صالح.');

            $subtotalGross=0.0;$discount=0.0;$before=0.0;$vat=0.0;$total=0.0;
            $baseBefore=0.0;$baseVat=0.0;$baseTotal=0.0;$acceptedKg=0.0;

            foreach($items as $it){
                $kg=round((float)$it->qty_kg,3);
                $weighed=round((float)($it->weighed_qty_kg ?: $kg),3);
                $itemDed=round((float)($it->item_deduction_qty_kg ?? 0),3);
                if($kg<=0) throw new \RuntimeException('أحد أصناف الشحنة لا يملك كمية مقبولة.');
                if(abs(($weighed-$itemDed)-$kg)>0.01)
                    throw new \RuntimeException('الكمية المقبولة لأحد الأصناف لا تطابق الوزن الموزع ناقص خصم الصنف.');

                $ton=$kg/1000;
                $lineBaseBefore=isset($it->base_total_before_vat)
                    ? (float)$it->base_total_before_vat
                    : round((float)$it->total_before_vat*$rate,3);
                $lineBaseVat=isset($it->base_vat_amount)
                    ? (float)$it->base_vat_amount
                    : round((float)$it->vat_amount*$rate,3);
                $lineBaseAfter=isset($it->base_total_after_vat)
                    ? (float)$it->base_total_after_vat
                    : round((float)$it->total_after_vat*$rate,3);

                DB::table('shipment_items')->where('id',$it->id)->update([
                    'net_weight'=>round($ton,6),
                    'remaining_qty'=>round($ton,6),
                    'sold_qty'=>0,
                    'remaining_qty_kg'=>$kg,
                    'sold_qty_kg'=>0,
                    'base_cost'=>round($lineBaseBefore,3),
                    'final_cost'=>round($lineBaseBefore+(float)($it->allocated_cost??0),3),
                    'final_unit_cost_per_kg'=>$kg>0
                        ? round(($lineBaseBefore+(float)($it->allocated_cost??0))/$kg,6)
                        : 0,
                    'average_cost'=>$ton>0
                        ? round(($lineBaseBefore+(float)($it->allocated_cost??0))/$ton,3)
                        : 0,
                    'currency_code'=>$currency,
                    'exchange_rate'=>$rate,
                    'base_total_before_vat'=>round($lineBaseBefore,3),
                    'base_vat_amount'=>round($lineBaseVat,3),
                    'base_total_after_vat'=>round($lineBaseAfter,3),
                    'updated_at'=>now(),
                ]);

                $subtotalGross+=round($ton*(float)$it->unit_price,3);
                $discount+=(float)$it->discount_amount;
                $before+=(float)$it->total_before_vat;
                $vat+=(float)$it->vat_amount;
                $total+=(float)$it->total_after_vat;
                $baseBefore+=$lineBaseBefore;
                $baseVat+=$lineBaseVat;
                $baseTotal+=$lineBaseAfter;
                $acceptedKg+=$kg;
            }

            DB::table('shipments')->where('id',$shipment->id)->update([
                'status'=>'APPROVED',
                'invoice_status'=>'UNINVOICED',
                'ready_for_invoice_at'=>now(),
                'currency_code'=>$currency,
                'exchange_rate'=>$rate,
                'total_loaded_weight_kg'=>(float)$card->loaded_weight_kg,
                'total_empty_weight_kg'=>(float)$card->empty_weight_kg,
                'total_deduction_weight_kg'=>(float)$card->deduction_weight_kg,
                'total_net_weight_kg'=>$netKg,
                'total_net_weight'=>round($acceptedKg/1000,6),
                'total_before_discount'=>round($subtotalGross,3),
                'discount_amount'=>round($discount,3),
                'vat_amount'=>round($vat,3),
                'total_amount'=>round($total,3),
                'base_total_before_vat'=>round($baseBefore,3),
                'base_vat_amount'=>round($baseVat,3),
                'base_total_amount'=>round($baseTotal,3),
                'approved_at'=>now(),
                'approved_by'=>$uid,
                'costing_status'=>'BASE_COSTED',
                'updated_at'=>now(),
            ]);

            return [
                'shipment_id'=>$shipment->id,
                'shipment_number'=>$shipment->shipment_number,
                'status'=>'APPROVED',
                'invoice_status'=>'UNINVOICED',
                'weighbridge_net_kg'=>$netKg,
                'accepted_qty_kg'=>round($acceptedKg,3),
                'message'=>'الشحنة جاهزة لإضافة التكاليف ثم ضمها إلى فاتورة مشتريات واحدة أو مع شحنات أخرى.',
            ];
        });
    }
}
