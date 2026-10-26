<?php
// Pre-implementation safety proof only. No runtime registration or service patch.
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
$kind=$argv[1]??'';$id=(int)($argv[2]??0);if(!in_array($kind,['void','cost'],true))exit(2);
DB::statement('SET SESSION innodb_lock_wait_timeout=8');fwrite(STDOUT,'CONNECTION '.DB::selectOne('SELECT CONNECTION_ID() AS n',[],false)->n."\n");fflush(STDOUT);
fwrite(STDOUT,'L2_CONNECTIONS '.json_encode(['write'=>DB::selectOne('SELECT CONNECTION_ID() n',[],false)->n,'read'=>DB::selectOne('SELECT CONNECTION_ID() n',[],true)->n])."\n");fflush(STDOUT);
$armed=true;
DB::connection()->beforeExecuting(function($sql)use($kind,&$armed){
 if($kind==='cost'&&str_contains(strtolower($sql),'from `shipments`')&&str_contains(strtolower($sql),'for update')){fwrite(STDOUT,"COST_REQUEST_SHIPMENT\n");fflush(STDOUT);}
 if($kind==='void'&&$armed&&str_contains(strtolower($sql),'from `financial_years`')&&str_contains(strtolower($sql),'for update')){
  $armed=false;fwrite(STDOUT,'L2_OWNER '.json_encode(['connection'=>DB::selectOne('SELECT CONNECTION_ID() n')->n,'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()])."\nVOID_OWNS_COST_BEFORE_YEAR\n");fflush(STDOUT);$key=DB::connection()->getDatabaseName().'-void-gate';if(!DB::selectOne('SELECT GET_LOCK(?,15) AS ok',[$key])->ok)throw new RuntimeException('PROOF_BARRIER_TIMEOUT');DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);
 }
});
DB::listen(function($q)use($kind){if($kind==='cost'&&str_contains(strtolower($q->sql),'from `shipments`')&&str_contains(strtolower($q->sql),'for update')){fwrite(STDOUT,"COST_OWNS_SHIPMENT\n");fflush(STDOUT);}});
try{
 if($kind==='void')app(App\Services\EnterpriseInvoiceService::class)->void('PURCHASE',1,$id,1,'Isolated lock boundary proof',1);
 else app(App\Services\ShipmentCostService::class)->postPendingForShipment(1,$id,1);
 fwrite(STDOUT,'L2_FINAL '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()])."\nCOMMITTED\n");
}catch(Throwable $e){fwrite(STDOUT,'L2_FINAL '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()])."\nREJECTED ".$e->getMessage()."\n");exit(1);}
