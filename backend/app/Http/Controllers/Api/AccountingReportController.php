<?php
namespace App\Http\Controllers\Api;
use App\Domain\Accounting\Services\AccountingReportService;use App\Http\Controllers\Controller;use App\Services\Accounting\AccountingContext;use Illuminate\Http\Request;
class AccountingReportController extends Controller
{
 private function f(Request $r):array{return $r->only(['financial_year_id','from_date','to_date','as_of','cost_center_id','party_type','party_id']);}
 public function overview(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->overview($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function trialBalance(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->trialBalance($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function incomeStatement(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->incomeStatement($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function balanceSheet(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->balanceSheet($c->companyId($r),$c->branchFilter($r),$this->f($r))]);}
 public function ledger(Request $r,AccountingReportService $s,AccountingContext $c){$v=$r->validate(['account_id'=>'required|integer']);try{return response()->json(['status'=>true,'data'=>$s->ledger($c->companyId($r),$c->branchFilter($r),(int)$v['account_id'],$this->f($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
}
