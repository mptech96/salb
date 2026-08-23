<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\FinancialAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialAccountController extends Controller
{
    public function index(Request $r,AccountingContext $c,FinancialAccountService $s){return response()->json(['status'=>true,'data'=>$s->list($c->companyId($r),$c->branchFilter($r))]);}
    public function meta(Request $r,AccountingContext $c){$cid=$c->companyId($r);$bid=$c->branchFilter($r);return response()->json(['status'=>true,'data'=>[
        'branches'=>DB::table('branches')->where('company_id',$cid)->where('is_active',1)->when($bid!==null,fn($q)=>$q->where('id',$bid))->orderBy('branch_name')->get(),
        // لا نحصر النظام في أكواد ثابتة؛ أي حساب أصل نشط وقابل للترحيل يمكن ربطه بخزينة/بنك/محفظة.
        'accounts'=>DB::table('accounts')->where('company_id',$cid)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->where('account_type','ASSET')->orderBy('account_code')->get(),
        'currencies'=>DB::table('company_currencies as cc')->join('currencies as c','c.currency_code','=','cc.currency_code')->where('cc.company_id',$cid)->where('cc.is_active',1)->select('c.*','cc.is_base')->orderByDesc('cc.is_base')->orderBy('c.currency_code')->get()
    ]]);}
    public function store(Request $r,AccountingContext $c,FinancialAccountService $s){$cid=$c->companyId($r);$v=$this->valid($r);$scoped=$c->branchFilter($r);if($scoped!==null)$v['branch_id']=$scoped;elseif(!empty($v['branch_id']))$c->branchForOperation($r,(int)$v['branch_id']);try{$id=$s->save($cid,$v);return response()->json(['status'=>true,'message'=>'تم إنشاء الخزينة/الحساب المالي وربطه بدفتر الأستاذ.','id'=>$id],201);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function update(Request $r,int $id,AccountingContext $c,FinancialAccountService $s){$cid=$c->companyId($r);$v=$this->valid($r);$existing=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$id)->first();if(!$existing)return response()->json(['status'=>false,'message'=>'الحساب المالي غير موجود.'],404);$scoped=$c->branchFilter($r);if($scoped!==null&&((int)$existing->branch_id!==$scoped))return response()->json(['status'=>false,'message'=>'الحساب المالي خارج نطاق الفرع.'],403);if($scoped!==null)$v['branch_id']=$scoped;try{$s->save($cid,$v,$id);return response()->json(['status'=>true,'message'=>'تم تحديث الحساب المالي.']);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function destroy(Request $r,int $id,AccountingContext $c){$cid=$c->companyId($r);$fa=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$id)->first();if(!$fa)return response()->json(['status'=>false,'message'=>'الحساب المالي غير موجود.'],404);$bid=$c->branchFilter($r);if($bid!==null&&(int)$fa->branch_id!==$bid)return response()->json(['status'=>false,'message'=>'الحساب المالي خارج نطاق الفرع.'],403);$used=DB::table('journal_entry_lines')->where('company_id',$cid)->where('financial_account_id',$id)->exists();if($used){DB::table('financial_accounts')->where('id',$id)->update(['is_active'=>0,'updated_at'=>now()]);return response()->json(['status'=>true,'message'=>'الحساب مستخدم تاريخيًا لذلك تم تعطيله بدل حذفه.']);}DB::table('financial_accounts')->where('id',$id)->delete();return response()->json(['status'=>true,'message'=>'تم حذف الحساب المالي.']);}
    private function valid(Request$r):array{return$r->validate(['branch_id'=>'nullable|integer','account_code'=>'nullable|string|max:80','account_name'=>'required|string|max:200','account_type'=>'required|in:CASH,BANK,WALLET,PETTY_CASH,OTHER','gl_account_id'=>'required|integer','currency_code'=>'required|string|max:10','bank_name'=>'nullable|string|max:150','account_number'=>'nullable|string|max:120','iban'=>'nullable|string|max:120','wallet_provider'=>'nullable|string|max:120','is_default_receipt'=>'nullable|boolean','is_default_payment'=>'nullable|boolean','is_active'=>'nullable|boolean','notes'=>'nullable|string|max:2000']);}
}
