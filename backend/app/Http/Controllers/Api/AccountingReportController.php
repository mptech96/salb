<?php
namespace App\Http\Controllers\Api;
use App\Domain\Accounting\Services\AccountingReportService;use App\Http\Controllers\Controller;use App\Services\Accounting\AccountingContext;use Illuminate\Http\Request;
class AccountingReportController extends Controller
{
 private function f(Request $r,bool $ledger=false):array{return $r->validate(array_filter([
  'financial_year_id'=>'nullable|integer','from_date'=>'nullable|date','to_date'=>'nullable|date|after_or_equal:from_date','as_of'=>'nullable|date',
  'cost_center_id'=>'nullable|integer','party_type'=>'nullable|string|max:30','party_id'=>'nullable|integer',
  'page'=>$ledger?'nullable|integer|min:1':null,'per_page'=>$ledger?'nullable|integer|in:25,50,100':null,'search'=>$ledger?'nullable|string|max:200':null,
 ]));}
 public function overview(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->overview($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function trialBalance(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->trialBalance($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function incomeStatement(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->incomeStatement($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function balanceSheet(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->balanceSheet($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function ledger(Request $r,AccountingReportService $s,AccountingContext $c){$v=$r->validate(['account_id'=>'required|integer']);try{return response()->json(['status'=>true,'data'=>$s->ledger($c->companyId($r),$c->branchFilter($r),(int)$v['account_id'],$this->f($r,true))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
}
