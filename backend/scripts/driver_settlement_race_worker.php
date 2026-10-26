<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    \App\Services\DriverSettlementIsolation::assert();
    \Illuminate\Support\Facades\Auth::loginUsingId(1);
    fwrite(STDOUT,'READY '.\Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() id')->id.PHP_EOL);fflush(STDOUT);
    \Illuminate\Support\Facades\DB::beginTransaction();
    app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany(1);
    $r=app(\App\Services\DriverCostSettlement::class)->settle(1,1,1,(int)$argv[1],1,'2026-03-03',$argv[2],$argv[3]);
    \Illuminate\Support\Facades\DB::commit();
    fwrite(STDOUT,'RESULT '.json_encode($r).PHP_EOL);
}catch(\Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage().PHP_EOL);exit(2);}
