<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Services\AccountingBootstrapService;
use App\Http\Controllers\Controller;
use App\Services\EntityAddressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class BranchController extends Controller
{
    private const COMPANY_MANAGER_ROLES = ['COMPANY_MANAGER','COMPANY_ADMIN','COMPANY_OWNER','MANAGER','ADMIN'];

    private function companyId(): ?int { $v=request()->header('X-Company-ID'); return is_numeric($v)?(int)$v:null; }
    private function branchId(): ?int { $v=request()->header('X-Branch-ID'); return is_numeric($v)?(int)$v:null; }
    private function roleCode(): string { return strtoupper(trim((string)request()->header('X-Role-Code',''))); }
    private function isSuper(): bool { return $this->roleCode()==='SUPER_ADMIN'; }
    private function isCompanyManager(): bool { return in_array($this->roleCode(),self::COMPANY_MANAGER_ROLES,true); }
    private function isBranchManager(): bool { return $this->roleCode()==='BRANCH_MANAGER'; }
    private function canViewBranches(): bool { return $this->isSuper()||$this->isCompanyManager()||$this->isBranchManager(); }
    private function canManageBranches(): bool { return $this->isSuper()||$this->isCompanyManager(); }

    private function rules(int $companyId,?int $ignoreId=null): array
    {
        $code=Rule::unique('branches','branch_code')->where(fn($q)=>$q->where('company_id',$companyId)); if($ignoreId)$code->ignore($ignoreId);
        return [
            'company_id'=>'required|integer|exists:companies,id','branch_name'=>['required','string','min:2','max:255'], 'branch_code'=>['nullable','string','min:2','max:50','regex:/^[A-Za-z0-9_-]+$/',$code],
            'legal_name'=>'nullable|string|max:255','phone'=>'nullable|string|max:50','email'=>'nullable|email|max:150','registration_number'=>'nullable|string|max:120','tax_number'=>'nullable|string|max:120','country_code'=>'nullable|string|size:2',
            'short_address'=>'nullable|string|max:100','building_no'=>'nullable|string|max:50','street_name'=>'nullable|string|max:200','district'=>'nullable|string|max:150','city'=>'nullable|string|max:150','state_region'=>'nullable|string|max:150','postal_code'=>'nullable|string|max:50','additional_no'=>'nullable|string|max:50','unit_no'=>'nullable|string|max:50','address'=>'nullable|string|max:500','address_line1'=>'nullable|string|max:500','address_line2'=>'nullable|string|max:500','is_active'=>'nullable|boolean',
        ];
    }

    private function normalize(Request $r,int $companyId): void
    {
        $clean=fn($v)=>trim((string)$v)===''?null:trim((string)$v);
        $r->merge([
            'company_id'=>$companyId,'branch_code'=>$r->filled('branch_code')?strtoupper(trim((string)$r->branch_code)):null,'branch_name'=>trim((string)$r->branch_name),
            'legal_name'=>$clean($r->legal_name),'phone'=>$clean($r->phone),'email'=>$clean($r->email),'registration_number'=>$clean($r->registration_number),'tax_number'=>$clean($r->tax_number),'country_code'=>$r->filled('country_code')?strtoupper(trim((string)$r->country_code)):null,
            'short_address'=>$clean($r->short_address),'building_no'=>$clean($r->building_no),'street_name'=>$clean($r->street_name),'district'=>$clean($r->district),'city'=>$clean($r->city),'state_region'=>$clean($r->state_region),'postal_code'=>$clean($r->postal_code),'additional_no'=>$clean($r->additional_no),'unit_no'=>$clean($r->unit_no),'address'=>$clean($r->address),'address_line1'=>$clean($r->address_line1?:$r->address),'address_line2'=>$clean($r->address_line2),'is_active'=>(int)($r->is_active??1),
        ]);
    }

    private function fail(Throwable $e,string $message){report($e);$x=['status'=>false,'message'=>$message];if(app()->isLocal())$x['technical_message']=$e->getMessage();return response()->json($x,500);}

    public function index(EntityAddressService $addresses)
    {
        if(!$this->canViewBranches()) return response()->json(['status'=>false,'message'=>'لا تملك صلاحية عرض الفروع.','data'=>[]],403);
        $cid=$this->companyId();$bid=$this->branchId();
        $q=DB::table('branches as b')->leftJoin('companies as c','c.id','=','b.company_id')->leftJoin('branch_financial_settings as bfs',function($j){$j->on('bfs.company_id','=','b.company_id')->on('bfs.branch_id','=','b.id');})->leftJoin('cost_centers as cc','cc.id','=','bfs.default_cost_center_id')->leftJoin('financial_accounts as fa','fa.id','=','bfs.default_cash_financial_account_id');
        if(!$this->isSuper()){if(!$cid)return response()->json(['status'=>false,'message'=>'تعذر تحديد الشركة الحالية.','data'=>[]],403);$q->where('b.company_id',$cid);} if($this->isBranchManager()){if(!$bid)return response()->json(['status'=>false,'message'=>'تعذر تحديد الفرع الحالي.','data'=>[]],403);$q->where('b.id',$bid);}
        $rows=$q->select('b.*','c.company_name','cc.id as cost_center_id','cc.cost_center_code','cc.cost_center_name','fa.id as default_cash_financial_account_id','fa.account_name as default_cash_name',DB::raw('(SELECT COUNT(*) FROM users u WHERE u.branch_id=b.id AND u.is_active=1) as users_count'))->orderByDesc('b.id')->get();
        foreach($rows as $row)$row->address_details=$addresses->getDefault((int)$row->company_id,'BRANCH',(int)$row->id);
        return response()->json(['status'=>true,'data'=>$rows]);
    }

    public function store(Request $r,AccountingBootstrapService $bootstrap,EntityAddressService $addresses)
    {
        if(!$this->canManageBranches())return response()->json(['status'=>false,'message'=>'لا تملك صلاحية إنشاء فرع.'],403);
        $current=$this->companyId();if(!$this->isSuper()&&!$current)return response()->json(['status'=>false,'message'=>'تعذر تحديد الشركة الحالية.'],403);
        $target=$this->isSuper()?$r->integer('company_id'):(int)$current;if(!$target)return response()->json(['status'=>false,'message'=>'اختر الشركة التي يتبع لها الفرع.'],422);
        if(!$this->isSuper()&&$r->filled('company_id')&&$r->integer('company_id')!==$target)return response()->json(['status'=>false,'message'=>'غير مسموح بإنشاء فرع لشركة أخرى.'],403);
        $this->normalize($r,$target);$v=$r->validate($this->rules($target));
        try{return DB::transaction(function()use($target,$v,$bootstrap,$addresses){
            $id=DB::table('branches')->insertGetId(['company_id'=>$target,'branch_code'=>$v['branch_code']??null,'branch_name'=>$v['branch_name'],'legal_name'=>$v['legal_name']??null,'phone'=>$v['phone']??null,'email'=>$v['email']??null,'registration_number'=>$v['registration_number']??null,'tax_number'=>$v['tax_number']??null,'country_code'=>$v['country_code']??null,'city'=>$v['city']??null,'address'=>$v['address_line1']??$v['address']??null,'is_active'=>(int)($v['is_active']??1),'created_at'=>now(),'updated_at'=>now()]);
            $cc=$bootstrap->bootstrapBranch($target,$id,$v['branch_name']);$addresses->upsertDefault($target,'BRANCH',$id,$v);
            $fa=Schema::hasTable('branch_financial_settings')?DB::table('branch_financial_settings')->where('company_id',$target)->where('branch_id',$id)->value('default_cash_financial_account_id'):null;
            return response()->json(['status'=>true,'message'=>'تم إنشاء الفرع ومركز التكلفة والصندوق التشغيلي الافتراضي بنجاح.','data'=>['branch_id'=>$id,'cost_center_id'=>$cc,'default_cash_financial_account_id'=>$fa]],201);
        });}catch(Throwable$e){return$this->fail($e,'تعذر إنشاء الفرع. تحقق من البيانات وحاول مرة أخرى.');}
    }

    public function show($id,EntityAddressService $addresses)
    {
        if(!$this->canViewBranches())return response()->json(['status'=>false,'message'=>'لا تملك صلاحية عرض الفرع.'],403);
        $q=DB::table('branches as b')->leftJoin('companies as c','c.id','=','b.company_id')->leftJoin('branch_financial_settings as bfs',function($j){$j->on('bfs.company_id','=','b.company_id')->on('bfs.branch_id','=','b.id');})->leftJoin('cost_centers as cc','cc.id','=','bfs.default_cost_center_id')->leftJoin('financial_accounts as fa','fa.id','=','bfs.default_cash_financial_account_id')->select('b.*','c.company_name','cc.id as cost_center_id','cc.cost_center_code','cc.cost_center_name','fa.id as default_cash_financial_account_id','fa.account_name as default_cash_name')->where('b.id',$id);
        if(!$this->isSuper())$q->where('b.company_id',$this->companyId());if($this->isBranchManager())$q->where('b.id',$this->branchId());$row=$q->first();if(!$row)return response()->json(['status'=>false,'message'=>'الفرع غير موجود أو غير مسموح بعرضه.'],404);
        $row->address_details=$addresses->getDefault((int)$row->company_id,'BRANCH',(int)$row->id);return response()->json(['status'=>true,'data'=>$row]);
    }

    public function update(Request $r,$id,AccountingBootstrapService $bootstrap,EntityAddressService $addresses)
    {
        if(!$this->canManageBranches())return response()->json(['status'=>false,'message'=>'لا تملك صلاحية تعديل الفرع.'],403);
        $q=DB::table('branches')->where('id',$id);if(!$this->isSuper())$q->where('company_id',$this->companyId());$branch=$q->first();if(!$branch)return response()->json(['status'=>false,'message'=>'الفرع غير موجود أو غير مسموح بتعديله.'],404);
        $target=(int)$branch->company_id;if($r->filled('company_id')&&$r->integer('company_id')!==$target)return response()->json(['status'=>false,'message'=>'لا يمكن نقل الفرع إلى شركة أخرى.'],422);
        $this->normalize($r,$target);$v=$r->validate($this->rules($target,(int)$id));
        try{return DB::transaction(function()use($target,$id,$v,$bootstrap,$addresses){
            DB::table('branches')->where('company_id',$target)->where('id',$id)->update(['branch_code'=>$v['branch_code']??null,'branch_name'=>$v['branch_name'],'legal_name'=>$v['legal_name']??null,'phone'=>$v['phone']??null,'email'=>$v['email']??null,'registration_number'=>$v['registration_number']??null,'tax_number'=>$v['tax_number']??null,'country_code'=>$v['country_code']??null,'city'=>$v['city']??null,'address'=>$v['address_line1']??$v['address']??null,'is_active'=>(int)($v['is_active']??1),'updated_at'=>now()]);
            $cc=$bootstrap->bootstrapBranch($target,(int)$id,$v['branch_name']);DB::table('cost_centers')->where('id',$cc)->update(['cost_center_name'=>'مركز تكلفة '.$v['branch_name'],'is_active'=>(int)($v['is_active']??1),'updated_at'=>now()]);
            if(Schema::hasTable('financial_accounts'))DB::table('financial_accounts')->where('company_id',$target)->where('branch_id',$id)->where('account_code','CASH-BR-'.$id)->update(['account_name'=>'صندوق '.$v['branch_name'],'is_active'=>(int)($v['is_active']??1),'updated_at'=>now()]);
            $addresses->upsertDefault($target,'BRANCH',(int)$id,$v);return response()->json(['status'=>true,'message'=>'تم تحديث الفرع وبياناته القانونية والعنوان وإعداداته المالية.','data'=>['branch_id'=>(int)$id,'cost_center_id'=>$cc]]);
        });}catch(Throwable$e){return$this->fail($e,'تعذر تحديث الفرع. تحقق من البيانات وحاول مرة أخرى.');}
    }

    public function destroy($id)
    {
        if(!$this->canManageBranches())return response()->json(['status'=>false,'message'=>'لا تملك صلاحية حذف الفرع.'],403);
        $q=DB::table('branches')->where('id',$id);if(!$this->isSuper())$q->where('company_id',$this->companyId());$branch=$q->first();if(!$branch)return response()->json(['status'=>false,'message'=>'الفرع غير موجود أو غير مسموح بحذفه.'],404);
        $refs=[['users','branch_id','مستخدمين'],['journal_entries','branch_id','قيود محاسبية'],['journal_entry_lines','branch_id','تفاصيل قيود'],['purchase_invoices','branch_id','فواتير مشتريات'],['sales_invoices','branch_id','فواتير مبيعات'],['shipments','branch_id','شحنات'],['stock_movements','branch_id','حركات مخزون'],['inventory_operations','branch_id','عمليات مخزنية'],['expenses','branch_id','مصروفات'],['vouchers','branch_id','سندات مالية'],['workers','branch_id','عاملين'],['fixed_assets','branch_id','أصول ثابتة'],['customer_branches','branch_id','عملاء مرتبطين'],['supplier_branches','branch_id','موردين مرتبطين'],['opening_balance_lines','branch_id','أرصدة افتتاحية'],['opening_inventory_lines','branch_id','مخزون افتتاحي'],['opening_fixed_asset_lines','branch_id','أصول افتتاحية']];
        foreach($refs as[$table,$col,$label])if(Schema::hasTable($table)&&Schema::hasColumn($table,$col)&&DB::table($table)->where($col,$id)->exists())return response()->json(['status'=>false,'message'=>"لا يمكن حذف الفرع لأنه مرتبط بـ {$label}. عطّل الفرع بدلًا من حذفه."],422);
        try{return DB::transaction(function()use($branch,$id){
            if(Schema::hasTable('branch_financial_settings'))DB::table('branch_financial_settings')->where('company_id',$branch->company_id)->where('branch_id',$id)->delete();
            if(Schema::hasTable('financial_accounts'))DB::table('financial_accounts')->where('company_id',$branch->company_id)->where('branch_id',$id)->delete();
            if(Schema::hasTable('entity_addresses'))DB::table('entity_addresses')->where('company_id',$branch->company_id)->where('entity_type','BRANCH')->where('entity_id',$id)->delete();
            DB::table('cost_centers')->where('company_id',$branch->company_id)->where('branch_id',$id)->delete();DB::table('branches')->where('company_id',$branch->company_id)->where('id',$id)->delete();return response()->json(['status'=>true,'message'=>'تم حذف الفرع وبنيته التشغيلية غير المستخدمة بنجاح.']);
        });}catch(Throwable$e){return$this->fail($e,'لا يمكن حذف الفرع لأنه مرتبط ببيانات أخرى. عطّل الفرع بدلًا من حذفه.');}
    }
}
