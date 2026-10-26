<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
if(($argv[1]??'')==='cost2') {
 app(App\Services\ShipmentCostService::class)->postPendingForShipment(2,(int)$argv[2],2);
 echo "COST_COMMIT\n";exit;
}
DB::statement('SET SESSION innodb_lock_wait_timeout=2');
DB::beginTransaction();echo 'QUERY_STARTED '.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";flush();$start=microtime(true);
try{
 $count=DB::update('UPDATE stock_movements SET journal_entry_id=?, updated_at=? WHERE company_id=? AND source_type=? AND source_id=?',[123,'2026-10-14 00:00:00',2,'PURCHASE',202]);
 DB::commit();echo 'PROBE_COMMIT '.json_encode(['rows'=>$count,'elapsed_ms'=>(microtime(true)-$start)*1000])."\n";
}catch(Throwable $e){if(DB::transactionLevel())DB::rollBack();echo 'PROBE_FAILURE '.json_encode(['code'=>$e instanceof Illuminate\Database\QueryException?($e->errorInfo[1]??null):null,'elapsed_ms'=>(microtime(true)-$start)*1000])."\n";exit(1);}
