<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ItemController extends Controller
{
    public function meta(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);
        $postingAccounts=[];
        if(Schema::hasTable('accounts')){
            $cols=array_flip(Schema::getColumnListing('accounts'));
            $code=isset($cols['account_code'])?'account_code':(isset($cols['account_number'])?'account_number':(isset($cols['code'])?'code':'id'));
            $name=isset($cols['account_name'])?'account_name':(isset($cols['name'])?'name':$code);
            $q=DB::table('accounts');
            if(isset($cols['company_id']))$q->where('company_id',$cid);
            if(isset($cols['is_active']))$q->where('is_active',1);
            if(isset($cols['is_postable']))$q->where('is_postable',1);
            $postingAccounts=$q->select('id',DB::raw($code.' as account_code'),DB::raw($name.' as account_name'))->orderBy($code)->get();
        }
        return response()->json(['status'=>true,'data'=>[
            'groups'=>DB::table('item_groups')->where('company_id',$cid)->where('is_active',1)->orderBy('group_name')->get(),
            'categories'=>DB::table('item_categories')->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->where('is_active',1)->orderBy('category_name')->get(),
            'types'=>[['code'=>'STOCK','name'=>'صنف مخزني'],['code'=>'SERVICE','name'=>'خدمة']],
            'costing_methods'=>[['code'=>'FIFO','name'=>'FIFO - الوارد أولاً يصرف أولاً']],
            'base_units'=>[['code'=>'KG','name'=>'كيلوجرام']],
            'commercial_units'=>[['code'=>'TON','name'=>'طن'],['code'=>'KG','name'=>'كيلوجرام'],['code'=>'UNIT','name'=>'وحدة / خدمة']],
            'posting_accounts'=>$postingAccounts,
        ]]);
    }

    public function index(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);
        $q=DB::table('items as i')->leftJoin('item_groups as g','g.id','=','i.group_id')->leftJoin('item_categories as c','c.id','=','i.category_id')->where('i.company_id',$cid);
        if($r->filled('active'))$q->where('i.is_active',(int)$r->query('active'));
        if($r->filled('type'))$q->where('i.item_type',strtoupper((string)$r->query('type')));
        return response()->json(['status'=>true,'data'=>$q->select('i.*','g.group_name','c.category_name',DB::raw('(SELECT COALESCE(SUM(l.qty_remaining_kg),0) FROM inventory_lots l WHERE l.company_id=i.company_id AND l.item_id=i.id) stock_balance_kg'))->orderBy('i.item_name')->get()]);
    }

    public function store(Request $r,AccountingContext $ctx){return $this->save($r,$ctx,null);}
    public function update(Request $r,int $id,AccountingContext $ctx){return $this->save($r,$ctx,$id);}

    public function show(Request $r,int $id,AccountingContext $ctx)
    {
        $x=DB::table('items')->where('company_id',$ctx->companyId($r))->where('id',$id)->first();
        return $x?response()->json(['status'=>true,'data'=>$x]):response()->json(['status'=>false,'message'=>'الصنف غير موجود.'],404);
    }

    public function destroy(Request $r,int $id,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r);$item=DB::table('items')->where('company_id',$cid)->where('id',$id)->first();if(!$item)return response()->json(['status'=>false,'message'=>'الصنف غير موجود.'],404);
        $used=DB::table('inventory_lots')->where('company_id',$cid)->where('item_id',$id)->exists()||DB::table('shipment_items')->where('company_id',$cid)->where('item_id',$id)->exists()||DB::table('purchase_invoice_lines')->where('company_id',$cid)->where('item_id',$id)->exists()||DB::table('sales_invoice_lines')->where('company_id',$cid)->where('item_id',$id)->exists();
        if($used){DB::table('items')->where('id',$id)->update(['is_active'=>0,'updated_at'=>now()]);return response()->json(['status'=>true,'message'=>'الصنف له حركة تاريخية؛ تم تعطيله بدل حذفه.']);}
        DB::table('items')->where('id',$id)->delete();return response()->json(['status'=>true,'message'=>'تم حذف الصنف غير المستخدم.']);
    }

    public function storeGroup(Request $r,AccountingContext $ctx)
    {
        $v=$r->validate(['inventory_account_id'=>'nullable|integer','sales_account_id'=>'nullable|integer','cogs_account_id'=>'nullable|integer','purchase_expense_account_id'=>'nullable|integer','sales_return_account_id'=>'nullable|integer','purchase_return_account_id'=>'nullable|integer','group_code'=>'nullable|string|max:60','group_name'=>'required|string|max:180','notes'=>'nullable|string|max:1000','is_active'=>'nullable|boolean']);$cid=$ctx->companyId($r);
        if(!empty($v['group_code'])&&DB::table('item_groups')->where('company_id',$cid)->where('group_code',$v['group_code'])->exists())return response()->json(['status'=>false,'message'=>'كود المجموعة مستخدم مسبقًا.'],422);
        $id=DB::table('item_groups')->insertGetId(['company_id'=>$cid,'group_code'=>$v['group_code']??null,'group_name'=>$v['group_name'],'inventory_account_id'=>$v['inventory_account_id']??null,'sales_account_id'=>$v['sales_account_id']??null,'cogs_account_id'=>$v['cogs_account_id']??null,'purchase_expense_account_id'=>$v['purchase_expense_account_id']??null,'sales_return_account_id'=>$v['sales_return_account_id']??null,'purchase_return_account_id'=>$v['purchase_return_account_id']??null,'notes'=>$v['notes']??null,'is_active'=>(int)($v['is_active']??1),'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>true,'id'=>$id,'message'=>'تمت إضافة مجموعة الأصناف.'],201);
    }

    public function storeCategory(Request $r,AccountingContext $ctx)
    {
        $v=$r->validate(['inventory_account_id'=>'nullable|integer','sales_account_id'=>'nullable|integer','cogs_account_id'=>'nullable|integer','purchase_expense_account_id'=>'nullable|integer','sales_return_account_id'=>'nullable|integer','purchase_return_account_id'=>'nullable|integer','group_id'=>'nullable|integer','parent_id'=>'nullable|integer','category_code'=>'nullable|string|max:60','category_name'=>'required|string|max:255','notes'=>'nullable|string|max:1000','is_active'=>'nullable|boolean']);$cid=$ctx->companyId($r);
        if(!empty($v['group_id'])&&!DB::table('item_groups')->where('company_id',$cid)->where('id',$v['group_id'])->exists())return response()->json(['status'=>false,'message'=>'المجموعة لا تتبع الشركة.'],422);
        if(!empty($v['parent_id'])&&!DB::table('item_categories')->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->where('id',$v['parent_id'])->exists())return response()->json(['status'=>false,'message'=>'الفئة الأب غير صالحة.'],422);
        $id=DB::table('item_categories')->insertGetId(['company_id'=>$cid,'group_id'=>$v['group_id']??null,'parent_id'=>$v['parent_id']??null,'category_code'=>$v['category_code']??null,'category_name'=>$v['category_name'],'inventory_account_id'=>$v['inventory_account_id']??null,'sales_account_id'=>$v['sales_account_id']??null,'cogs_account_id'=>$v['cogs_account_id']??null,'purchase_expense_account_id'=>$v['purchase_expense_account_id']??null,'sales_return_account_id'=>$v['sales_return_account_id']??null,'purchase_return_account_id'=>$v['purchase_return_account_id']??null,'notes'=>$v['notes']??null,'is_active'=>(int)($v['is_active']??1),'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>true,'id'=>$id,'message'=>'تمت إضافة فئة الأصناف.'],201);
    }

    private function save(Request $r,AccountingContext $ctx,?int $id)
    {
        $v=$r->validate(['inventory_account_id'=>'nullable|integer','sales_account_id'=>'nullable|integer','cogs_account_id'=>'nullable|integer','purchase_expense_account_id'=>'nullable|integer','sales_return_account_id'=>'nullable|integer','purchase_return_account_id'=>'nullable|integer','group_id'=>'nullable|integer','category_id'=>'nullable|integer','item_code'=>'nullable|string|max:50','item_name'=>'required|string|max:255','item_grade'=>'nullable|string|max:100','item_type'=>'required|in:STOCK,SERVICE','track_inventory'=>'nullable|boolean','allow_negative_stock'=>'nullable|boolean','can_purchase'=>'nullable|boolean','can_sell'=>'nullable|boolean','base_unit_code'=>'nullable|string|max:20','commercial_unit_code'=>'nullable|string|max:20','commercial_to_base_factor'=>'nullable|numeric|gt:0','costing_method'=>'nullable|in:FIFO','default_buy_price'=>'nullable|numeric|min:0','default_sell_price'=>'nullable|numeric|min:0','min_sell_price'=>'nullable|numeric|min:0','is_waste_item'=>'nullable|boolean','is_byproduct'=>'nullable|boolean','color_notes'=>'nullable|string|max:255','notes'=>'nullable|string','is_active'=>'nullable|boolean']);
        $cid=$ctx->companyId($r);$type=strtoupper($v['item_type']);$track=$type==='SERVICE'?0:(int)($v['track_inventory']??1);
        if(!empty($v['group_id'])&&!DB::table('item_groups')->where('company_id',$cid)->where('id',$v['group_id'])->exists())return response()->json(['status'=>false,'message'=>'مجموعة الصنف لا تتبع الشركة.'],422);
        if(!empty($v['category_id'])&&!DB::table('item_categories')->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->where('id',$v['category_id'])->exists())return response()->json(['status'=>false,'message'=>'فئة الصنف غير صالحة.'],422);
        if(!empty($v['item_code'])&&DB::table('items')->where('company_id',$cid)->where('item_code',$v['item_code'])->when($id,fn($q)=>$q->where('id','<>',$id))->exists())return response()->json(['status'=>false,'message'=>'كود الصنف مستخدم داخل الشركة.'],422);
        $data=['group_id'=>$v['group_id']??null,'category_id'=>$v['category_id']??null,'item_code'=>$v['item_code']??null,'item_name'=>$v['item_name'],'item_grade'=>$v['item_grade']??null,'item_type'=>$type,'track_inventory'=>$track,'allow_negative_stock'=>0,'can_purchase'=>(int)($v['can_purchase']??1),'can_sell'=>(int)($v['can_sell']??1),'base_unit_code'=>$type==='SERVICE'?'UNIT':($v['base_unit_code']??'KG'),'commercial_unit_code'=>$type==='SERVICE'?'UNIT':($v['commercial_unit_code']??'TON'),'commercial_to_base_factor'=>$type==='SERVICE'?1:(float)($v['commercial_to_base_factor']??1000),'costing_method'=>'FIFO','unit_name'=>$type==='SERVICE'?'خدمة':(($v['commercial_unit_code']??'TON')==='TON'?'طن':'كجم'),'default_buy_price'=>(float)($v['default_buy_price']??0),'default_sell_price'=>(float)($v['default_sell_price']??0),'min_sell_price'=>(float)($v['min_sell_price']??0),'is_waste_item'=>(int)($v['is_waste_item']??0),'is_byproduct'=>(int)($v['is_byproduct']??0),'inventory_account_id'=>$v['inventory_account_id']??null,'sales_account_id'=>$v['sales_account_id']??null,'cogs_account_id'=>$v['cogs_account_id']??null,'purchase_expense_account_id'=>$v['purchase_expense_account_id']??null,'sales_return_account_id'=>$v['sales_return_account_id']??null,'purchase_return_account_id'=>$v['purchase_return_account_id']??null,'color_notes'=>$v['color_notes']??null,'notes'=>$v['notes']??null,'is_active'=>(int)($v['is_active']??1),'updated_at'=>now()];
        if($id){if(!DB::table('items')->where('company_id',$cid)->where('id',$id)->exists())return response()->json(['status'=>false,'message'=>'الصنف غير موجود.'],404);DB::table('items')->where('id',$id)->update($data);return response()->json(['status'=>true,'message'=>'تم تحديث الصنف.']);}
        $new=DB::table('items')->insertGetId(['company_id'=>$cid,...$data,'created_at'=>now()]);return response()->json(['status'=>true,'id'=>$new,'message'=>'تم إنشاء الصنف.'],201);
    }
}
