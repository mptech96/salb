<?php
namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/** Opt-in isolated integration only. No routes or container replacements. */
final class InvoiceSnapshotPostingService
{
    public function __construct(private EnterpriseAccountingPostingService $posting,private ItemAccountingResolver $accounts){}

    public function post(int $cid,int $bid,string $kind,int $id,int $uid,bool $fullLifecycle=false): int
    {
        IsolatedHistoricalAccountingGate::assert();
        HistoricalAccountingAuthorization::assert($cid,$bid,$uid,[$kind==='SALE'?'sales.post':'purchases.post']);
        if(!in_array($kind,['SALE','PURCHASE'],true))throw new \RuntimeException('Invalid invoice kind.');
        return DB::transaction(function()use($cid,$bid,$kind,$id,$uid,$fullLifecycle){
            $sale=$kind==='SALE';$table=$sale?'sales_invoices':'purchase_invoices';
            $invoice=DB::table($table)->where('company_id',$cid)->where('branch_id',$bid)->where('id',$id)->lockForUpdate()->first();
            if(!$invoice||!empty($invoice->voided_at))throw new \RuntimeException('Invoice outside scope or void.');
            $snapshot=DB::table('invoice_accounting_snapshots')->where('company_id',$cid)->where($sale?'sales_invoice_id':'purchase_invoice_id',$id)->first();
            if($invoice->journal_entry_id){
                if(!$snapshot||(int)$snapshot->journal_entry_id!==(int)$invoice->journal_entry_id)throw new \RuntimeException('Historical invoice requires reviewed evidence; no automatic backfill.');
                app(HistoricalInvoiceAccountingService::class)->partyAccount($cid,$bid,$kind,$id);
                return (int)$invoice->journal_entry_id;
            }
            if($invoice->document_status!=='DRAFT'||$snapshot)throw new \RuntimeException('Invalid invoice snapshot state.');
            // Lock configuration read by the existing engine so capture cannot drift mid-post.
            foreach(['accounts','items','accounting_settings','tax_codes','item_groups']as$t)DB::table($t)->where('company_id',$cid)->lockForUpdate()->get();
            DB::table('item_categories')->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->lockForUpdate()->get();
            DB::table($sale?'customers':'suppliers')->where('company_id',$cid)->where('id',$invoice->{$sale?'customer_id':'supplier_id'})->lockForUpdate()->first();
            DB::table($sale?'sales_invoice_lines':'purchase_invoice_lines')->where('company_id',$cid)->where($sale?'sales_invoice_id':'purchase_invoice_id',$id)->lockForUpdate()->get();
            $capture=function($inv,$lines,$contributions,$jid)use($cid,$bid,$kind,$uid){$this->capture($cid,$bid,$kind,$uid,$inv,$lines,$contributions,$jid);};
            if($fullLifecycle){
                $result=app(\App\Services\EnterpriseInvoiceService::class)->post($kind,$cid,$id,$uid,$bid,$capture);
                $jid=(int)$result['journal_entry_id'];
            }else{
                $jid=$sale?$this->posting->postSale($cid,$id,$uid,$capture):$this->posting->postPurchase($cid,$id,$uid,$capture);
            }
            DB::table($table)->where('company_id',$cid)->where('id',$id)->update(['document_status'=>'POSTED','posted_by'=>$uid,'posted_at'=>now()]);
            return $jid;
        });
    }

