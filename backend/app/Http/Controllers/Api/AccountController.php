<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;use App\Domain\Accounting\Services\AccountService;use App\Support\TenantScope;use Illuminate\Http\Request;
class AccountController extends Controller
{
 public function tree(Request $r,AccountService $s){return response()->json(['status'=>true,'data'=>$s->tree(TenantScope::companyId($r))]);}
 public function posting(Request $r,AccountService $s){return response()->json(['status'=>true,'data'=>$s->postingAccounts(TenantScope::companyId($r))]);}
 public function store(Request $r,AccountService $s){$v=$r->validate(['account_code'=>'required|string|max:50','account_name'=>'required|string|max:255','account_type'=>'required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE','normal_side'=>'required|in:DEBIT,CREDIT','parent_id'=>'nullable|integer','is_group'=>'nullable|boolean','allow_cost_center'=>'nullable|boolean','notes'=>'nullable|string']);try{$id=$s->create([...$v,'company_id'=>TenantScope::companyId($r)]);return response()->json(['status'=>true,'message'=>'تم إنشاء الحساب بنجاح.','id'=>$id],201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
}
