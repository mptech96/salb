<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Disposable database only; this probe deliberately works with and without proposed guards.
$db=\Illuminate\Support\Facades\DB::connection();
if($db->getConfig('host')!=='127.0.0.1' || (string)$db->getConfig('port')!=='3339' || !preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$db->getDatabaseName()))throw new \RuntimeException('Owned database required');
$actual=$db->selectOne('SELECT @@datadir d')->d;
if(str_replace('\\','/',rtrim($actual,'/\\'))!==str_replace('\\','/',rtrim((string)getenv('SULB_SHIPMENT_TEST_DIR'),'/\\')))throw new \RuntimeException('Owned server required');
$db->statement('SET SESSION innodb_lock_wait_timeout='.(($argv[1]??'')==='deadlock'?20:1));
fwrite(STDERR,'CONNECTION '.$db->selectOne('SELECT CONNECTION_ID() id')->id.PHP_EOL);
$start=microtime(true);
try {
    app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','source_type'=>'MANUAL','description'=>'Unrelated GL probe','created_by'=>1,'lines'=>[['account_id'=>8,'debit'=>1,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>1]]]);
    echo json_encode(['result'=>'POSTED','ms'=>(microtime(true)-$start)*1000]);
}catch(\Throwable $e){echo json_encode(['result'=>'BLOCKED','ms'=>(microtime(true)-$start)*1000,'error'=>$e->getMessage()]);exit(2);}
