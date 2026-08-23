<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\FinancialAccountService;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    public function meta(Request $r,AccountingContext $c,FinancialAccountService $money)
    {
        $cid=$c->companyId($r);$scoped=$c->branchFilter($r);
        $branches=DB::table('branches')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where('id',$scoped))->orderBy('branch_name')->get(['id','branch_name','branch_code']);
        $suppliers=DB::table('suppliers')->where('company_id',$cid)->where('is_active',1)->orderBy('supplier_name')->get(['id','supplier_name','default_branch_id','scope_all_branches']);
        foreach($suppliers as$p)$p->branch_ids=(int)($p->scope_all_branches??0)===1?[]:DB::table('supplier_branches')->where('company_id',$cid)->where('supplier_id',$p->id)->where('is_active',1)->pluck('branch_id')->map(fn($x)=>(int)$x)->all();
        $customers=DB::table('customers')->where('company_id',$cid)->where('is_active',1)->orderBy('customer_name')->get(['id','customer_name','default_branch_id','scope_all_branches']);
        foreach($customers as$p)$p->branch_ids=(int)($p->scope_all_branches??0)===1?[]:DB::table('customer_branches')->where('company_id',$cid)->where('customer_id',$p->id)->where('is_active',1)->pluck('branch_id')->map(fn($x)=>(int)$x)->all();
        $cars=DB::table('cars')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$scoped)->orWhereNull('branch_id')))->orderBy('plate_number')->get(['id','branch_id','driver_id','supplier_id','car_number','plate_number','vehicle_type','owner_name','ownership_type','owner_party_type','owner_party_id']);
        $drivers=DB::table('drivers')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$scoped)->orWhereNull('branch_id')))->orderBy('driver_name')->get(['id','branch_id','driver_name','phone','affiliation_type','affiliation_id']);
        $items=DB::table('items as i')->leftJoin('item_groups as g','g.id','=','i.group_id')->leftJoin('item_categories as cat','cat.id','=','i.category_id')->where('i.company_id',$cid)->where('i.is_active',1)->orderBy('i.item_name')->get(['i.id','i.item_code','i.item_name','i.unit_name','i.default_buy_price','i.default_sell_price','i.item_type','i.track_inventory','i.can_purchase','i.can_sell','i.base_unit_code','i.commercial_unit_code','i.commercial_to_base_factor','g.group_name','cat.category_name']);
        $currencies=DB::table('company_currencies as cc')->join('currencies as cu','cu.currency_code','=','cc.currency_code')->where('cc.company_id',$cid)->where('cc.is_active',1)->where('cu.is_active',1)->orderByDesc('cc.is_base')->get(['cc.currency_code','cc.is_base','cu.currency_name','cu.symbol','cu.decimal_places']);
        $taxCodes=DB::table('tax_codes')->where('company_id',$cid)->where('is_active',1)->orderBy('tax_code')->get(['id','tax_code','tax_name','tax_type','rate','is_zero_rated','is_exempt','is_out_of_scope','is_default_sales','is_default_purchase']);
        $financial=DB::table('financial_accounts')->where('company_id',$cid)->where('is_active',1)->when($scoped!==null,fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$scoped)->orWhereNull('branch_id')))->orderBy('account_name')->get(['id','branch_id','account_name','account_type','currency_code','is_default_payment']);
        $costTypes=DB::table('expense_types')->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->where('is_active',1)->orderBy('type_name')->get(['id','type_name','type_code']);
        return response()->json(['status'=>true,'data'=>[
            'branches'=>$branches,'suppliers'=>$suppliers,'customers'=>$customers,'cars'=>$cars,'drivers'=>$drivers,'items'=>$items,'currencies'=>$currencies,'tax_codes'=>$taxCodes,
            'financial_accounts'=>$financial,'cost_types'=>$costTypes,'base_currency'=>$money->baseCurrency($cid),'scoped_branch_id'=>$scoped,
            'settings'=>DB::table('company_settings')->where('company_id',$cid)->first(),
        ]]);
    }

    public function index(Request $r,AccountingContext $c,ShipmentService $s)
    {return response()->json(['status'=>true,'data'=>$s->list($c->companyId($r),$c->branchFilter($r),$r->query('type'))]);}

    public function show(Request $r,int $id,AccountingContext $c,ShipmentService $s)
    {$d=$s->details($c->companyId($r),$id,$c->branchFilter($r));return $d?response()->json(['status'=>true,'data'=>$d]):response()->json(['status'=>false,'message'=>'الشحنة غير موجودة ضمن نطاقك.'],404);}

    public function store(Request $r,AccountingContext $c,ShipmentService $s){return $this->save($r,null,$c,$s);}
    public function update(Request $r,int $id,AccountingContext $c,ShipmentService $s){return $this->save($r,$id,$c,$s);}

    private function save(Request $r,?int $id,AccountingContext $c,ShipmentService $s)
    {
        $v=$r->validate([
            'branch_id'=>'nullable|integer','shipment_type'=>'required|in:PURCHASE,SALE,TRANSFER,INTERNAL','shipment_date'=>'required|date','supplier_id'=>'nullable|integer','customer_id'=>'nullable|integer',
            'car_id'=>'nullable|integer','driver_id'=>'nullable|integer','currency_code'=>'nullable|string|max:10','exchange_rate'=>'nullable|numeric|gt:0','cost_allocation_method'=>'nullable|in:RELATIVE_VALUE,WEIGHT,MANUAL_PERCENT,MANUAL_COST','notes'=>'nullable|string|max:4000',
            'items'=>'nullable|array|max:300','items.*.item_id'=>'required_with:items|integer','items.*.gross_qty_kg'=>'required_with:items|numeric|gt:0','items.*.deduction_qty_kg'=>'nullable|numeric|min:0','items.*.accepted_qty_kg'=>'nullable|numeric|min:0',
            'items.*.deduction_reason'=>'nullable|string|max:500','items.*.price_unit'=>'nullable|in:KG,TON','items.*.unit_price'=>'nullable|numeric|min:0','items.*.discount_amount'=>'nullable|numeric|min:0','items.*.tax_code_id'=>'nullable|integer','items.*.vat_percent'=>'nullable|numeric|min:0|max:100','items.*.cost_share_percent'=>'nullable|numeric|min:0|max:100','items.*.manual_allocated_cost'=>'nullable|numeric|min:0','items.*.notes'=>'nullable|string|max:1000',
        ]);
        try{
            $bid=$c->branchForOperation($r);$sid=$s->save($v,$c->companyId($r),$bid,$c->userId($r),$id);
            return response()->json(['status'=>true,'message'=>$id?'تم تحديث تجهيز الشحنة.':'تم إنشاء الشحنة كمسودة تشغيلية. ابدأ كروت الميزان ثم وزع الأصناف والتكاليف.','id'=>$sid,'data'=>$s->details($c->companyId($r),$sid,$c->branchFilter($r))],$id?200:201);
        }catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function ready(Request $r,int $id,AccountingContext $c,ShipmentService $s)
    {try{return response()->json(['status'=>true,'message'=>'الشحنة جاهزة للفوترة. لم يتم إنشاء مخزون أو قيد محاسبي بعد.','data'=>$s->ready($c->companyId($r),$id,$c->userId($r),$c->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}

    // Compatibility with old button: "approve" now means operational READY only, never invoice/accounting posting.
    public function approve(Request $r,int $id,AccountingContext $c,ShipmentService $s){return $this->ready($r,$id,$c,$s);}

    public function reopen(Request $r,int $id,AccountingContext $c,ShipmentService $s)
    {try{return response()->json(['status'=>true,'message'=>'تمت إعادة فتح الشحنة للتجهيز.','data'=>$s->reopen($c->companyId($r),$id,$c->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}

    public function destroy(Request $r,int $id,AccountingContext $c,ShipmentService $s)
    {try{$s->deleteDraft($c->companyId($r),$id,$c->branchFilter($r));return response()->json(['status'=>true,'message'=>'تم حذف مسودة الشحنة.']);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
}
