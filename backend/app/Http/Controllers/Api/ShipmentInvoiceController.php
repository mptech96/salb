<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\Accounting\AccountingEngine;
use App\Services\DocumentNumberService;
use App\Services\EntityAddressService;
use App\Services\InventoryLotService;
use App\Services\TaxEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentInvoiceController extends Controller
{
    public function eligible(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);
        $q=DB::table('shipments as s')
            ->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')
            ->leftJoin('branches as b','b.id','=','s.branch_id')
            ->leftJoin('cars as c','c.id','=','s.car_id')
            ->where('s.company_id',$cid)
            ->where('s.status','APPROVED')
            ->where(function($x){
                $x->whereNull('s.invoice_status')->orWhere('s.invoice_status','!=','INVOICED');
            });
        if($bid!==null)$q->where('s.branch_id',$bid);

        $rows=$q->select(
            's.*','sp.supplier_name','b.branch_name','c.plate_number','c.car_number',
            DB::raw('(SELECT COALESCE(SUM(si.qty_kg-si.invoiced_qty_kg),0) FROM shipment_items si WHERE si.shipment_id=s.id) available_qty_kg'),
            DB::raw('(SELECT COALESCE(SUM(si.base_cost),0) FROM shipment_items si WHERE si.shipment_id=s.id) supplier_base_cost'),
            DB::raw('(SELECT COALESCE(SUM(si.allocated_cost),0) FROM shipment_items si WHERE si.shipment_id=s.id) shipment_capitalized_cost')
        )->orderBy('s.supplier_id')->orderBy('s.branch_id')->orderBy('s.shipment_date')->get();

        return response()->json(['status'=>true,'data'=>$rows]);
    }

    public function create(Request $r,AccountingContext $ctx,AccountingEngine $accounting,InventoryLotService $lots,EntityAddressService $addresses,TaxEngineService $taxes,DocumentNumberService $numbers)
    {
        $v=$r->validate([
            'shipment_ids'=>'required|array|min:1',
            'shipment_ids.*'=>'required|integer',
            'invoice_date'=>'nullable|date',
            'invoice_number'=>'nullable|string|max:100',
            'document_type'=>'nullable|string|max:40',
            'notes'=>'nullable|string',
        ]);

        $cid=$ctx->companyId($r);$uid=(int)$ctx->userId($r);$bf=$ctx->branchFilter($r);
        $ids=array_values(array_unique(array_map('intval',$v['shipment_ids'])));

        try {
            $result=DB::transaction(function()use($v,$cid,$uid,$bf,$ids,$accounting,$lots,$addresses,$taxes,$numbers){
                $shipments=DB::table('shipments')->where('company_id',$cid)->whereIn('id',$ids)
                    ->orderBy('id')->lockForUpdate()->get();
                if($shipments->count()!==count($ids)) throw new \RuntimeException('إحدى الشحنات غير موجودة.');
                if($shipments->contains(fn($s)=>$s->status!=='APPROVED')) throw new \RuntimeException('كل الشحنات المختارة يجب أن تكون معتمدة وجاهزة للفوترة.');
                if($shipments->contains(fn($s)=>($s->invoice_status??'UNINVOICED')==='INVOICED')) throw new \RuntimeException('إحدى الشحنات مفوترة بالكامل مسبقاً.');

                $first=$shipments->first();
                $branchId=(int)$first->branch_id;$supplierId=(int)$first->supplier_id;
                $currency=strtoupper((string)($first->currency_code?:''));
                $rate=(float)($first->exchange_rate?:1);

                if($bf!==null && $branchId!==$bf) throw new \RuntimeException('الشحنات خارج نطاق فرعك.');
                foreach($shipments as $s){
                    if((int)$s->branch_id!==$branchId) throw new \RuntimeException('اجمع في فاتورة واحدة شحنات من نفس الفرع.');
                    if((int)$s->supplier_id!==$supplierId) throw new \RuntimeException('فاتورة المورد الواحدة لا تجمع شحنات موردين مختلفين.');
                    if(strtoupper((string)($s->currency_code?:''))!==$currency) throw new \RuntimeException('الشحنات المختارة يجب أن تكون بنفس العملة.');
                    if(abs((float)($s->exchange_rate?:1)-$rate)>0.0000001) throw new \RuntimeException('الشحنات المختارة يجب أن تستخدم نفس سعر الصرف لهذه الفاتورة.');
                }

                $invoiceDate=$v['invoice_date']??date('Y-m-d');
                $docType=strtoupper((string)($v['document_type']??'TAX_INVOICE'));
                $settings=DB::table('company_settings')->where('company_id',$cid)->first();
                $manualNo=trim((string)($v['invoice_number']??''));
                if($manualNo!==''){
                    $numbers->assertManualUnique($cid,'purchase_invoices',$manualNo,null);
                    $invoiceNo=$manualNo;
                }else{
                    $invoiceNo=$numbers->next($cid,$branchId,'PURCHASE',$docType,$invoiceDate,$settings->purchase_prefix??null);
                }

                $sourceItems=DB::table('shipment_items as si')
                    ->join('shipments as s','s.id','=','si.shipment_id')
                    ->where('si.company_id',$cid)->whereIn('si.shipment_id',$ids)
                    ->select('si.*','s.car_id','s.shipment_number')
                    ->orderBy('si.shipment_id')->orderBy('si.sorting_order')->lockForUpdate()->get();

                if($sourceItems->isEmpty()) throw new \RuntimeException('الشحنات لا تحتوي أصنافاً جاهزة للفوترة.');

                $prepared=[];$qtyTon=0.0;$before=0.0;$vat=0.0;$total=0.0;$baseBefore=0.0;$baseVat=0.0;$baseTotal=0.0;$taxLines=[];
                foreach($sourceItems as $it){
                    $available=round((float)$it->qty_kg-(float)($it->invoiced_qty_kg??0),3);
                    if($available<=0) continue;
                    $ratio=(float)$it->qty_kg>0?$available/(float)$it->qty_kg:0;
                    $lineBefore=round((float)$it->total_before_vat*$ratio,3);
                    $lineVat=round((float)$it->vat_amount*$ratio,3);
                    $lineAfter=round((float)$it->total_after_vat*$ratio,3);
                    $lineBaseBefore=round((float)($it->base_total_before_vat??$it->base_cost)*$ratio,3);
                    $lineBaseVat=round((float)($it->base_vat_amount??0)*$ratio,3);
                    $lineBaseAfter=round((float)($it->base_total_after_vat??($lineBaseBefore+$lineBaseVat))*$ratio,3);
                    $capitalizedExtra=round((float)($it->allocated_cost??0)*$ratio,3);
                    $prepared[]=[
                        'source'=>$it,'qty_kg'=>$available,'ratio'=>$ratio,
                        'before'=>$lineBefore,'vat'=>$lineVat,'after'=>$lineAfter,
                        'base_before'=>$lineBaseBefore,'base_vat'=>$lineBaseVat,'base_after'=>$lineBaseAfter,
                        'capitalized_extra'=>$capitalizedExtra,
                    ];
                    $qtyTon+=round($available/1000,6);$before+=$lineBefore;$vat+=$lineVat;$total+=$lineAfter;
                    $baseBefore+=$lineBaseBefore;$baseVat+=$lineBaseVat;$baseTotal+=$lineBaseAfter;
                    $taxLines[]=[
                        'tax_code_snapshot'=>$it->tax_code_snapshot??'OUT_SCOPE',
                        'tax_name_snapshot'=>$it->tax_name_snapshot??'خارج النطاق',
                        'tax_rate_snapshot'=>(float)($it->tax_rate_snapshot??$it->vat_percent??0),
                        'total_before_vat'=>$lineBefore,'vat_amount'=>$lineVat
                    ];
                }
                if(!$prepared) throw new \RuntimeException('لا توجد كميات متبقية قابلة للفوترة.');

                $seller=json_encode($addresses->snapshotParty($cid,'SUPPLIER',$supplierId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $buyer=json_encode($addresses->snapshotCompanyAndBranch($cid,$branchId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $taxSummary=json_encode($taxes->summary($taxLines),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

                $invoiceId=DB::table('purchase_invoices')->insertGetId([
                    'company_id'=>$cid,'branch_id'=>$branchId,'supplier_id'=>$supplierId,'car_id'=>null,
                    'invoice_number'=>$invoiceNo,'invoice_date'=>$invoiceDate,'document_type'=>$docType,
                    'currency_code'=>$currency,'exchange_rate'=>$rate,'seller_snapshot_json'=>$seller,'buyer_snapshot_json'=>$buyer,'tax_summary_json'=>$taxSummary,
                    'total_qty'=>round($qtyTon,6),'total_before_discount'=>round($before,3),'discount_amount'=>0,
                    'vat_amount'=>round($vat,3),'total_before_vat'=>round($before,3),'total_after_vat'=>round($total,3),
                    'transport_cost'=>0,'extra_cost'=>0,'total_amount'=>round($total,3),
                    'base_total_before_vat'=>round($baseBefore,3),'base_vat_amount'=>round($baseVat,3),'base_total_amount'=>round($baseTotal,3),
                    'source_mode'=>'SHIPMENTS','source_shipment_count'=>count($ids),'payment_status'=>'UNPAID',
                    'notes'=>$v['notes']??('فاتورة مجمعة من الشحنات: '.implode(', ',$shipments->pluck('shipment_number')->all())),
                    'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now(),
                ]);

                $shipmentTotals=[];
                foreach($prepared as $p){
                    $it=$p['source'];$kg=$p['qty_kg'];$ton=$kg/1000;
                    $unitPrice=(float)$it->unit_price;
                    $disc=round((float)$it->discount_amount*$p['ratio'],3);

                    $lineId=DB::table('purchase_invoice_lines')->insertGetId([
                        'company_id'=>$cid,'purchase_invoice_id'=>$invoiceId,'item_id'=>$it->item_id,'car_id'=>$it->car_id,
                        'qty'=>round($ton,6),'unit_price'=>$unitPrice,'discount_amount'=>$disc,
                        'vat_percent'=>$it->tax_rate_snapshot??$it->vat_percent,'vat_amount'=>$p['vat'],
                        'total_before_vat'=>$p['before'],'total_after_vat'=>$p['after'],'line_total'=>$p['after'],
                        'tax_code_id'=>$it->tax_code_id,'tax_code_snapshot'=>$it->tax_code_snapshot,'tax_name_snapshot'=>$it->tax_name_snapshot,'tax_rate_snapshot'=>$it->tax_rate_snapshot,
                        'currency_code'=>$currency,'exchange_rate'=>$rate,'base_total_before_vat'=>$p['base_before'],'base_vat_amount'=>$p['base_vat'],'base_total_after_vat'=>$p['base_after'],
                        'notes'=>'من الشحنة '.$it->shipment_number.($it->notes?' — '.$it->notes:''),
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);

                    $capitalized=round($p['base_before']+$p['capitalized_extra'],3);
                    $lotId=$lots->createInboundLot([
                        'company_id'=>$cid,'branch_id'=>$branchId,'item_id'=>$it->item_id,'car_id'=>$it->car_id,
                        'shipment_id'=>$it->shipment_id,'shipment_item_id'=>$it->id,
                        'purchase_invoice_id'=>$invoiceId,'purchase_invoice_line_id'=>$lineId,
                        'qty_kg'=>$kg,'base_cost'=>$p['base_before'],'allocated_cost'=>$p['capitalized_extra'],'source_type'=>'SHIPMENT','source_id'=>$it->shipment_id,
                        'received_at'=>$invoiceDate.' 00:00:00','notes'=>'دفعة من الشحنة '.$it->shipment_number,'created_by'=>$uid
                    ]);

                    DB::table('stock_movements')->insert([
                        'company_id'=>$cid,'branch_id'=>$branchId,'item_id'=>$it->item_id,'car_id'=>$it->car_id,'shipment_item_id'=>$it->id,'inventory_lot_id'=>$lotId,
                        'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>$invoiceId,'movement_date'=>$invoiceDate,
                        'qty'=>round($ton,6),'qty_kg'=>$kg,
                        'unit_cost'=>$ton>0?round($capitalized/$ton,3):0,
                        'unit_cost_per_kg'=>$kg>0?round($capitalized/$kg,6):0,
                        'total_cost'=>$capitalized,'notes'=>'فاتورة مشتريات مجمعة - '.$it->shipment_number,
                        'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()
                    ]);

                    DB::table('shipment_items')->where('id',$it->id)->update([
                        'invoiced_qty_kg'=>round((float)($it->invoiced_qty_kg??0)+$kg,3),
                        'purchase_line_id'=>$lineId,
                        'inventory_lot_id'=>$lotId,
                        'inventory_created'=>1,
                        'updated_at'=>now(),
                    ]);

                    $shipmentTotals[$it->shipment_id]??=['qty'=>0.0,'supplier'=>0.0,'capitalized'=>0.0];
                    $shipmentTotals[$it->shipment_id]['qty']+=$kg;
                    $shipmentTotals[$it->shipment_id]['supplier']+=$p['base_before'];
                    $shipmentTotals[$it->shipment_id]['capitalized']+=$capitalized;
                }

                foreach($shipments as $s){
                    $st=$shipmentTotals[$s->id]??['qty'=>0,'supplier'=>0,'capitalized'=>0];
                    DB::table('purchase_invoice_shipments')->insert([
                        'company_id'=>$cid,'branch_id'=>$branchId,'purchase_invoice_id'=>$invoiceId,'shipment_id'=>$s->id,
                        'allocated_qty_kg'=>round($st['qty'],3),'supplier_amount_base'=>round($st['supplier'],3),'capitalized_cost_base'=>round($st['capitalized'],3),
                        'created_at'=>now(),'updated_at'=>now()
                    ]);

                    $remaining=(float)DB::table('shipment_items')->where('shipment_id',$s->id)
                        ->selectRaw('COALESCE(SUM(GREATEST(qty_kg-invoiced_qty_kg,0)),0) r')->value('r');
                    DB::table('shipments')->where('id',$s->id)->update([
                        'purchase_invoice_id'=>$invoiceId,
                        'invoice_status'=>$remaining<=0.001?'INVOICED':'PARTIAL',
                        'updated_at'=>now()
                    ]);
                }

                $post=$accounting->purchase(['company_id'=>$cid,'invoice_id'=>$invoiceId,'created_by'=>$uid]);
                if(!$post->success) throw new \RuntimeException($post->message);

                return [
                    'invoice_id'=>$invoiceId,'invoice_number'=>$invoiceNo,
                    'shipment_count'=>count($ids),'qty_kg'=>round($qtyTon*1000,3),
                    'journal_entry_id'=>$post->journalEntryId
                ];
            });

            return response()->json(['status'=>true,'message'=>'تم إنشاء فاتورة مشتريات مجمعة من الشحنات وترحيل المخزون والمحاسبة.','data'=>$result],201);
        }catch(\Throwable $e){
            return response()->json(['status'=>false,'message'=>$e->getMessage()],422);
        }
    }
}
