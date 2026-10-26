<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actual=Illuminate\Support\Facades\DB::selectOne('SELECT @@datadir AS dir')->dir;
if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',getenv('SULB_SHIPMENT_TEST_DIR')?:''),'/'))exit(2);
if(Illuminate\Support\Facades\DB::table('companies')->where('id',1)->value('company_name')!=='Shipment test only')exit(2);
$distribution=Mockery::mock(App\Services\ShipmentCostDistributionService::class);
$distribution->shouldReceive('distributeByShipmentId')->andReturn([]);
$app->instance(App\Services\ShipmentCostDistributionService::class,$distribution);
fwrite(STDOUT,'READY '.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");fflush(STDOUT);
try{
    $service=app(App\Services\ShipmentCostService::class);
    fwrite(STDOUT,"SERVICE_READY\n");fflush(STDOUT);
    Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function($sql){if(str_contains(strtolower($sql),'for update')){fwrite(STDOUT,'LOCK_SQL_ATTEMPT '.substr($sql,0,180)."\n");fflush(STDOUT);}});
    if(($argv[1]??'')==='post')$service->postPendingForShipment(1,1,1);
    elseif(($argv[1]??'')==='delete')$service->deleteDraft(1,(int)$argv[2],1);
    else exit(2);
    fwrite(STDOUT,"SUCCESS\n");
}catch(Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage()."\n");exit(1);}
