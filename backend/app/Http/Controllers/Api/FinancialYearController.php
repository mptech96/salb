<?php
namespace App\Http\Controllers\Api;
use App\Domain\Accounting\Services\FinancialYearService;use App\Http\Controllers\Controller;use App\Services\Accounting\AccountingContext;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class FinancialYearController extends Controller
{
 public function index(Request $r,AccountingContext $c){return response()->json(['status'=>true,'data'=>DB::table('financial_years')->where('company_id',$c->companyId($r))->orderByDesc('start_date')->get()]);}
 public function store(Request $r,FinancialYearService $s,AccountingContext $c){$v=$r->validate(['year_name'=>'nullable|string|max:100','start_date'=>'required|date','end_date'=>'required|date']);try{$id=$s->create($c->companyId($r),$v);return response()->json(['status'=>true,'message'=>'تم إنشاء السنة المالية.','id'=>$id],201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
 public function preview(Request $r,int $id,FinancialYearService $s,AccountingContext $c){try{return response()->json(['status'=>true,'data'=>$s->preview($c->companyId($r),$id)]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
 public function close(Request $r,int $id,FinancialYearService $s,AccountingContext $c){try{return response()->json(['status'=>true,'message'=>'تم إقفال السنة وفتح السنة التالية تلقائيًا.','data'=>$s->close($c->companyId($r),$id,$c->userId($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
 public function reopen(Request $r,int $id,FinancialYearService $s,AccountingContext $c){try{return response()->json(['status'=>true,'message'=>'تمت إعادة فتح السنة وعكس قيود الإقفال.','data'=>$s->reopen($c->companyId($r),$id,$c->userId($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
}
