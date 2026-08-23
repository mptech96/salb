<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\EntityAddressService;
use App\Services\PartyBranchScopeService;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    use LogsActivity;

    public function index(Request $r,AccountingContext $ctx,PartyBranchScopeService $scope)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);
        $q=DB::table('suppliers as s')->leftJoin('branches as b','b.id','=','s.default_branch_id')->where('s.company_id',$cid)
            ->select('s.*','b.branch_name as default_branch_name');
        $scope->scopeQuery($q,$cid,'SUPPLIER',$bid);
        if($r->filled('search')){$x=trim((string)$r->search);$q->where(function($z)use($x){$z->where('s.supplier_name','like','%'.$x.'%')->orWhere('s.supplier_code','like','%'.$x.'%')->orWhere('s.phone','like','%'.$x.'%')->orWhere('s.tax_number','like','%'.$x.'%');});}
        if($r->filled('is_active'))$q->where('s.is_active',(int)$r->is_active);
        $rows=$q->orderBy('s.supplier_name')->get();
        foreach($rows as $row)$row->branch_ids=$scope->branchIds($cid,'SUPPLIER',(int)$row->id);
        return response()->json(['status'=>true,'data'=>$rows]);
    }

    public function store(Request $r,AccountingContext $ctx,PartyBranchScopeService $scope,EntityAddressService $addresses)
    {
        $cid=$ctx->companyId($r);$v=$this->validateData($r,$cid);$scoped=$ctx->branchFilter($r);
        if($scoped!==null){$v['scope_all_branches']=false;$v['branch_ids']=[$scoped];$v['default_branch_id']=$scoped;}
        elseif(empty($v['scope_all_branches'])&&empty($v['branch_ids'])&&empty($v['default_branch_id'])){$fallback=$ctx->branchForOperation($r);$v['branch_ids']=[$fallback];$v['default_branch_id']=$fallback;}
        try{return DB::transaction(function()use($cid,$v,$scope,$addresses){
            $id=DB::table('suppliers')->insertGetId(['company_id'=>$cid,'branch_id'=>$v['default_branch_id']??null,'default_branch_id'=>$v['default_branch_id']??null,'scope_all_branches'=>(int)($v['scope_all_branches']??0),
                'supplier_code'=>$this->clean($v['supplier_code']??null),'supplier_name'=>trim($v['supplier_name']),'legal_name'=>$this->clean($v['legal_name']??null),'phone'=>$this->clean($v['phone']??null),'email'=>$this->clean($v['email']??null),
                'registration_number'=>$this->clean($v['registration_number']??null),'tax_number'=>$this->clean($v['tax_number']??null),'country_code'=>$this->upper($v['country_code']??null),
                'city'=>$this->clean($v['city']??null),'address'=>$this->clean($v['address']??null),'opening_balance'=>0,'notes'=>$this->clean($v['notes']??null),'is_active'=>(int)($v['is_active']??1),'created_at'=>now(),'updated_at'=>now()]);
            $scope->sync($cid,'SUPPLIER',$id,(bool)($v['scope_all_branches']??false),$v['branch_ids']??[],$v['default_branch_id']??null);$addresses->upsertDefault($cid,'SUPPLIER',$id,$v);
            $this->logCreate('Suppliers',$id,'تم إنشاء المورد: '.$v['supplier_name']);return response()->json(['status'=>true,'message'=>'تم إنشاء المورد بنجاح.','id'=>$id],201);
        });}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function show(Request $r,int $id,AccountingContext $ctx,PartyBranchScopeService $scope,EntityAddressService $addresses)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$q=DB::table('suppliers as s')->where('s.company_id',$cid)->where('s.id',$id);$scope->scopeQuery($q,$cid,'SUPPLIER',$bid);$row=$q->first();
        if(!$row)return response()->json(['status'=>false,'message'=>'المورد غير موجود أو خارج نطاقك.'],404);$row->branch_ids=$scope->branchIds($cid,'SUPPLIER',$id);$row->address_details=$addresses->getDefault($cid,'SUPPLIER',$id);
        return response()->json(['status'=>true,'data'=>$row]);
    }

    public function update(Request $r,int $id,AccountingContext $ctx,PartyBranchScopeService $scope,EntityAddressService $addresses)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$existing=DB::table('suppliers as s')->where('s.company_id',$cid)->where('s.id',$id);$scope->scopeQuery($existing,$cid,'SUPPLIER',$bid);if(!$existing->exists())return response()->json(['status'=>false,'message'=>'المورد غير موجود أو خارج نطاقك.'],404);
        $v=$this->validateData($r,$cid,$id);if($bid!==null){$v['scope_all_branches']=false;$v['branch_ids']=[$bid];$v['default_branch_id']=$bid;}
        try{return DB::transaction(function()use($cid,$id,$v,$scope,$addresses){
            DB::table('suppliers')->where('company_id',$cid)->where('id',$id)->update(['supplier_code'=>$this->clean($v['supplier_code']??null),'supplier_name'=>trim($v['supplier_name']),'legal_name'=>$this->clean($v['legal_name']??null),'phone'=>$this->clean($v['phone']??null),'email'=>$this->clean($v['email']??null),'registration_number'=>$this->clean($v['registration_number']??null),'tax_number'=>$this->clean($v['tax_number']??null),'country_code'=>$this->upper($v['country_code']??null),'city'=>$this->clean($v['city']??null),'address'=>$this->clean($v['address']??null),'notes'=>$this->clean($v['notes']??null),'is_active'=>(int)($v['is_active']??1),'updated_at'=>now()]);
            $scope->sync($cid,'SUPPLIER',$id,(bool)($v['scope_all_branches']??false),$v['branch_ids']??[],$v['default_branch_id']??null);$addresses->upsertDefault($cid,'SUPPLIER',$id,$v);$this->logUpdate('Suppliers',$id,'تم تعديل المورد: '.$v['supplier_name']);return response()->json(['status'=>true,'message'=>'تم تحديث المورد بنجاح.']);
        });}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function destroy(Request $r,int $id,AccountingContext $ctx,PartyBranchScopeService $scope)
    {
        $cid=$ctx->companyId($r);$bid=$ctx->branchFilter($r);$q=DB::table('suppliers as s')->where('s.company_id',$cid)->where('s.id',$id);$scope->scopeQuery($q,$cid,'SUPPLIER',$bid);if(!$q->exists())return response()->json(['status'=>false,'message'=>'المورد غير موجود أو خارج نطاقك.'],404);
        $used=DB::table('purchase_invoices')->where('company_id',$cid)->where('supplier_id',$id)->exists()||DB::table('journal_entry_lines')->where('company_id',$cid)->where('party_type','SUPPLIER')->where('party_id',$id)->exists();
        if($used){DB::table('suppliers')->where('company_id',$cid)->where('id',$id)->update(['is_active'=>0,'updated_at'=>now()]);return response()->json(['status'=>true,'message'=>'المورد مستخدم في حركات سابقة، لذلك تم تعطيله مع المحافظة على التاريخ.']);}
        DB::table('suppliers')->where('company_id',$cid)->where('id',$id)->delete();return response()->json(['status'=>true,'message'=>'تم حذف المورد.']);
    }

    private function validateData(Request $r,int $cid,?int $id=null): array
    {
        return $r->validate(['supplier_name'=>'required|string|max:255','supplier_code'=>['nullable','string','max:50',Rule::unique('suppliers','supplier_code')->where(fn($q)=>$q->where('company_id',$cid))->ignore($id)],
            'legal_name'=>'nullable|string|max:255','phone'=>'nullable|string|max:50','email'=>'nullable|email|max:150','registration_number'=>'nullable|string|max:120','tax_number'=>'nullable|string|max:120','country_code'=>'nullable|string|size:2',
            'scope_all_branches'=>'nullable|boolean','branch_ids'=>'nullable|array','branch_ids.*'=>'integer','default_branch_id'=>'nullable|integer','notes'=>'nullable|string|max:5000','is_active'=>'nullable|boolean',
            'short_address'=>'nullable|string|max:100','building_no'=>'nullable|string|max:50','street_name'=>'nullable|string|max:200','district'=>'nullable|string|max:150','city'=>'nullable|string|max:150','state_region'=>'nullable|string|max:150','postal_code'=>'nullable|string|max:50','additional_no'=>'nullable|string|max:50','unit_no'=>'nullable|string|max:50','address'=>'nullable|string|max:500','address_line1'=>'nullable|string|max:500','address_line2'=>'nullable|string|max:500']);
    }
    private function clean($v): ?string{$v=trim((string)$v);return$v===''?null:$v;}private function upper($v): ?string{$v=$this->clean($v);return$v?strtoupper($v):null;}
}
