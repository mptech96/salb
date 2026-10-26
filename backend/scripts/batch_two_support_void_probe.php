<?php
// Actual HTTP/middleware boundary counterexample, isolated environment only.
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
config(['app.debug'=>false]);
use Illuminate\Support\Facades\DB;
DB::statement('SET SESSION innodb_lock_wait_timeout=2');
$phase=$argv[3]??'';$reversals=0;
DB::connection()->beforeExecuting(function($sql)use($phase,&$reversals){$s=strtolower($sql);
 if($phase==='real1213'&&str_contains($s,'from `items`')&&str_contains($s,'for update')){fwrite(STDOUT,"VOID_REQUEST_ITEM_LOCK\n");fflush(STDOUT);}
 if(str_contains($s,'insert into `audit_logs`')){fwrite(STDOUT,'VOID_AUDIT_STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction n')->n])."\n");if($phase==='audit')throw new RuntimeException('FAULT_VOID_AUDIT');}
 if(str_contains($s,'update `journal_entries`')&&str_contains($s,'reversed_by_id'))$reversals++;
 if($phase==='cost'&&str_contains($s,'insert into `shipment_costs`'))throw new RuntimeException('FAULT_AFTER_COST_VOID');
 if($phase==='stock'&&str_contains($s,'update `inventory_lots`'))throw new RuntimeException('FAULT_AFTER_STOCK_MOVEMENT');
 if($phase==='lot'&&str_contains($s,'update `stock_movements`')&&str_contains($s,'notes'))throw new RuntimeException('FAULT_AFTER_LOT_BALANCE');
 if($phase==='journal'&&$reversals===2&&str_contains($s,'select * from `inventory_lots`')&&str_contains($s,'for update'))throw new RuntimeException('FAULT_AFTER_ORIGINAL_JOURNAL_REVERSED');
 if(in_array($phase,['1205','1213','swallow'],true)&&str_contains($s,'update `inventory_lots`'))DB::unprepared($phase==='1213'?"SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213,MESSAGE_TEXT='Deadlock found when trying to get lock'":"SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO=1205,MESSAGE_TEXT='Lock wait timeout exceeded; try restarting transaction'");
 if(str_contains($s,'update `shipments`')){fwrite(STDOUT,"VOID_REQUEST_SHIPMENT_UPDATE\n");fflush(STDOUT);}
});
if($phase==='swallow')app()->bind(App\Http\Controllers\Api\PurchaseInvoiceController::class,fn()=>new class extends App\Http\Controllers\Api\PurchaseInvoiceController{
 public function void(\Illuminate\Http\Request $r,int $id,\App\Services\Accounting\AccountingContext $ctx,\App\Services\EnterpriseInvoiceService $service){try{return parent::void($r,$id,$ctx,$service);}catch(\App\Services\ShipmentPostingFailure){return response()->json(['message'=>'Swallowed test failure'],422);}}
});
$r=Illuminate\Http\Request::create('/api/purchase-invoices/'.(int)$argv[1].'/void','POST',['reason'=>'Isolated Support VOID timeout proof']);
$r->headers->set('Authorization','Bearer '.($argv[2]??''));$r->headers->set('Accept','application/json');
$response=app(Illuminate\Contracts\Http\Kernel::class)->handle($r);
fwrite(STDOUT,'HTTP_VOID_RESULT '.json_encode(['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true),'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction n')->n])."\n");
exit($response->getStatusCode()===200?0:1);
