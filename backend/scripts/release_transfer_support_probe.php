<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
config(['inventory_transfer.root_protocol_enabled'=>true]);
use Illuminate\Support\Facades\DB;
config(['app.debug'=>false]);DB::statement('SET SESSION innodb_lock_wait_timeout=1');$phase=$argv[3]??'';
DB::connection()->beforeExecuting(function($sql)use($phase){
    if($phase==='real1213'&&str_contains($sql,'from `items`')&&str_contains($sql,'for update')){echo "TRANSFER_REQUEST_ITEM\n";fflush(STDOUT);}
    if($phase==='late_failure'&&str_starts_with($sql,'insert into `journal_entries`'))throw new RuntimeException('TRANSFER_LATE_FAILURE');
    if($phase==='audit'&&str_starts_with($sql,'insert into `audit_logs`'))throw new RuntimeException('TRANSFER_AUDIT_FAILURE');
});
if($phase==='swallow')app()->bind(App\Http\Controllers\Api\InventoryOperationController::class,fn()=>new class extends App\Http\Controllers\Api\InventoryOperationController {
    public function approve(Illuminate\Http\Request $request,int $id,App\Services\InventoryOperationService $service,App\Services\Accounting\AccountingContext $context){
        try{return parent::approve($request,$id,$service,$context);}catch(App\Services\ShipmentPostingFailure){return response()->json(['message'=>'swallowed diagnostic'],422);}
    }
});
$request=Illuminate\Http\Request::create('/api/inventory-operations/'.(int)$argv[1].'/approve','POST');
$request->headers->set('Accept','application/json');$request->headers->set('Authorization','Bearer '.($argv[2]??''));
$response=app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo 'TRANSFER_HTTP '.json_encode(['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true),'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()]).PHP_EOL;
exit($response->getStatusCode()===200?0:1);
