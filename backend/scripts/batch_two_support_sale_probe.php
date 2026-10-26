<?php
// Test-only boundary probe. No production route/controller/service change.
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
config(['app.debug'=>false]);DB::statement('SET SESSION innodb_lock_wait_timeout=2');
$phase=$argv[3]??'';
DB::connection()->beforeExecuting(function($sql)use($phase){$s=strtolower($sql);
 if($phase==='real1213'&&str_contains($s,'from `items`')&&str_contains($s,'for update')){fwrite(STDOUT,"SALE_REQUEST_ITEM_LOCK\n");fflush(STDOUT);}
 if(str_contains($s,'insert into `audit_logs`')){
  fwrite(STDOUT,'SALE_AUDIT_STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction n')->n])."\n");
  if($phase==='audit')throw new RuntimeException('FAULT_SALE_AUDIT');
 }
 if(str_contains($s,'update `shipments`')){
  fwrite(STDOUT,"SALE_REQUEST_SHIPMENT_UPDATE\n");fflush(STDOUT);
  if(in_array($phase,['1205','1213','swallow'],true))DB::unprepared($phase==='1213'?"SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213,MESSAGE_TEXT='Deadlock found when trying to get lock'":"SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO=1205,MESSAGE_TEXT='Lock wait timeout exceeded; try restarting transaction'");
  if($phase==='reject')throw new RuntimeException('FAULT_AFTER_SALE_FINANCE');
 }
});
if($phase==='swallow')app()->bind(App\Http\Controllers\Api\SalesInvoiceController::class,fn()=>new class extends App\Http\Controllers\Api\SalesInvoiceController{
 public function post(\Illuminate\Http\Request $r,int $id,\App\Services\Accounting\AccountingContext $ctx,\App\Services\EnterpriseInvoiceService $service){try{return parent::post($r,$id,$ctx,$service);}catch(\App\Services\ShipmentPostingFailure){return response()->json(['message'=>'Swallowed test failure'],422);}}
});
$r=Illuminate\Http\Request::create('/api/sales-invoices/'.(int)$argv[1].'/post','POST');$r->headers->set('Accept','application/json');$r->headers->set('Authorization','Bearer '.($argv[2]??''));
$response=app(Illuminate\Contracts\Http\Kernel::class)->handle($r);fwrite(STDOUT,'SALE_HTTP_RESULT '.json_encode(['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true),'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction n')->n])."\n");exit($response->getStatusCode()===200?0:1);
