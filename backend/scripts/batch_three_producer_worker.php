<?php
// Isolated audit worker only. It does not register a route or change posting services.
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
config(['inventory_transfer.root_protocol_enabled'=>true]);
use Illuminate\Support\Facades\DB;
[$kind,$company,$id,$phase]=[$argv[1],(int)$argv[2],(int)$argv[3],$argv[4]??''];
DB::statement('SET SESSION innodb_lock_wait_timeout='.($phase==='timeout'?2:8));$once=false;
fwrite(STDOUT,'CONNECTION '.DB::selectOne('SELECT CONNECTION_ID() n')->n."\n");fflush(STDOUT);
DB::connection()->beforeExecuting(function($sql,$bindings)use($kind,$phase,&$once){
    $s=strtolower($sql);if(str_contains($s,'for update')){fwrite(STDOUT,'REQUEST_LOCK '.$s.' '.json_encode($bindings)."\n");fflush(STDOUT);}
    if($kind==='transfer'&&$phase==='hold_fifo'&&str_contains($s,'insert into `stock_movements`')&&in_array('OUT',$bindings,true)&&!$once){
        $once=true;fwrite(STDOUT,"TRANSFER_OWNS_FIFO\n");fflush(STDOUT);
        $key=DB::connection()->getDatabaseName().'-release-transfer';
        if(!DB::selectOne('SELECT GET_LOCK(?,15) ok',[$key])->ok)throw new RuntimeException('Test FIFO barrier timeout');
        DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);
    }
    if($kind==='purchase'&&str_contains($s,'insert into `inventory_lots`')){fwrite(STDOUT,"REQUEST_LOT_INSERT\n");fflush(STDOUT);}
    if(!$once&&$kind==='transfer'&&str_contains($s,'from `sulb_document_sequences`')&&in_array('INVENTORY_LOT',$bindings,true)&&str_contains($s,'for update')){
        if($phase==='hold_fifo')return;
        $once=true;fwrite(STDOUT,"TRANSFER_HOLDS_LOTS_BEFORE_Q\n");fflush(STDOUT);
        if($phase==='hold'){$key=DB::connection()->getDatabaseName().'-batch3-transfer';if(!DB::selectOne('SELECT GET_LOCK(?,15) ok',[$key])->ok)throw new RuntimeException('Test barrier timeout');DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);}
    }
});
try {
    match($kind){
        'transfer'=>app(App\Services\InventoryOperationService::class)->approve($company,$id,$company,$company),
        'purchase'=>app(App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',$company,$id,$company,$company),
        default=>throw new RuntimeException('Unknown isolated test operation'),
    };
    fwrite(STDOUT,"COMMITTED\n");
}catch(Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage().' STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()])."\n");exit(1);}
