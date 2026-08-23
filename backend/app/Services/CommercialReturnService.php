<?php
namespace App\Services;

use App\Domain\Accounting\Services\JournalService;
use App\Services\Accounting\ItemAccountingResolver;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\DB;

class CommercialReturnService
{
    public function __construct(private ItemAccountingResolver $accounts,private TaxEngineService $taxes,private JournalService $journals,private SulbDocumentSequenceService $sequences){}

    public function list(int $cid,?int $bid=null,?string $type=null)
    {
        $q=DB::table('commercial_returns as r')->leftJoin('branches as b','b.id','=','r.branch_id')->where('r.company_id',$cid);
        if($bid!==null)$q->where('r.branch_id',$bid);if($type)$q->where('r.return_type',strtoupper($type));
        return $q->select('r.*','b.branch_name')->orderByDesc('r.return_date')->orderByDesc('r.id')->get();
    }

    public function sourceInvoices(int $cid,?int $bid,string $type)
    {
        $sale=strtoupper($type)==='SALES_RETURN';$table=$sale?'sales_invoices':'purchase_invoices';$party=$sale?'customer_id':'supplier_id';$nameTable=$sale?'customers':'suppliers';$nameCol=$sale?'customer_name':'supplier_name';
        $q=DB::table($table.' as i')->leftJoin($nameTable.' as p','p.id','=','i.'.$party)->where('i.company_id',$cid)->where('i.document_status','POSTED')->whereNull('i.voided_at');if($bid!==null)$q->where('i.branch_id',$bid);
        return $q->select('i.id','i.branch_id','i.invoice_number','i.invoice_date','i.'.$party.' as party_id','p.'.$nameCol.' as party_name','i.currency_code','i.exchange_rate','i.total_amount','i.base_total_amount')->orderByDesc('i.invoice_date')->orderByDesc('i.id')->limit(1000)->get();
    }

    public function sourceLines(int $cid,string $type,int $invoiceId): array
    {
        [$table,$lineTable,$fk]=$this->tables($type);$inv=DB::table($table)->where('company_id',$cid)->where('id',$invoiceId)->where('document_status','POSTED')->whereNull('voided_at')->first();if(!$inv)throw new \RuntimeException('الفاتورة الأصلية غير موجودة أو غير مرحلة.');
        $lines=DB::table($lineTable.' as l')->join('items as i','i.id','=','l.item_id')->where('l.company_id',$cid)->where('l.'.$fk,$invoiceId)->select('l.*','i.item_code','i.item_name')->orderBy('l.id')->get();
        foreach($lines as$l){$returned=$this->returnedQty($cid,$type,(int)$l->id);$source=$this->sourceQty($l);$l->returned_qty=$returned;$l->returnable_qty=max(0,round($source-$returned,6));}
        return ['invoice'=>$inv,'lines'=>$lines];
    }

