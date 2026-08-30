<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\CommercialDocumentService;
use App\Services\FinancialAccountService;
use App\Services\Platform\PrivilegedAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class CommercialDocumentController extends Controller
{
    abstract protected function type(): string;

    public function meta(Request$r,AccountingContext$ctx,FinancialAccountService$money,CommercialDocumentService$svc)
    {
        $c=$svc->config($this->type());$cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$sale=$c['mode']==='SALE';
        $parties=DB::table(($sale?'customers':'suppliers').' as p')->where('p.company_id',$cid)->where('p.is_active',1)->when($bid!==null,function($q)use($sale,$cid,$bid){$link=$sale?'customer_branches':'supplier_branches';$fk=$sale?'customer_id':'supplier_id';$q->where(fn($x)=>$x->where('p.scope_all_branches',1)->orWhereExists(fn($z)=>$z->selectRaw('1')->from($link.' as pb')->whereColumn('pb.'.$fk,'p.id')->where('pb.company_id',$cid)->where('pb.branch_id',$bid)->where('pb.is_active',1)));})->orderBy($sale?'customer_name':'supplier_name')->get(['p.id',$sale?'p.customer_name':'p.supplier_name']);
        return response()->json(['status'=>true,'data'=>['branches'=>DB::table('branches')->where('company_id',$cid)->where('is_active',1)->when($bid!==null,fn($q)=>$q->where('id',$bid))->orderBy('branch_name')->get(['id','branch_name','branch_code']),'parties'=>$parties,'items'=>DB::table('items')->where('company_id',$cid)->where('is_active',1)->where($sale?'can_sell':'can_purchase',1)->orderBy('item_name')->get(['id','item_code','item_name','item_type','track_inventory','unit_name','base_unit_code','commercial_unit_code','commercial_to_base_factor','default_buy_price','default_sell_price']),'tax_codes'=>DB::table('tax_codes')->where('company_id',$cid)->where('is_active',1)->orderBy('tax_code')->get(['id','tax_code','tax_name','rate']),'currencies'=>DB::table('company_currencies as cc')->join('currencies as c','c.currency_code','=','cc.currency_code')->where('cc.company_id',$cid)->where('cc.is_active',1)->get(['cc.currency_code','cc.is_base','c.currency_name','c.symbol','c.decimal_places']),'base_currency'=>$money->baseCurrency($cid),'scoped_branch_id'=>$bid]]);
    }
    public function index(Request$r,AccountingContext$ctx,CommercialDocumentService$svc)
    {
        $c=$svc->config($this->type());$cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$sale=$c['mode']==='SALE';$party=$sale?'customers':'suppliers';$name=$sale?'customer_name':'supplier_name';
        $q=DB::table($c['table'].' as d')->join($party.' as p','p.id','=','d.'.$c['party_key'])->join('branches as b','b.id','=','d.branch_id')->where('d.company_id',$cid)->when($bid!==null,fn($x)=>$x->where('d.branch_id',$bid));
        if($r->filled('status'))$q->where('d.status',strtoupper((string)$r->query('status')));if($r->filled('party_id'))$q->where('d.'.$c['party_key'],(int)$r->query('party_id'));if($r->filled('date_from'))$q->whereDate('d.document_date','>=',$r->query('date_from'));if($r->filled('date_to'))$q->whereDate('d.document_date','<=',$r->query('date_to'));if($r->filled('search')){$s='%'.trim((string)$r->query('search')).'%';$q->where(fn($x)=>$x->where('d.document_number','like',$s)->orWhere('p.'.$name,'like',$s));}
        return response()->json(['status'=>true,'data'=>$q->select('d.*','p.'.$name,'b.branch_name','b.branch_code')->orderByDesc('d.document_date')->orderByDesc('d.id')->limit(2000)->get()]);
    }
    public function show(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc)
    {
        $c=$svc->config($this->type());$cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$sale=$c['mode']==='SALE';$party=$sale?'customers':'suppliers';$name=$sale?'customer_name':'supplier_name';$doc=DB::table($c['table'].' as d')->join($party.' as p','p.id','=','d.'.$c['party_key'])->join('branches as b','b.id','=','d.branch_id')->where('d.company_id',$cid)->where('d.id',$id)->when($bid!==null,fn($q)=>$q->where('d.branch_id',$bid))->select('d.*','p.'.$name,'b.branch_name','b.branch_code')->first();if(!$doc)return response()->json(['status'=>false,'message'=>'المستند غير موجود.'],404);$lines=DB::table($c['line_table'].' as l')->join('items as i','i.id','=','l.item_id')->where('l.company_id',$cid)->where('l.'.$c['fk'],$id)->select('l.*','i.item_code','i.item_name','i.item_type','i.track_inventory')->orderBy('l.id')->get();return response()->json(['status'=>true,'data'=>['document'=>$doc,'lines'=>$lines]]);
    }
    public function store(Request$r,AccountingContext$ctx,CommercialDocumentService$svc){return$this->save($r,null,$ctx,$svc);}
    public function update(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){return$this->save($r,$id,$ctx,$svc);}
    public function destroy(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){try{$svc->deleteDraft($this->type(),$ctx->companyId($r),$id,$ctx->branchFilter($r));$this->audit($r,$ctx,$id,'DELETE');return response()->json(['status'=>true,'message'=>'تم حذف المسودة.']);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function transition(Request$r,int$id,string$status,AccountingContext$ctx,CommercialDocumentService$svc){try{$doc=$svc->transition($this->type(),$ctx->companyId($r),$id,$ctx->branchFilter($r),strtoupper($status),(int)$ctx->userId($r));$this->audit($r,$ctx,$id,'STATUS_'.strtoupper($status));return response()->json(['status'=>true,'data'=>$doc]);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function send(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){return$this->transition($r,$id,'SENT',$ctx,$svc);}
    public function accept(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){return$this->transition($r,$id,'ACCEPTED',$ctx,$svc);}
    public function reject(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){return$this->transition($r,$id,'REJECTED',$ctx,$svc);}
    public function approve(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){return$this->transition($r,$id,'APPROVED',$ctx,$svc);}
    public function cancel(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){return$this->transition($r,$id,'CANCELLED',$ctx,$svc);}
    public function convert(Request$r,int$id,AccountingContext$ctx,CommercialDocumentService$svc){try{$result=$svc->convert($this->type(),$ctx->companyId($r),$id,$ctx->branchFilter($r),(int)$ctx->userId($r));$this->audit($r,$ctx,$id,'CONVERT', ['invoice_id'=>$result['invoice_id'],'existing'=>$result['existing']]);return response()->json(['status'=>true,'data'=>$result]);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    private function save(Request$r,?int$id,AccountingContext$ctx,CommercialDocumentService$svc){$c=$svc->config($this->type());$v=$r->validate(['branch_id'=>'nullable|integer',$c['party_key']=>'required|integer','document_number'=>'nullable|string|max:100','document_date'=>'required|date',$c['date_key']=>'nullable|date|after_or_equal:document_date','currency_code'=>'nullable|string|max:10','exchange_rate'=>'nullable|numeric|gt:0','discount_amount'=>'nullable|numeric|min:0','notes'=>'nullable|string|max:5000','terms'=>'nullable|string|max:10000','items'=>'required|array|min:1|max:500','items.*.item_id'=>'required|integer','items.*.description'=>'nullable|string|max:1000','items.*.quantity'=>'required|numeric|gt:0','items.*.qty_kg'=>'nullable|numeric|gt:0','items.*.unit_code'=>'nullable|string|max:20','items.*.price_unit'=>'nullable|in:KG,TON,UNIT','items.*.unit_price'=>'required|numeric|min:0','items.*.discount_amount'=>'nullable|numeric|min:0','items.*.tax_code_id'=>'nullable|integer','items.*.vat_percent'=>'nullable|numeric|min:0|max:100']);try{$docId=$svc->save($this->type(),$v,$ctx->companyId($r),$ctx->branchForOperation($r),(int)$ctx->userId($r),$id);$this->audit($r,$ctx,$docId,$id?'UPDATE':'CREATE');return response()->json(['status'=>true,'id'=>$docId,'message'=>'تم حفظ المستند التجاري دون أي أثر محاسبي أو مخزني.'],$id?200:201);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    private function audit(Request$r,AccountingContext$ctx,int$id,string$action,array$after=[]):void{app(PrivilegedAuditService::class)->record($r,['actor_type'=>'COMPANY_USER','target_company_id'=>$ctx->companyId($r),'branch_id'=>$ctx->branchFilter($r),'resource'=>$this->type()==='QUOTATION'?'SalesQuotation':'PurchaseOrder','resource_id'=>$id,'action'=>$action,'after'=>$after]);}
}
