<?php
namespace App\Services\Accounting;

use App\Services\CommercialReturnService;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\DB;

/** Conservative isolated adapter over the real existing lot/return/JournalService path. */
final class SnapshotReturnPostingService
{
    /** Isolated draft preparation exposes the final residual BEFORE posting. */
    public function saveDraft(int $cid,int $bid,int $uid,array $data): int
    {
        HistoricalAccountingAuthorization::assert($cid,$bid,$uid,['returns.draft']);
        return DB::transaction(function()use($cid,$bid,$uid,$data){
            DB::table('companies')->where('id',$cid)->lockForUpdate()->first();
            $rid=app(CommercialReturnService::class)->saveDraft($cid,$bid,$uid,$data);
            $r=DB::table('commercial_returns')->where('company_id',$cid)->where('id',$rid)->first();
            $sale=$r->return_type==='SALES_RETURN';
            $s=DB::table('invoice_accounting_snapshots')->where('company_id',$cid)->where('branch_id',$bid)->where($sale?'sales_invoice_id':'purchase_invoice_id',$r->source_invoice_id)->first();
            if(!$s||$s->evidence_type!=='LIVE_CAPTURE')throw new \RuntimeException('Reviewed line snapshot required for precise draft.');
            foreach(DB::table('commercial_return_lines')->where('company_id',$cid)->where('return_id',$rid)->get()as$l){
                $basis=DB::table('invoice_line_accounting_snapshots')->where('snapshot_id',$s->id)->where($sale?'sales_invoice_line_id':'purchase_invoice_line_id',$l->source_invoice_line_id)->first();
                if(!$basis)throw new \RuntimeException('Line evidence missing.');
                $stock=(int)$l->track_inventory_snapshot===1;$qty=$stock?$l->qty_kg:$l->quantity;$original=$stock?$basis->original_qty_kg:$basis->original_quantity;
                $prior=DB::table('commercial_return_lines as l')->join('commercial_returns as r','r.id','=','l.return_id')->where('l.company_id',$cid)->where('r.source_invoice_id',$r->source_invoice_id)->where('r.return_type',$r->return_type)->where('r.document_status','POSTED')->where('l.source_invoice_line_id',$l->source_invoice_line_id)->get(['l.qty_kg','l.quantity','l.total_before_vat','l.vat_amount','l.base_total_before_vat','l.base_vat_amount']);
                $final=bccomp(bcadd((string)$prior->sum($stock?'qty_kg':'quantity'),(string)$qty,6),(string)$original,6)===0;
                $values=[];
                foreach(['total_before_vat'=>'foreign_net_basis','vat_amount'=>'foreign_tax_basis','base_total_before_vat'=>'base_net_basis','base_vat_amount'=>'base_tax_basis']as$field=>$source){
                    $expected=$this->basisAmount($basis->$source,(string)$qty,(string)$original,$prior,$field,$final);
                    if(abs(HistoricalDecimal::units($expected)-HistoricalDecimal::units($l->$field))>1)throw new \RuntimeException('Draft rounding differs by more than one ledger unit; review required.');
                    $values[$field]=$expected;
                }
                $values['total_after_vat']=HistoricalDecimal::format(HistoricalDecimal::units($values['total_before_vat'])+HistoricalDecimal::units($values['vat_amount']));
                $values['base_total_after_vat']=HistoricalDecimal::format(HistoricalDecimal::units($values['base_total_before_vat'])+HistoricalDecimal::units($values['base_vat_amount']));
                DB::table('commercial_return_lines')->where('company_id',$cid)->where('id',$l->id)->update($values);
            }
            $q=DB::table('commercial_return_lines')->where('company_id',$cid)->where('return_id',$rid);
            DB::table('commercial_returns')->where('company_id',$cid)->where('id',$rid)->update(['total_before_vat'=>$q->sum('total_before_vat'),'vat_amount'=>$q->sum('vat_amount'),'total_amount'=>$q->sum('total_after_vat'),'base_total_before_vat'=>$q->sum('base_total_before_vat'),'base_vat_amount'=>$q->sum('base_vat_amount'),'base_total_amount'=>$q->sum('base_total_after_vat')]);
            return $rid;
        });
    }
    private function basisAmount(mixed $amount,string $qty,string $original,$prior,string $field,bool $final): string
    {
        if(!$final)return HistoricalDecimal::proportion($amount,$qty,$original);
        $done=0;foreach($prior as$p)$done+=HistoricalDecimal::units($p->$field);
        return HistoricalDecimal::format(HistoricalDecimal::units($amount)-$done);
    }
    public function post(int $cid,int $bid,int $rid,int $uid): array
    {
        IsolatedHistoricalAccountingGate::assert();
        HistoricalAccountingAuthorization::assert($cid,$bid,$uid,['returns.post']);
        return DB::transaction(function()use($cid,$bid,$rid,$uid){
            DB::table('companies')->where('id',$cid)->lockForUpdate()->first();
            $r=DB::table('commercial_returns')->where('company_id',$cid)->where('branch_id',$bid)->where('id',$rid)->lockForUpdate()->first();if(!$r)throw new \RuntimeException('Return outside scope.');
            if($r->document_status==='POSTED')return app(CommercialReturnService::class)->details($cid,$rid,$bid);
            if($r->document_status!=='DRAFT')throw new \RuntimeException('Return not draft.');
            $sale=$r->return_type==='SALES_RETURN';$kind=$sale?'SALE':'PURCHASE';
            $s=DB::table('invoice_accounting_snapshots')->where('company_id',$cid)->where('branch_id',$bid)->where($sale?'sales_invoice_id':'purchase_invoice_id',$r->source_invoice_id)->lockForUpdate()->first();
            if(!$s||$s->evidence_type!=='LIVE_CAPTURE'||(int)($sale?$s->customer_id:$s->supplier_id)!==(int)$r->party_id)throw new \RuntimeException('Historical return requires reviewed line evidence. Draft/view remain available.');
            $invoice=DB::table($sale?'sales_invoices':'purchase_invoices')->where('company_id',$cid)->where('id',$r->source_invoice_id)->lockForUpdate()->first();
            if(!$invoice||$r->return_date<$invoice->invoice_date)throw new \RuntimeException('Return cannot precede original invoice.');
            $party=app(HistoricalInvoiceAccountingService::class)->partyAccount($cid,$bid,$kind,(int)$r->source_invoice_id);
            if($party!==(int)$s->party_account_id)throw new \RuntimeException('Original party evidence mismatch.');
            if($r->currency_code!==$s->currency_code||abs((float)$r->exchange_rate-(float)$s->exchange_rate)>0.0000000001)throw new \RuntimeException('Return currency/FX drift.');
            $paid=(float)DB::table('voucher_invoice_allocations')->where('company_id',$cid)->where('snapshot_id',$s->id)->selectRaw("COALESCE(SUM(CASE WHEN entry_kind='ALLOCATE' THEN base_amount ELSE -base_amount END),0) AS paid")->value('paid');
            if($paid>0.0001)throw new \RuntimeException('Settled invoice return requires explicit refund/credit policy.');
            $map=[];$taxMap=[];$expectedTotal=0.0;
            $lines=DB::table('commercial_return_lines')->where('company_id',$cid)->where('return_id',$rid)->lockForUpdate()->get();if($lines->isEmpty())throw new \RuntimeException('Return has no lines.');
            foreach($lines as$l){
                $source=DB::table($sale?'sales_invoice_lines':'purchase_invoice_lines')->where('company_id',$cid)->where($sale?'sales_invoice_id':'purchase_invoice_id',$r->source_invoice_id)->where('id',$l->source_invoice_line_id)->lockForUpdate()->first();
                if(!$source||(int)$source->item_id!==(int)$l->item_id)throw new \RuntimeException('Return line/source mismatch.');
                if((int)$source->track_inventory_snapshot!==(int)$l->track_inventory_snapshot||$source->item_type_snapshot!==$l->item_type_snapshot)throw new \RuntimeException('Return stock/service classification differs from original.');
                $rows=DB::table('invoice_line_accounting_snapshots')->where('company_id',$cid)->where('snapshot_id',$s->id)->where($sale?'sales_invoice_line_id':'purchase_invoice_line_id',$source->id)->get();
                if($rows->isEmpty())throw new \RuntimeException('Missing source line snapshot.');$basis=$rows->first();
                $stock=(int)$l->track_inventory_snapshot===1&&$l->item_type_snapshot!=='SERVICE';$qty=(float)($stock?$l->qty_kg:$l->quantity);$original=(float)($stock?$basis->original_qty_kg:$basis->original_quantity);
                if($qty<=0||$original<=0)throw new \RuntimeException('Invalid historical quantity.');
                $prior=DB::table('commercial_return_lines as l')->join('commercial_returns as r','r.id','=','l.return_id')->where('l.company_id',$cid)->where('r.source_invoice_id',$r->source_invoice_id)->where('r.return_type',$r->return_type)->where('r.document_status','POSTED')->where('l.source_invoice_line_id',$source->id)->get(['l.qty_kg','l.quantity','l.base_total_before_vat','l.base_vat_amount']);
                $done=(float)$prior->sum($stock?'qty_kg':'quantity');if($qty+$done-$original>0.0001)throw new \RuntimeException('Return exceeds original quantity.');
                $final=abs($qty+$done-$original)<0.0001;$net=(float)$this->basisAmount($basis->base_net_basis,number_format($qty,6,'.',''),number_format($original,6,'.',''),$prior,'base_total_before_vat',$final);
                $tax=(float)$this->basisAmount($basis->base_tax_basis,number_format($qty,6,'.',''),number_format($original,6,'.',''),$prior,'base_vat_amount',$final);
                if(abs($net-(float)$l->base_total_before_vat)>0.0001||abs($tax-(float)$l->base_vat_amount)>0.0001)throw new \RuntimeException('Return amount/rounding differs from frozen basis; review draft.');$expectedTotal=round($expectedTotal+$net+$tax,3);
                foreach($rows as$row){$role=$row->accounting_role;if(isset($map[$l->item_id][$role])&&$map[$l->item_id][$role]!=(int)$row->account_id)throw new \RuntimeException('Same item has distinct historical line GL; adapter cannot collapse it.');$map[$l->item_id][$role]=(int)$row->account_id;if(in_array($role,['VAT_INPUT','VAT_OUTPUT'],true)){$taxId=(int)($l->tax_code_id??0);if(isset($taxMap[$taxId])&&$taxMap[$taxId]!=(int)$row->account_id)throw new \RuntimeException('Tax code has distinct historical GL; requires line-aware tax review.');$taxMap[$taxId]=(int)$row->account_id;}}
                if(!isset($map[$l->item_id]['RETURN_ACCOUNT']))throw new \RuntimeException('Explicit historical return account missing; requires setup/review.');
                if($stock&&!isset($map[$l->item_id]['INVENTORY']))throw new \RuntimeException('Original inventory GL missing.');
                if($stock&&$sale&&!isset($map[$l->item_id]['COGS']))throw new \RuntimeException('Original COGS missing.');
                if($stock&&$sale){$fifo=DB::table('sales_line_lot_sources')->where('company_id',$cid)->where('sales_invoice_line_id',$source->id)->lockForUpdate()->get();if(abs((float)$fifo->sum('qty_kg')-$original)>0.0001||abs((float)$fifo->sum('total_cost')-(float)$basis->base_cost_basis)>0.0001)throw new \RuntimeException('Original FIFO evidence drift.');}
                if($stock&&!$sale){$lot=DB::table('inventory_lots')->where('company_id',$cid)->where('purchase_invoice_line_id',$source->id)->lockForUpdate()->get();if($lot->isEmpty()||$lot->pluck('unit_cost_per_kg')->unique()->count()!==1||abs(round($qty*(float)$lot->first()->unit_cost_per_kg,3)-$net)>0.0001)throw new \RuntimeException('Purchase landed cost/variance requires explicit policy.');}
            }
            if(abs($expectedTotal-(float)$r->base_total_amount)>0.0001)throw new \RuntimeException('Return header does not reconcile.');
            $resolver=new class(app(PostingSupport::class),$map,$party) extends ItemAccountingResolver {
                public function __construct(PostingSupport $s,private array $map,private int $party){parent::__construct($s);}
                private function original(int $item,string $role):int{return $this->map[$item][$role]??throw new \RuntimeException('Missing frozen accounting role.');}
                public function inventory(int $c,int $i):int{return$this->original($i,'INVENTORY');}
                public function cogs(int $c,int $i):int{return$this->original($i,'COGS');}
                public function salesReturn(int $c,int $i):int{return$this->original($i,'RETURN_ACCOUNT');}
                public function purchaseReturn(int $c,int $i):int{return$this->original($i,'RETURN_ACCOUNT');}
                public function receivable(int $c,?int $p=null):int{return$this->party;}
                public function payable(int $c,?int $p=null):int{return$this->party;}
            };
            $taxes=new class($taxMap) extends TaxEngineService {
                public function __construct(private array $map){}
                public function taxAccount(int $companyId,?int $taxCodeId,string $mode):int{return$this->map[(int)($taxCodeId??0)]??throw new \RuntimeException('Missing original tax account.');}
            };
            $service=new CommercialReturnService($resolver,$taxes,app(\App\Domain\Accounting\Services\JournalService::class),app(\App\Services\SulbDocumentSequenceService::class));
            $result=$service->post($cid,$rid,$uid,$bid);
            app(InvoiceAllocationService::class)->project($s);
            return$result;
        });
    }
}
