<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/DriverGatewayPrototype.php';
try{
    $gateway=new \Tests\Support\DriverGatewayPrototype(json_decode((string)getenv('SULB_GW_WRITER'),true,512,JSON_THROW_ON_ERROR));
    fwrite(STDOUT,"READY\n");fflush(STDOUT);$start=microtime(true);
    if($argv[1]==='purchase-hold')$result=$gateway->purchaseOrigin($argv[2],false,static function(){fwrite(STDOUT,"BEFORE_COMMIT_BARRIER\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='COMMIT')throw new \RuntimeException('Controlled writer aborted');});
    elseif($argv[1]==='purchase')$result=$gateway->purchaseOrigin($argv[2],false,static function(){fwrite(STDOUT,"PURCHASE_CAPTURED\n");fflush(STDOUT);usleep(800000);});
    elseif(in_array($argv[1],['batch','baseline-batch'],true)){
        $samples=[];for($i=0;$i<(int)$argv[2];$i++){$t=microtime(true);if($argv[1]==='batch')$gateway->manual(1,(int)($argv[3]??1),8);else app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','description'=>'Concurrent baseline','lines'=>[['account_id'=>8,'debit'=>10,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>10]]]);$samples[]=['ms'=>(microtime(true)-$t)*1000,'scope_ms'=>$gateway->lockTimings['scope_ms']??null];}$result=$samples;
    }elseif($argv[1]==='origin'){
        // Same isolated distribution stub as the parent fixture; no inventory E2E claim.
        $distribution=\Mockery::mock(\App\Services\ShipmentCostDistributionService::class);$distribution->shouldReceive('distributeByShipmentId')->andReturn([]);$app->instance(\App\Services\ShipmentCostDistributionService::class,$distribution);
        $result=$gateway->createOrigin(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>'1000.000','currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'UNPAID','capitalizable'=>false,'payee_type'=>'DRIVER','payee_id'=>3],$argv[2]);
    }elseif($argv[1]==='supplier')$result=$gateway->supplierVoucher(1,1);
    elseif(in_array($argv[1],['pay','pay-barrier'],true)){
        if($argv[1]==='pay-barrier')$gateway->beforeCompanyLock=static function(){fwrite(STDOUT,"COMPANY_LOCK_REQUEST\n");fflush(STDOUT);};
        $result=$gateway->pay(1,1,(int)$argv[2],$argv[3],$argv[4]);
    }
    else $result=$gateway->manual((int)($argv[4]??1),(int)$argv[2],(int)($argv[3]??8));
    fwrite(STDOUT,'RESULT '.json_encode(['value'=>$result,'elapsed_ms'=>(microtime(true)-$start)*1000,'lock_acquire_ms'=>$gateway->lockTimings]).PHP_EOL);
}catch(\Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage().PHP_EOL);exit(2);}
