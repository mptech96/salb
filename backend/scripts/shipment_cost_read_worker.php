<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
[$op,$company,$shipment,$cost]=[$argv[1],(int)$argv[2],(int)$argv[3],(int)($argv[4]??0)];
DB::statement('SET SESSION innodb_lock_wait_timeout=6');
echo 'CONNECTION '.DB::selectOne('SELECT CONNECTION_ID() n')->n.PHP_EOL;flush();
DB::connection()->beforeExecuting(function($sql){
 if(str_contains($sql,'from `shipments`')&&str_contains($sql,'for update')){echo "REQUEST_H\n";flush();}
 if(str_starts_with($sql,'insert into `shipment_costs`')){echo "REQUEST_COST_INSERT\n";flush();}
});
DB::listen(function($q){if(str_contains($q->sql,'from `shipments`')&&str_contains($q->sql,'for update')){echo "OWNS_H\n";flush();}});
$start=microtime(true);
try{
 $svc=app(App\Services\ShipmentCostService::class);
 match($op){
  'create'=>$svc->storeDraft(['company_id'=>$company,'branch_id'=>$company,'shipment_id'=>$shipment,'expense_type_id'=>$company,'expense_date'=>'2026-10-14','amount'=>7,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'UNPAID','capitalizable'=>true,'created_by'=>$company]),
  'update'=>$svc->updateDraft($company,$cost,['amount'=>21],$company),
  'delete'=>$svc->deleteDraft($company,$cost,$company),
  default=>throw new RuntimeException('Unknown test operation')};
 echo 'COMMITTED '.json_encode(['elapsed_ms'=>(microtime(true)-$start)*1000]).PHP_EOL;
}catch(Throwable $e){echo 'REJECTED '.$e->getMessage().' STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()]).PHP_EOL;exit(1);}
