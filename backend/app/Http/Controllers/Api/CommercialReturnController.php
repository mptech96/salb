<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\CommercialReturnService;
use Illuminate\Http\Request;
class CommercialReturnController extends Controller
{
    public function index(Request $r,AccountingContext $c,CommercialReturnService $s){return response()->json(['status'=>true,'data'=>$s->list($c->companyId($r),$c->branchFilter($r),$r->query('type'))]);}
    public function meta(Request $r,AccountingContext $c,CommercialReturnService $s){$type=strtoupper((string)$r->query('type','SALES_RETURN'));return response()->json(['status'=>true,'data'=>['source_invoices'=>$s->sourceInvoices($c->companyId($r),$c->branchFilter($r),$type)]]);}
    public function source(Request $r,int $invoiceId,AccountingContext $c,CommercialReturnService $s){$type=strtoupper((string)$r->query('type','SALES_RETURN'));try{return response()->json(['status'=>true,'data'=>$s->sourceLines($c->companyId($r),$type,$invoiceId)]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function show(Request $r,int $id,AccountingContext $c,CommercialReturnService $s){try{return response()->json(['status'=>true,'data'=>$s->details($c->companyId($r),$id,$c->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],404);}}
    public function store(Request $r,AccountingContext $c,CommercialReturnService $s){return $this->save($r,null,$c,$s);}
    public function update(Request $r,int $id,AccountingContext $c,CommercialReturnService $s){return $this->save($r,$id,$c,$s);}
    private function save(Request $r,?int $id,AccountingContext $c,CommercialReturnService $s){$v=$r->validate(['return_type'=>'required|in:SALES_RETURN,PURCHASE_RETURN','return_date'=>'required|date','source_invoice_id'=>'required|integer','notes'=>'nullable|string|max:4000','lines'=>'required|array|min:1|max:500','lines.*.source_invoice_line_id'=>'required|integer','lines.*.quantity'=>'required|numeric|gt:0','lines.*.notes'=>'nullable|string|max:1000']);try{$bid=$c->branchForOperation($r);$rid=$s->saveDraft($c->companyId($r),$bid,$c->userId($r),$v,$id);return response()->json(['status'=>true,'id'=>$rid,'message'=>$id?'تم تحديث مسودة المردود.':'تم حفظ المردود كمسودة دون أثر مخزني أو محاسبي.','data'=>$s->details($c->companyId($r),$rid,$c->branchFilter($r))],$id?200:201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function post(Request $r,int $id,AccountingContext $c,CommercialReturnService $s){try{return response()->json(['status'=>true,'message'=>'تم ترحيل المردود وعكس أثره على المخزون والذمة والضريبة والحسابات.','data'=>$s->post($c->companyId($r),$id,$c->userId($r),$c->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
    public function void(Request $r,int $id,AccountingContext $c,CommercialReturnService $s){$v=$r->validate(['reason'=>'required|string|min:5|max:2000']);try{return response()->json(['status'=>true,'message'=>'تم عكس المردود مع حفظ كامل الأثر.','data'=>$s->void($c->companyId($r),$id,$c->userId($r),$v['reason'],$c->branchFilter($r))]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}}
}
