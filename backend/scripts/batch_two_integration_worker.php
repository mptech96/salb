<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
[$kind,$company,$id,$phase]=[$argv[1],(int)$argv[2],(int)$argv[3],$argv[4]??''];
DB::statement('SET SESSION innodb_lock_wait_timeout=8');$once=false;
fwrite(STDOUT,'CONNECTION '.DB::selectOne('SELECT CONNECTION_ID() n')->n."\n");fflush(STDOUT);
DB::connection()->beforeExecuting(function($sql,$bindings)use($phase,&$once){$s=strtolower($sql);
 if(str_contains($s,'for update')){fwrite(STDOUT,'REQUEST_LOCK '.substr($s,0,110).' '.json_encode($bindings)."\n");fflush(STDOUT);}
 if(!$once&&str_contains($s,'from `financial_years`')&&str_contains($s,'for update')){
  $once=true;fwrite(STDOUT,"BEFORE_FINANCIAL_YEAR\n");fflush(STDOUT);
  if($phase==='hold'){$key=DB::connection()->getDatabaseName().'-batch2-barrier';if(!DB::selectOne('SELECT GET_LOCK(?,15) ok',[$key])->ok)throw new RuntimeException('Barrier timeout');DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);}
 }
});
try{
 match($kind){
 'sale'=>app(App\Services\EnterpriseInvoiceService::class)->post('SALE',$company,$id,$company,$company),
 'purchase'=>app(App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',$company,$id,$company,$company),
 'cost'=>app(App\Services\ShipmentCostService::class)->postPendingForShipment($company,$id,$company),
 'distribution'=>app(App\Services\ShipmentCostDistributionService::class)->distributeByShipmentId($company,$id),
 default=>throw new RuntimeException('Unknown test operation')};
 fwrite(STDOUT,"COMMITTED\n");
}catch(Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage().' STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()])."\n");exit(1);}