    private function capture(int $cid,int $bid,string $kind,int $uid,object $inv,$lines,array $contributions,int $jid): void
    {
        $sale=$kind==='SALE';$journal=DB::table('journal_entry_lines')->where('company_id',$cid)->where('journal_entry_id',$jid)->orderBy('id')->get();
        $party=$journal->filter(fn($l)=>$l->party_type===($sale?'CUSTOMER':'SUPPLIER')&&(int)$l->party_id===(int)$inv->{$sale?'customer_id':'supplier_id'});
        if($party->count()!==1)throw new \RuntimeException('Ambiguous original party GL.');
        $grouped=[];
        foreach($contributions as$c){$key=$c['account_id'].'|'.($c['debit']>0?'D':'C');$grouped[$key]=round(($grouped[$key]??0)+$c['debit']+$c['credit'],3);}
        foreach($journal->filter(fn($l)=>!$l->party_type)as$l){$key=$l->account_id.'|'.($l->debit>0?'D':'C');if(!isset($grouped[$key])||abs($grouped[$key]-(float)$l->debit-(float)$l->credit)>0.0001)throw new \RuntimeException('Uncaptured GL component/rounding needs explicit policy.');unset($grouped[$key]);}
        if($grouped)throw new \RuntimeException('Snapshot contribution does not reconcile to GL.');
        $header=['company_id'=>$cid,'branch_id'=>$bid,'invoice_type'=>$kind,'sales_invoice_id'=>$sale?$inv->id:null,'purchase_invoice_id'=>$sale?null:$inv->id,'customer_id'=>$sale?$inv->customer_id:null,'supplier_id'=>$sale?null:$inv->supplier_id,'journal_entry_id'=>$jid,'party_account_id'=>$party->first()->account_id,'currency_code'=>$inv->currency_code,'exchange_rate'=>$inv->exchange_rate,'foreign_total'=>$inv->total_amount,'base_total'=>$inv->base_total_amount??$inv->total_amount,'policy_version'=>'CONSERVATIVE-1','policy_json'=>json_encode(['missing_return_account'=>'REVIEW_REQUIRED','landed_costs'=>'BLOCKED','inactive_original'=>'BLOCKED']),'evidence_type'=>'LIVE_CAPTURE','evidence_hash'=>hash('sha256',json_encode([$inv,$lines,$contributions,$journal],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'approved_by'=>null,'captured_at'=>now()];
        $snapshotId=DB::table('invoice_accounting_snapshots')->insertGetId($header);
        foreach($lines as$l){
            $cost=0.0;foreach($contributions as$c)if($c['line_id']==$l->id&&in_array($c['role'],['COGS','INVENTORY'],true))$cost=max($cost,(float)$c['debit']+(float)$c['credit']);
            $basis=['company_id'=>$cid,'snapshot_id'=>$snapshotId,'sales_invoice_line_id'=>$sale?$l->id:null,'purchase_invoice_line_id'=>$sale?null:$l->id,'item_id'=>$l->item_id,'allocation_component'=>'LINE','original_quantity'=>$l->quantity??$l->qty,'unit_code'=>$l->unit_code??'KG','original_qty_kg'=>$l->qty_kg??0,'foreign_net_basis'=>$l->total_before_vat,'base_net_basis'=>$l->base_total_before_vat??$l->total_before_vat,'foreign_discount_basis'=>$l->discount_amount??0,'base_discount_basis'=>round((float)($l->discount_amount??0)*(float)$inv->exchange_rate,3),'foreign_tax_basis'=>$l->vat_amount??0,'base_tax_basis'=>$l->base_vat_amount??$l->vat_amount??0,'base_cost_basis'=>$cost,'captured_at'=>now()];
            foreach($contributions as$c){if($c['line_id']!=$l->id)continue;$match=$journal->filter(fn($j)=>!$j->party_type&&(int)$j->account_id===(int)$c['account_id']&&($c['debit']>0?$j->debit>0:$j->credit>0));if($match->count()!==1)throw new \RuntimeException('Ambiguous grouped journal line.');DB::table('invoice_line_accounting_snapshots')->insert($basis+['accounting_role'=>$c['role'],'account_id'=>$c['account_id'],'journal_entry_line_id'=>$match->first()->id,'original_debit'=>$c['debit'],'original_credit'=>$c['credit']]);}
            $return=$this->explicitReturnAccount($cid,(int)$l->item_id,$sale);
            if($return)DB::table('invoice_line_accounting_snapshots')->insert($basis+['accounting_role'=>'RETURN_ACCOUNT','account_id'=>$return,'journal_entry_line_id'=>null,'original_debit'=>0,'original_credit'=>0]);
        }
    }

    private function explicitReturnAccount(int $cid,int $itemId,bool $sale): ?int
    {
        $column=$sale?'sales_return_account_id':'purchase_return_account_id';$item=DB::table('items')->where('company_id',$cid)->where('id',$itemId)->first();$id=(int)($item->$column??0);
        $parent=(int)($item->category_id??0);$seen=[];
        while(!$id&&$parent&&!isset($seen[$parent])){$seen[$parent]=true;$cat=DB::table('item_categories')->where('id',$parent)->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->first();$id=(int)($cat->$column??0);$parent=(int)($cat->parent_id??0);}
        if(!$id&&!empty($item->group_id))$id=(int)DB::table('item_groups')->where('company_id',$cid)->where('id',$item->group_id)->value($column);
        foreach($sale?['SALES_RETURN_ACCOUNT','SALES_RETURNS_ACCOUNT']:['PURCHASE_RETURN_ACCOUNT','PURCHASE_RETURNS_ACCOUNT']as$key)if(!$id)$id=(int)DB::table('accounting_settings')->where('company_id',$cid)->where('setting_key',$key)->value('account_id');
        if(!$id)return null;
        if(!DB::table('accounts')->where('company_id',$cid)->where('id',$id)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->exists())throw new \RuntimeException('Explicit return account is not eligible.');
        return $id;
    }
}
