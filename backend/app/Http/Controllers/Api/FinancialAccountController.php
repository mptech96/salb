<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\FinancialAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialAccountController extends Controller
{
    public function index(Request $r,AccountingContext $c,FinancialAccountService $s)
    {
        $v=$r->validate(['page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|in:25,50,100','search'=>'nullable|string|max:200','branch_id'=>'nullable|integer','scope'=>'nullable|in:CENTRAL','account_type'=>'nullable|in:CASH,BANK,WALLET,PETTY_CASH,OTHER','is_active'=>'nullable|boolean']);
        $cid=$c->companyId($r);$scopedBranch=$c->branchFilter($r);if($scopedBranch!==null)unset($v['branch_id']);elseif(!empty($v['branch_id']))$c->branchForOperation($r,(int)$v['branch_id']);$rows=$s->list($cid,$scopedBranch,$v);
        $ids=$rows->getCollection()->pluck('id')->all();
        $aggregate=function(string $column)use($ids,$cid,$scopedBranch){return DB::table('financial_accounts as f')->join('journal_entry_lines as l','l.'.$column,'=',$column==='financial_account_id'?'f.id':'f.gl_account_id')->join('journal_entries as e','e.id','=','l.journal_entry_id')->whereIn('f.id',$ids)->where('f.company_id',$cid)->where('l.company_id',$cid)->where('e.company_id',$cid)->where('e.status','POSTED')->whereRaw('(f.branch_id IS NULL OR l.branch_id = f.branch_id)')->when($scopedBranch!==null,fn($q)=>$q->where('l.branch_id',$scopedBranch))->groupBy('f.id');};
        $taggedTotals=$aggregate('financial_account_id')->selectRaw('f.id, SUM(l.debit-l.credit) AS balance')->get()->keyBy('id');
        $glTotals=$aggregate('account_id')->selectRaw('f.id, SUM(l.debit-l.credit) AS balance, SUM(CASE WHEN l.financial_account_id IS NULL THEN 1 ELSE 0 END) AS unmatched')->get()->keyBy('id');
        foreach($rows as$row){
            $tagged=(float)($taggedTotals->get($row->id)?->balance??0);
            $gl=(float)($glTotals->get($row->id)?->balance??0);
            $unmatched=(int)($glTotals->get($row->id)?->unmatched??0);
            $row->tagged_balance=$row->current_balance=round((float)$tagged,3);$row->gl_balance=round((float)$gl,3);$row->reconciliation_difference=round((float)$gl-(float)$tagged,3);$row->is_reconciled=abs($row->reconciliation_difference)<0.001;$row->unmatched_lines_count=$unmatched;
        }
        return response()->json(['status'=>true,'data'=>$rows]);
    }

    public function transactions(Request $r,int $id,AccountingContext $c)
    {
        $v=$r->validate(['page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|in:25,50,100','search'=>'nullable|string|max:200','from_date'=>'nullable|date','to_date'=>'nullable|date|after_or_equal:from_date','scope'=>'nullable|in:tagged,unmatched']);$cid=$c->companyId($r);$bid=$c->branchFilter($r);
        $account=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$id)->when($bid!==null,fn($q)=>$q->where(function($x)use($bid){$x->whereNull('branch_id')->orWhere('branch_id',$bid);}))->first();if(!$account)return response()->json(['status'=>false,'message'=>'الحساب المالي غير موجود ضمن نطاقك.'],404);
        $effectiveBranch=$account->branch_id!==null?(int)$account->branch_id:$bid;$scope=$v['scope']??'tagged';
        $base=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->leftJoin('branches as b','b.id','=','e.branch_id')->where('l.company_id',$cid)->where('e.status','POSTED');
        if($scope==='unmatched')$base->where('l.account_id',$account->gl_account_id)->whereNull('l.financial_account_id');else$base->where('l.financial_account_id',$id);
        if($effectiveBranch!==null)$base->where('l.branch_id',$effectiveBranch);
        $filtered=(clone$base)->when(!empty($v['from_date']),fn($q)=>$q->whereDate('e.entry_date','>=',$v['from_date']))->when(!empty($v['to_date']),fn($q)=>$q->whereDate('e.entry_date','<=',$v['to_date']))->when(!empty($v['search']),function($q)use($v){$s='%'.trim($v['search']).'%';$q->where(function($x)use($s){$x->where('e.entry_number','like',$s)->orWhere('e.reference_no','like',$s)->orWhere('e.description','like',$s)->orWhere('l.description','like',$s);});});
        $per=(int)($v['per_page']??25);$data=$filtered->select('l.id','l.journal_entry_id','l.debit','l.credit','l.description','l.currency_code','l.foreign_debit','l.foreign_credit','e.entry_number','e.reference_no','e.entry_date','e.source_type','e.source_id','e.status','b.branch_name')->orderBy('e.entry_date')->orderBy('l.id')->paginate($per);$first=$data->items()[0]??null;$pageOpening=0.0;if($first)$pageOpening=(float)(clone$base)->where(function($q)use($first){$q->whereDate('e.entry_date','<',$first->entry_date)->orWhere(function($x)use($first){$x->whereDate('e.entry_date',$first->entry_date)->where('l.id','<',$first->id);});})->sum(DB::raw('l.debit-l.credit'));$running=$pageOpening;foreach($data->items()as$row){$running+=(float)$row->debit-(float)$row->credit;$row->running_balance=round($running,3);}
        $tagged=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('l.company_id',$cid)->where('l.financial_account_id',$id)->where('e.status','POSTED')->when($effectiveBranch!==null,fn($q)=>$q->where('l.branch_id',$effectiveBranch))->sum(DB::raw('l.debit-l.credit'));
        $gl=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('l.company_id',$cid)->where('l.account_id',$account->gl_account_id)->where('e.status','POSTED')->when($effectiveBranch!==null,fn($q)=>$q->where('l.branch_id',$effectiveBranch))->sum(DB::raw('l.debit-l.credit'));
        return response()->json(['status'=>true,'data'=>$data,'summary'=>['opening_balance'=>round($pageOpening,3),'closing_balance'=>round($running,3),'tagged_balance'=>round((float)$tagged,3),'gl_balance'=>round((float)$gl,3),'difference'=>round((float)$gl-(float)$tagged,3),'is_reconciled'=>abs((float)$gl-(float)$tagged)<0.001,'scope'=>$scope]]);
    }
    public function meta(Request $r,AccountingContext $c){$cid=$c->companyId($r);$bid=$c->branchFilter($r);return response()->json(['status'=>true,'data'=>[
        'branches'=>DB::table('branches')->where('company_id',$cid)->where('is_active',1)->when($bid!==null,fn($q)=>$q->where('id',$bid))->orderBy('branch_name')->get(),
        // لا نحصر النظام في أكواد ثابتة؛ أي حساب أصل نشط وقابل للترحيل يمكن ربطه بخزينة/بنك/محفظة.
        'accounts'=>DB::table('accounts')->where('company_id',$cid)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->where('account_type','ASSET')->orderBy('account_code')->get(),
        'currencies'=>DB::table('company_currencies as cc')->join('currencies as c','c.currency_code','=','cc.currency_code')->where('cc.company_id',$cid)->where('cc.is_active',1)->select('c.*','cc.is_base')->orderByDesc('cc.is_base')->orderBy('c.currency_code')->get()
    ]]);}
    public function store(Request $r,AccountingContext $c,FinancialAccountService $s){$cid=$c->companyId($r);$v=$this->valid($r);$scoped=$c->branchFilter($r);if($scoped!==null)$v['branch_id']=$scoped;elseif(!empty($v['branch_id']))$c->branchForOperation($r,(int)$v['branch_id']);try{$id=$s->save($cid,$v);return response()->json(['status'=>true,'message'=>'تم إنشاء الخزينة/الحساب المالي وربطه بدفتر الأستاذ.','id'=>$id],201);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function update(Request $r,int $id,AccountingContext $c,FinancialAccountService $s){$cid=$c->companyId($r);$v=$this->valid($r);$existing=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$id)->first();if(!$existing)return response()->json(['status'=>false,'message'=>'الحساب المالي غير موجود.'],404);$scoped=$c->branchFilter($r);if($scoped!==null&&((int)$existing->branch_id!==$scoped))return response()->json(['status'=>false,'message'=>'الحساب المالي خارج نطاق الفرع.'],403);if($scoped!==null)$v['branch_id']=$scoped;try{$s->save($cid,$v,$id);return response()->json(['status'=>true,'message'=>'تم تحديث الحساب المالي.']);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function destroy(Request $r,int $id,AccountingContext $c,FinancialAccountService $s){$cid=$c->companyId($r);$fa=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$id)->first();if(!$fa)return response()->json(['status'=>false,'message'=>'الحساب المالي غير موجود.'],404);$bid=$c->branchFilter($r);if($bid!==null&&(int)$fa->branch_id!==$bid)return response()->json(['status'=>false,'message'=>'الحساب المالي خارج نطاق الفرع.'],403);try{return response()->json(['status'=>true,'message'=>$s->deactivateOrDelete($cid,$id)]);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    private function valid(Request$r):array{return$r->validate(['branch_id'=>'nullable|integer','account_code'=>'nullable|string|max:80','account_name'=>'required|string|max:200','account_type'=>'required|in:CASH,BANK,WALLET,PETTY_CASH,OTHER','gl_account_id'=>'required|integer','currency_code'=>'required|string|max:10','bank_name'=>'nullable|string|max:150','account_number'=>'nullable|string|max:120','iban'=>'nullable|string|max:120','wallet_provider'=>'nullable|string|max:120','is_default_receipt'=>'nullable|boolean','is_default_payment'=>'nullable|boolean','is_active'=>'nullable|boolean','notes'=>'nullable|string|max:2000']);}
}
