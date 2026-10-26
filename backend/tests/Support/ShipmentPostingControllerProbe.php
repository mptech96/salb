<?php
namespace Tests\Support;
use App\Services\Accounting\AccountingContext;
use App\Services\EnterpriseInvoiceService;
use App\Services\ShipmentPostingFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Test-only observer; calls the real Controller, services and middleware. */
class ShipmentPostingControllerProbe extends \App\Http\Controllers\Api\PurchaseInvoiceController
{
 public function __construct(private string $phase){}
 public function post(Request $r,int $id,AccountingContext $ctx,EnterpriseInvoiceService $service)
 {
  try{return parent::post($r,$id,$ctx,$service);}
  catch(ShipmentPostingFailure $e){
   fwrite(STDOUT,'POSTING_FAILURE_STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction v')->v])."\n");
   if($this->phase==='swallow-1205')return response()->json(['status'=>false,'message'=>'Deliberately swallowed posting failure'],422);
   throw $e;
  }
 }
}