    public function saveDraft(int $cid,int $bid,int $uid,array $d,?int $returnId=null): int
    {
        $type=strtoupper((string)$d['return_type']);if(!in_array($type,['SALES_RETURN','PURCHASE_RETURN'],true))throw new \RuntimeException('نوع المردود غير صحيح.');
        return DB::transaction(function()use($cid,$bid,$uid,$d,$returnId,$type){
            [$table,$lineTable,$fk,$partyCol]=$this->tables($type,true);$invId=(int)$d['source_invoice_id'];$inv=DB::table($table)->where('company_id',$cid)->where('branch_id',$bid)->where('id',$invId)->where('document_status','POSTED')->whereNull('voided_at')->lockForUpdate()->first();if(!$inv)throw new \RuntimeException('الفاتورة الأصلية غير صالحة للمردود.');
            $old=$returnId?DB::table('commercial_returns')->where('company_id',$cid)->where('id',$returnId)->lockForUpdate()->first():null;if($returnId&&!$old)throw new \RuntimeException('المردود غير موجود.');if($old&&$old->document_status!=='DRAFT')throw new \RuntimeException('المردود المرحل لا يعدل مباشرة.');
            $date=(string)($d['return_date']??date('Y-m-d'));$number=$old?->return_number?:$this->sequences->next($cid,$bid,$type,$date,$type==='SALES_RETURN'?'SR':'PR');$partyId=(int)$inv->{$partyCol};
            $header=['company_id'=>$cid,'branch_id'=>$bid,'return_type'=>$type,'return_number'=>$number,'return_date'=>$date,'source_invoice_id'=>$invId,'party_id'=>$partyId,'currency_code'=>$inv->currency_code??null,'exchange_rate'=>(float)($inv->exchange_rate?:1),'document_status'=>'DRAFT','notes'=>$d['notes']??null,'updated_at'=>now()];
            if($old){DB::table('commercial_returns')->where('id',$returnId)->update($header);$rid=$returnId;DB::table('commercial_return_lines')->where('company_id',$cid)->where('return_id',$rid)->delete();}else{$header['created_by']=$uid;$header['created_at']=now();$rid=DB::table('commercial_returns')->insertGetId($header);}
            $before=0;$vat=0;$after=0;$bBefore=0;$bVat=0;$bAfter=0;$rows=$d['lines']??[];if(!$rows)throw new \RuntimeException('أضف صنفًا واحدًا على الأقل للمردود.');
            foreach($rows as$n=>$r){$sourceId=(int)($r['source_invoice_line_id']??0);$src=DB::table($lineTable)->where('company_id',$cid)->where($fk,$invId)->where('id',$sourceId)->first();if(!$src)throw new \RuntimeException('السطر '.($n+1).': سطر الفاتورة الأصلية غير صحيح.');$qty=round((float)($r['quantity']??$r['qty_kg']??0),6);if($qty<=0)continue;$available=round($this->sourceQty($src)-$this->returnedQty($cid,$type,$sourceId,$returnId),6);if($qty-$available>0.0001)throw new \RuntimeException('السطر '.($n+1).': كمية المردود أكبر من المتاح. المتاح '.number_format($available,3));$sourceQty=max(0.000001,$this->sourceQty($src));$ratio=$qty/$sourceQty;
                $isService=strtoupper((string)($src->item_type_snapshot??'STOCK'))==='SERVICE'||(int)($src->track_inventory_snapshot??1)!==1;$lineBefore=round((float)$src->total_before_vat*$ratio,3);$lineVat=round((float)$src->vat_amount*$ratio,3);$lineAfter=round($lineBefore+$lineVat,3);$baseBefore=round((float)($src->base_total_before_vat??$src->total_before_vat)*$ratio,3);$baseVat=round((float)($src->base_vat_amount??$src->vat_amount)*$ratio,3);$baseAfter=round($baseBefore+$baseVat,3);
                DB::table('commercial_return_lines')->insert(['company_id'=>$cid,'return_id'=>$rid,'source_invoice_line_id'=>$sourceId,'item_id'=>$src->item_id,'item_type_snapshot'=>$src->item_type_snapshot??($isService?'SERVICE':'STOCK'),'track_inventory_snapshot'=>$isService?0:1,'quantity'=>$isService?$qty:round($qty/1000,6),'unit_code'=>$isService?($src->unit_code??'UNIT'):'KG','qty_kg'=>$isService?0:$qty,'unit_price_per_kg'=>(float)($src->unit_price_per_kg??0),'total_before_vat'=>$lineBefore,'tax_code_id'=>$src->tax_code_id??null,'vat_percent'=>(float)($src->vat_percent??$src->tax_rate_snapshot??0),'vat_amount'=>$lineVat,'total_after_vat'=>$lineAfter,'base_total_before_vat'=>$baseBefore,'base_vat_amount'=>$baseVat,'base_total_after_vat'=>$baseAfter,'notes'=>$r['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
                $before+=$lineBefore;$vat+=$lineVat;$after+=$lineAfter;$bBefore+=$baseBefore;$bVat+=$baseVat;$bAfter+=$baseAfter;
            }
            if(!DB::table('commercial_return_lines')->where('return_id',$rid)->exists())throw new \RuntimeException('كمية المردود يجب أن تكون أكبر من صفر.');
            DB::table('commercial_returns')->where('id',$rid)->update(['total_before_vat'=>round($before,3),'vat_amount'=>round($vat,3),'total_amount'=>round($after,3),'base_total_before_vat'=>round($bBefore,3),'base_vat_amount'=>round($bVat,3),'base_total_amount'=>round($bAfter,3),'updated_at'=>now()]);return $rid;
        });
    }

    public function post(int $cid,int $rid,int $uid,?int $bf=null): array
    {
        return DB::transaction(function()use($cid,$rid,$uid,$bf){$r=DB::table('commercial_returns')->where('company_id',$cid)->where('id',$rid)->lockForUpdate()->first();if(!$r)throw new \RuntimeException('المردود غير موجود.');if($bf!==null&&(int)$r->branch_id!==$bf)throw new \RuntimeException('المردود خارج نطاق فرعك.');if($r->document_status==='POSTED')return $this->details($cid,$rid,$bf);if($r->document_status!=='DRAFT')throw new \RuntimeException('حالة المردود لا تسمح بالترحيل.');$lines=DB::table('commercial_return_lines')->where('company_id',$cid)->where('return_id',$rid)->orderBy('id')->lockForUpdate()->get();if($lines->isEmpty())throw new \RuntimeException('المردود بلا أسطر.');
            $sale=$r->return_type==='SALES_RETURN';$journal=[];$inventoryCostByLine=[];
            foreach($lines as$l){$stock=(int)$l->track_inventory_snapshot===1&&$l->item_type_snapshot!=='SERVICE';if($sale){$this->group($journal,$this->accounts->salesReturn($cid,(int)$l->item_id),(float)$l->base_total_before_vat,0,'مردود مبيعات');if((float)$l->base_vat_amount>0)$this->group($journal,$this->taxes->taxAccount($cid,$l->tax_code_id?(int)$l->tax_code_id:null,'SALES'),(float)$l->base_vat_amount,0,'عكس ضريبة مخرجات');if($stock){$cost=$this->restoreSaleLots($cid,$r,$l,$uid);$inventoryCostByLine[$l->id]=$cost;$this->group($journal,$this->accounts->inventory($cid,(int)$l->item_id),$cost,0,'إعادة مخزون مردود بيع');$this->group($journal,$this->accounts->cogs($cid,(int)$l->item_id),0,$cost,'عكس تكلفة مبيعات');}}
                else{if((float)$l->base_vat_amount>0)$this->group($journal,$this->taxes->taxAccount($cid,$l->tax_code_id?(int)$l->tax_code_id:null,'PURCHASE'),0,(float)$l->base_vat_amount,'عكس ضريبة مدخلات');if($stock){$cost=$this->removePurchaseLots($cid,$r,$l,$uid);$inventoryCostByLine[$l->id]=$cost;$this->group($journal,$this->accounts->inventory($cid,(int)$l->item_id),0,$cost,'إخراج مخزون مردود شراء');$diff=round((float)$l->base_total_before_vat-$cost,3);if($diff>0)$this->group($journal,$this->accounts->purchaseReturn($cid,(int)$l->item_id),0,$diff,'فرق تكلفة مردود شراء');elseif($diff<0)$this->group($journal,$this->accounts->purchaseReturn($cid,(int)$l->item_id),abs($diff),0,'فرق تكلفة مردود شراء');}else{$this->group($journal,$this->accounts->purchaseReturn($cid,(int)$l->item_id),0,(float)$l->base_total_before_vat,'مردود شراء خدمة/مصروف');}}
                DB::table('commercial_return_lines')->where('id',$l->id)->update(['inventory_cost'=>round((float)($inventoryCostByLine[$l->id]??0),3),'updated_at'=>now()]);}
            if($sale){$journal[]=['account_id'=>$this->accounts->receivable($cid,(int)$r->party_id),'debit'=>0,'credit'=>(float)$r->base_total_amount,'party_type'=>'CUSTOMER','party_id'=>(int)$r->party_id,'description'=>'تخفيض ذمة العميل بالمردود'];}else{$journal[]=['account_id'=>$this->accounts->payable($cid,(int)$r->party_id),'debit'=>(float)$r->base_total_amount,'credit'=>0,'party_type'=>'SUPPLIER','party_id'=>(int)$r->party_id,'description'=>'تخفيض ذمة المورد بالمردود'];}
            $this->balance($journal,$r->return_number);$jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>(int)$r->branch_id,'entry_date'=>$r->return_date,'source_type'=>$r->return_type,'source_id'=>$rid,'description'=>'مردود '.$r->return_number,'currency_code'=>$r->currency_code,'exchange_rate'=>$r->exchange_rate,'lines'=>array_values($journal),'is_system_generated'=>1,'created_by'=>$uid]);DB::table('commercial_returns')->where('id',$rid)->update(['document_status'=>'POSTED','journal_entry_id'=>$jid,'posted_at'=>now(),'posted_by'=>$uid,'updated_at'=>now()]);return $this->details($cid,$rid,$bf);});
    }

    public function void(int $cid,int $rid,int $uid,string $reason,?int $bf=null): array
    {
        return DB::transaction(function()use($cid,$rid,$uid,$reason,$bf){
            $r=DB::table('commercial_returns')->where('company_id',$cid)->where('id',$rid)->lockForUpdate()->first();
            if(!$r)throw new \RuntimeException('المردود غير موجود.');
            if($bf!==null&&(int)$r->branch_id!==$bf)throw new \RuntimeException('المردود خارج نطاق فرعك.');
            if($r->document_status!=='POSTED'||!$r->journal_entry_id)throw new \RuntimeException('يمكن عكس المردود المرحل فقط.');
            if(mb_strlen(trim($reason))<5)throw new \RuntimeException('سبب العكس مطلوب.');

            $sources=DB::table('commercial_return_lot_sources as s')->join('commercial_return_lines as l','l.id','=','s.return_line_id')
                ->where('s.company_id',$cid)->where('l.return_id',$rid)->lockForUpdate()->select('s.*','l.item_id')->get();
            $sale=$r->return_type==='SALES_RETURN';
            foreach($sources as $s){
                $lot=DB::table('inventory_lots')->where('company_id',$cid)->where('id',$s->inventory_lot_id)->lockForUpdate()->first();
                if(!$lot)throw new \RuntimeException('دفعة مخزون مرتبطة بالمردود غير موجودة.');
                if($sale){
                    if((float)$lot->qty_remaining_kg+0.0001<(float)$s->qty_kg)throw new \RuntimeException('لا يمكن عكس مردود البيع لأن جزءًا من الكمية المرتجعة تم صرفه/بيعه لاحقًا.');
                    $remain=round((float)$lot->qty_remaining_kg-(float)$s->qty_kg,3);
                    DB::table('inventory_lots')->where('id',$lot->id)->update(['qty_remaining_kg'=>$remain,'qty_sold_kg'=>round((float)$lot->qty_sold_kg+(float)$s->qty_kg,3),'lot_status'=>$remain<=0?'CLOSED':'OPEN','updated_at'=>now()]);
                    $mv='OUT';
                } else {
                    DB::table('inventory_lots')->where('id',$lot->id)->update(['qty_remaining_kg'=>round((float)$lot->qty_remaining_kg+(float)$s->qty_kg,3),'lot_status'=>'OPEN','updated_at'=>now()]);
                    $mv='IN';
                }
                DB::table('inventory_lot_movements')->insert(['company_id'=>$cid,'branch_id'=>$r->branch_id,'inventory_lot_id'=>$lot->id,'item_id'=>$s->item_id,'movement_type'=>$mv,'source_type'=>$r->return_type.'_REVERSAL','source_id'=>$rid,'movement_at'=>now(),'qty_kg'=>$s->qty_kg,'unit_cost_per_kg'=>$s->unit_cost_per_kg,'total_cost'=>$s->total_cost,'notes'=>'عكس مردود '.$r->return_number,'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);
                DB::table('stock_movements')->insert(['company_id'=>$cid,'branch_id'=>$r->branch_id,'item_id'=>$s->item_id,'inventory_lot_id'=>$lot->id,'movement_type'=>$mv,'source_type'=>$r->return_type.'_REVERSAL','source_id'=>$rid,'movement_date'=>date('Y-m-d'),'qty'=>round((float)$s->qty_kg/1000,6),'qty_kg'=>$s->qty_kg,'unit_cost'=>round((float)$s->unit_cost_per_kg*1000,3),'unit_cost_per_kg'=>$s->unit_cost_per_kg,'total_cost'=>$s->total_cost,'notes'=>'عكس مردود '.$r->return_number,'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);
            }

            $reversal=$this->journals->reverse($cid,(int)$r->journal_entry_id,['reason'=>$reason,'entry_date'=>date('Y-m-d'),'source_type'=>$r->return_type.'_REVERSAL','created_by'=>$uid]);
            DB::table('commercial_returns')->where('id',$rid)->update(['document_status'=>'VOID','voided_at'=>now(),'voided_by'=>$uid,'void_reason'=>$reason,'updated_at'=>now()]);
            return ['return'=>$this->details($cid,$rid,$bf),'reversal_journal_entry_id'=>$reversal];
        });
    }

    public function details(int $cid,int $rid,?int $bf=null): array
    {$q=DB::table('commercial_returns as r')->leftJoin('branches as b','b.id','=','r.branch_id')->where('r.company_id',$cid)->where('r.id',$rid);if($bf!==null)$q->where('r.branch_id',$bf);$r=$q->select('r.*','b.branch_name')->first();if(!$r)throw new \RuntimeException('المردود غير موجود.');$lines=DB::table('commercial_return_lines as l')->join('items as i','i.id','=','l.item_id')->where('l.company_id',$cid)->where('l.return_id',$rid)->select('l.*','i.item_code','i.item_name')->orderBy('l.id')->get();return ['return'=>$r,'lines'=>$lines,'lot_sources'=>DB::table('commercial_return_lot_sources')->where('company_id',$cid)->whereIn('return_line_id',$lines->pluck('id'))->get()];}

    private function restoreSaleLots(int $cid,object $r,object $line,int $uid): float
    {$need=(float)$line->qty_kg;$sources=DB::table('sales_line_lot_sources')->where('company_id',$cid)->where('sales_invoice_line_id',$line->source_invoice_line_id)->orderBy('id')->lockForUpdate()->get();$prior=DB::table('commercial_return_lot_sources as rs')->join('commercial_return_lines as rl','rl.id','=','rs.return_line_id')->join('commercial_returns as rr','rr.id','=','rl.return_id')->where('rs.company_id',$cid)->where('rl.source_invoice_line_id',$line->source_invoice_line_id)->where('rr.return_type','SALES_RETURN')->whereIn('rr.document_status',['DRAFT','POSTED'])->selectRaw('rs.inventory_lot_id,SUM(rs.qty_kg) qty')->groupBy('rs.inventory_lot_id')->pluck('qty','inventory_lot_id');$total=0.0;foreach($sources as$s){if($need<=0.0001)break;$available=max(0,(float)$s->qty_kg-(float)($prior[$s->inventory_lot_id]??0));$take=min($need,$available);if($take<=0)continue;$lot=DB::table('inventory_lots')->where('company_id',$cid)->where('id',$s->inventory_lot_id)->lockForUpdate()->first();if(!$lot)throw new \RuntimeException('دفعة البيع الأصلية غير موجودة.');$cost=round($take*(float)$s->unit_cost_per_kg,3);DB::table('inventory_lots')->where('id',$lot->id)->update(['qty_remaining_kg'=>round((float)$lot->qty_remaining_kg+$take,3),'qty_sold_kg'=>max(0,round((float)$lot->qty_sold_kg-$take,3)),'lot_status'=>'OPEN','updated_at'=>now()]);$this->lotSource($cid,$line->id,$lot->id,$take,(float)$s->unit_cost_per_kg,$cost);$this->lotMovement($cid,$r,$lot,$line->item_id,'IN',$take,(float)$s->unit_cost_per_kg,$cost,$uid);$total+=$cost;$need=round($need-$take,3);}if($need>0.001)throw new \RuntimeException('تعذر إعادة كمية المردود إلى مصادر FIFO الأصلية بالكامل.');return round($total,3);}

    private function removePurchaseLots(int $cid,object $r,object $line,int $uid): float
    {$need=(float)$line->qty_kg;$lots=DB::table('inventory_lots')->where('company_id',$cid)->where('purchase_invoice_line_id',$line->source_invoice_line_id)->where('qty_remaining_kg','>',0)->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();$total=0.0;foreach($lots as$lot){if($need<=0.0001)break;$take=min($need,(float)$lot->qty_remaining_kg);$cost=round($take*(float)$lot->unit_cost_per_kg,3);DB::table('inventory_lots')->where('id',$lot->id)->update(['qty_remaining_kg'=>round((float)$lot->qty_remaining_kg-$take,3),'lot_status'=>round((float)$lot->qty_remaining_kg-$take,3)<=0?'CLOSED':'OPEN','updated_at'=>now()]);$this->lotSource($cid,$line->id,$lot->id,$take,(float)$lot->unit_cost_per_kg,$cost);$this->lotMovement($cid,$r,$lot,$line->item_id,'OUT',$take,(float)$lot->unit_cost_per_kg,$cost,$uid);$total+=$cost;$need=round($need-$take,3);}if($need>0.001)throw new \RuntimeException('لا يمكن مردود كمية شراء أكبر من الكمية المتبقية في دفعات الفاتورة.');return round($total,3);}
    private function lotSource(int $cid,int $lineId,int $lotId,float $kg,float $unit,float $cost): void {DB::table('commercial_return_lot_sources')->insert(['company_id'=>$cid,'return_line_id'=>$lineId,'inventory_lot_id'=>$lotId,'qty_kg'=>$kg,'unit_cost_per_kg'=>$unit,'total_cost'=>$cost,'created_at'=>now(),'updated_at'=>now()]);}
    private function lotMovement(int $cid,object $r,object $lot,int $itemId,string $mv,float $kg,float $unit,float $cost,int $uid): void {DB::table('inventory_lot_movements')->insert(['company_id'=>$cid,'branch_id'=>$r->branch_id,'inventory_lot_id'=>$lot->id,'item_id'=>$itemId,'movement_type'=>$mv,'source_type'=>$r->return_type,'source_id'=>$r->id,'movement_at'=>now(),'qty_kg'=>$kg,'unit_cost_per_kg'=>$unit,'total_cost'=>$cost,'notes'=>'مردود '.$r->return_number,'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);DB::table('stock_movements')->insert(['company_id'=>$cid,'branch_id'=>$r->branch_id,'item_id'=>$itemId,'inventory_lot_id'=>$lot->id,'movement_type'=>$mv,'source_type'=>$r->return_type,'source_id'=>$r->id,'movement_date'=>$r->return_date,'qty'=>round($kg/1000,6),'qty_kg'=>$kg,'unit_cost'=>round($unit*1000,3),'unit_cost_per_kg'=>$unit,'total_cost'=>$cost,'notes'=>'مردود '.$r->return_number,'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);}
    private function returnedQty(int $cid,string $type,int $sourceLineId,?int $excludeReturnId=null): float {$q=DB::table('commercial_return_lines as l')->join('commercial_returns as r','r.id','=','l.return_id')->where('l.company_id',$cid)->where('r.return_type',$type)->where('l.source_invoice_line_id',$sourceLineId)->whereIn('r.document_status',['DRAFT','POSTED']);if($excludeReturnId)$q->where('r.id','<>',$excludeReturnId);$rows=$q->select('l.qty_kg','l.quantity','l.track_inventory_snapshot')->get();return round((float)$rows->sum(fn($x)=>(int)$x->track_inventory_snapshot===1?(float)$x->qty_kg:(float)$x->quantity),6);}
    private function sourceQty(object $src): float {$service=strtoupper((string)($src->item_type_snapshot??'STOCK'))==='SERVICE'||(int)($src->track_inventory_snapshot??1)!==1;return $service?(float)($src->quantity??$src->qty??0):(float)($src->qty_kg??((float)($src->qty??0)*1000));}
    private function tables(string $type,bool $party=false): array {$sale=strtoupper($type)==='SALES_RETURN';$a=[$sale?'sales_invoices':'purchase_invoices',$sale?'sales_invoice_lines':'purchase_invoice_lines',$sale?'sales_invoice_id':'purchase_invoice_id'];if($party)$a[]=$sale?'customer_id':'supplier_id';return $a;}
    private function group(array &$j,int $acc,float $d,float $c,string $desc):void{$k=$acc.'|'.($d>0?'D':'C');if(!isset($j[$k]))$j[$k]=['account_id'=>$acc,'debit'=>0.0,'credit'=>0.0,'description'=>$desc];$j[$k]['debit']=round($j[$k]['debit']+$d,3);$j[$k]['credit']=round($j[$k]['credit']+$c,3);}
    private function balance(array $j,string $no):void{$d=round(array_sum(array_map(fn($x)=>(float)$x['debit'],$j)),3);$c=round(array_sum(array_map(fn($x)=>(float)$x['credit'],$j)),3);if(abs($d-$c)>0.01)throw new \RuntimeException('قيد المردود '.$no.' غير متوازن: '.number_format($d,3).' / '.number_format($c,3));}
}
