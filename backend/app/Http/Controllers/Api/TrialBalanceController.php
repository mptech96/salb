<?php
namespace App\Http\Controllers\Api;use App\Domain\Accounting\Services\AccountingReportService;use App\Http\Controllers\Controller;use App\Services\Accounting\AccountingContext;use Illuminate\Http\Request;
class TrialBalanceController extends Controller{public function index(Request $r,AccountingReportService $s,AccountingContext $c){return response()->json(['status'=>true,'data'=>$s->trialBalance($c->companyId($r),$c->branchFilter($r),$r->only(['financial_year_id','from_date','to_date']))]);}}
