<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\Uom\ProcessingRecipeService;
use App\Support\ApiExceptionMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class ProcessingRecipeController extends Controller
{
    public function index(Request $r,AccountingContext $ctx)
    {
        $company=$ctx->companyId($r);$branch=$ctx->branchForOperation($r);
        if(!DB::getSchemaBuilder()->hasTable('uom_processing_recipes'))return response()->json(['status'=>false,'message'=>'بنية وصفات المعالجة غير متاحة.'],422);
        $rows=DB::table('uom_processing_recipes')->where('company_id',$company)->where('branch_id',$branch)->orderByDesc('id')->limit(200)->get();
        foreach($rows as $row)$row->definition=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);
        return response()->json(['status'=>true,'data'=>$rows,'branch_id'=>$branch]);
    }
    public function store(Request $r,AccountingContext $ctx,ProcessingRecipeService $service)
    {
        $v=$r->validate(['branch_id'=>'nullable|integer','recipe_code'=>'required|string|max:80','operation_type'=>'required|string','yield_notes'=>'required|string|min:5|max:2000','from'=>'required|array|min:1|max:100','to'=>'present|array|max:100','from.*.item_id'=>'required|integer','to.*.item_id'=>'required|integer','from.*.quantity'=>'required|string','to.*.quantity'=>'required|string','from.*.unit_code'=>'required|string|max:20','to.*.unit_code'=>'required|string|max:20']);
        try{$id=$service->create($ctx->companyId($r),$ctx->branchForOperation($r),$v);return response()->json(['status'=>true,'id'=>$id],201);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>ApiExceptionMessage::for($e)],422);}
    }
    public function approve(Request $r,int $id,AccountingContext $ctx,ProcessingRecipeService $service)
    {
        $v=$r->validate(['branch_id'=>'nullable|integer']);
        try{$service->approve($ctx->companyId($r),$ctx->branchForOperation($r),$id);return response()->json(['status'=>true]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>ApiExceptionMessage::for($e)],422);}
    }
}
