<?php
// Real posting service over the test packet proxy; no application binding/route change.
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
$port=(int)($argv[2]??0);if($port<1024||$port>65535)exit(2);
config(['database.connections.mysql.port'=>$port]);Illuminate\Support\Facades\DB::purge();
try{
 $result=app(App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,(int)$argv[1],1,1);
 fwrite(STDOUT,'COMPLETED '.json_encode($result)."\n");
}catch(Throwable $e){
 // A communication failure is deliberately NOT labelled financial FAILED.
 fwrite(STDOUT,'UNKNOWN_TO_CALLER '.get_class($e).' '.$e->getMessage()."\n");exit(1);
}
