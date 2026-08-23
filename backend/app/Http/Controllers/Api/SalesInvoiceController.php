<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\EnterpriseInvoiceService;
use App\Services\DefaultPartyService;
use App\Services\FinancialAccountService;
use App\Services\InventoryLotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesInvoiceController extends Controller
{
    public function meta(Request $r,AccountingContext $ctx,FinancialAccountService $money,InventoryLotService $lots,DefaultPartyService $defaults)
    {
        $cid=$ctx->companyId($r);$scoped=$ctx->branchFilter($r);$defaultSetup=$defaults->ensure($cid,$ctx->userId($r));
        $branches=DB::table('branches')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where('id',$scoped))->orderBy('branch_name')->get(['id','branch_name','branch_code']);
        $parties=DB::table('customers')->where('company_id',$cid)->where('is_active',1)->orderBy('customer_name')->get(['id','customer_name','default_branch_id','scope_all_branches','is_system_default']);
        foreach($parties as$p)$p->branch_ids=(int)($p->scope_all_branches??0)===1?[]:DB::table('customer_branches')->where('company_id',$cid)->where('customer_id',$p->id)->where('is_active',1)->pluck('branch_id')->map(fn($x)=>(int)$x)->all();
        $cars=DB::table('cars')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$scoped)->orWhereNull('branch_id')))->orderBy('plate_number')->get(['id','branch_id','car_number','plate_number','ownership_type']);
        $items=DB::table('items')->where('company_id',$cid)->where('is_active',1)->where('can_sell',1)->orderBy('item_name')->get(['id','item_code','item_name','unit_name','default_buy_price','default_sell_price','item_type','track_inventory','base_unit_code','commercial_unit_code','commercial_to_base_factor']);
        $currencies=DB::table('company_currencies as cc')->join('currencies as cu','cu.currency_code','=','cc.currency_code')->where('cc.company_id',$cid)->where('cc.is_active',1)->where('cu.is_active',1)->orderByDesc('cc.is_base')->get(['cc.currency_code','cc.is_base','cu.currency_name','cu.symbol','cu.decimal_places']);
        $taxCodes=DB::table('tax_codes')->where('company_id',$cid)->where('is_active',1)->orderBy('tax_code')->get(['id','tax_code','tax_name','rate','is_zero_rated','is_exempt','is_out_of_scope','is_default_sales']);
        $ready=DB::table('shipments as sh')->leftJoin('customers as c','c.id','=','sh.customer_id')->where('sh.company_id',$cid)->where('sh.shipment_type','SALE')->where('sh.commercial_status','READY')->when($scoped!==null,fn($q)=>$q->where('sh.branch_id',$scoped))->whereNotExists(fn($q)=>$q->select(DB::raw(1))->from('invoice_shipment_links as l')->whereColumn('l.shipment_id','sh.id')->whereColumn('l.company_id','sh.company_id'))->select('sh.id','sh.branch_id','sh.customer_id','sh.shipment_number','sh.shipment_date','sh.accepted_weight_kg',DB::raw('sh.total_before_discount as total_before_vat'),'sh.vat_amount','sh.total_amount','c.customer_name')->orderByDesc('sh.id')->get();
        return response()->json(['status'=>true,'data'=>['branches'=>$branches,'parties'=>$parties,'cars'=>$cars,'items'=>$items,'currencies'=>$currencies,'tax_codes'=>$taxCodes,'ready_shipments'=>$ready,'inventory'=>$lots->summary($cid,$scoped),'base_currency'=>$money->baseCurrency($cid),'scoped_branch_id'=>$scoped,'settings'=>DB::table('company_settings')->where('company_id',$cid)->first(),'default_parties'=>$defaultSetup]]);
    }

    public function index(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);
        $q=DB::table('sales_invoices as p')->leftJoin('customers as c','c.id','=','p.customer_id')->leftJoin('cars as car','car.id','=','p.car_id')->leftJoin('branches as b','b.id','=','p.branch_id')->where('p.company_id',$cid);
        if($bid!==null)$q->where('p.branch_id',$bid);if($r->filled('status'))$q->where('p.document_status',strtoupper((string)$r->query('status')));
        return response()->json(['status'=>true,'data'=>$q->select('p.*','c.customer_name','car.car_number','car.plate_number','b.branch_name',DB::raw("(SELECT COUNT(*) FROM invoice_shipment_links l WHERE l.company_id=p.company_id AND l.invoice_type='SALE' AND l.invoice_id=p.id) shipment_count"))->orderByDesc('p.invoice_date')->orderByDesc('p.id')->limit(2000)->get()]);
    }

    public function show(Request $r,int $id,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$q=DB::table('sales_invoices as p')->leftJoin('customers as c','c.id','=','p.customer_id')->leftJoin('branches as b','b.id','=','p.branch_id')->leftJoin('cars as car','car.id','=','p.car_id')->where('p.company_id',$cid)->where('p.id',$id);if($bid!==null)$q->where('p.branch_id',$bid);
        $inv=$q->select('p.*','c.customer_name','b.branch_name','car.car_number','car.plate_number')->first();if(!$inv)return response()->json(['status'=>false,'message'=>'فاتورة البيع غير موجودة.'],404);
        $lines=DB::table('sales_invoice_lines as l')->leftJoin('items as i','i.id','=','l.item_id')->leftJoin('shipments as sh','sh.id','=','l.shipment_id')->where('l.company_id',$cid)->where('l.sales_invoice_id',$id)->select('l.*','i.item_name','i.item_code','sh.shipment_number')->orderBy('l.id')->get();
        $shipments=DB::table('invoice_shipment_links as x')->join('shipments as sh','sh.id','=','x.shipment_id')->where('x.company_id',$cid)->where('x.invoice_type','SALE')->where('x.invoice_id',$id)->select('x.*','sh.shipment_number','sh.shipment_date','sh.commercial_status')->orderBy('x.id')->get();
        return response()->json(['status'=>true,'data'=>['invoice'=>$inv,'lines'=>$lines,'shipments'=>$shipments]]);
    }

    public function store(Request $r,AccountingContext $ctx,EnterpriseInvoiceService $service){return $this->save($r,null,$ctx,$service);}
    public function update(Request $r,int $id,AccountingContext $ctx,EnterpriseInvoiceService $service){return $this->save($r,$id,$ctx,$service);}
    public function post(Request $r,int $id,AccountingContext $ctx,EnterpriseInvoiceService $service){try{return response()->json(['status'=>true,'data'=>$service->post('SALE',$ctx->companyId($r),$id,$ctx->userId($r),$ctx->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function void(Request $r,int $id,AccountingContext $ctx,EnterpriseInvoiceService $service){$v=$r->validate(['reason'=>'required|string|min:5|max:2000']);try{return response()->json(['status'=>true,'data'=>$service->void('SALE',$ctx->companyId($r),$id,$ctx->userId($r),$v['reason'],$ctx->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function destroy(Request $r,int $id,AccountingContext $ctx,EnterpriseInvoiceService $service){try{$service->deleteDraft('SALE',$ctx->companyId($r),$id,$ctx->branchFilter($r));return response()->json(['status'=>true,'message'=>'تم حذف مسودة فاتورة البيع.']);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}

    private function save(Request $r,?int $id,AccountingContext $ctx,EnterpriseInvoiceService $service)
    {
        $v=$r->validate(['branch_id'=>'nullable|integer','customer_id'=>'nullable|integer','car_id'=>'nullable|integer','invoice_number'=>'nullable|string|max:100','invoice_date'=>'required|date','document_type'=>'nullable|string|max:40','currency_code'=>'nullable|string|max:10','exchange_rate'=>'nullable|numeric|gt:0','discount_amount'=>'nullable|numeric|min:0','commission_amount'=>'nullable|numeric|min:0','transport_cost'=>'nullable|numeric|min:0','extra_cost'=>'nullable|numeric|min:0','notes'=>'nullable|string|max:5000','shipment_ids'=>'nullable|array|max:200','shipment_ids.*'=>'integer','items'=>'nullable|array|max:500','items.*.item_id'=>'required_with:items|integer','items.*.qty_kg'=>'nullable|numeric|gt:0','items.*.qty'=>'nullable|numeric|gt:0','items.*.quantity'=>'nullable|numeric|gt:0','items.*.unit_code'=>'nullable|string|max:20','items.*.price_unit'=>'nullable|in:KG,TON,UNIT','items.*.unit_price'=>'required_with:items|numeric|min:0','items.*.discount_amount'=>'nullable|numeric|min:0','items.*.tax_code_id'=>'nullable|integer','items.*.vat_percent'=>'nullable|numeric|min:0|max:100','items.*.shipment_id'=>'nullable|integer','items.*.shipment_item_id'=>'nullable|integer','items.*.notes'=>'nullable|string|max:1000']);
        try{$bid=$ctx->branchForOperation($r);$newId=$service->saveDraft('SALE',$v,$ctx->companyId($r),$bid,$ctx->userId($r),$id);return response()->json(['status'=>true,'message'=>$id?'تم تحديث مسودة فاتورة البيع.':'تم حفظ فاتورة البيع كمسودة. لم ينقص المخزون ولم ينشأ قيد حتى الترحيل.','id'=>$newId],$id?200:201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }
}
