<?php
require __DIR__.'/../vendor/autoload.php';
$mode=$argv[1]??'';$id=$argv[2]??'';$schema=getenv('DB_DATABASE');
if(!in_array($mode,['ORDER_CONVERT','OPENING_POST','ADJUSTMENT_OUT','ADJUSTMENT_VOID','PROCESS_POST','PROCESS_VOID','SHIPMENT_PURCHASE_POST','SHIPMENT_PURCHASE_VOID','MANUAL_REVERSAL_PROBE','PROCESS_COST_POST'],true)||!ctype_digit($id)||(int)$id<1||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',(string)$schema))throw new RuntimeException('Owned QA adapter command required');
foreach(['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_DATABASE'=>$schema,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','SULB_UOM_OPERATIONAL_ENABLED'=>'true','APP_CONFIG_CACHE'=>sys_get_temp_dir().'/qa-adapter-worker-'.bin2hex(random_bytes(8)).'.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Mirror only the parent's explicit QA value-ledger setting, not project .env defaults.
if(getenv('SULB_QA_ADAPTER_VALUE_LEDGER')==='1')config(['inventory_value.enabled'=>true]);
if(getenv('SULB_QA_ADAPTER_SHIPMENT_L3')==='1')config(['shipment_allocation.enabled'=>true,'shipment_cost_read.enabled'=>true]);
require_once __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout=15');
echo 'QA_THREAD '.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() id')->id.PHP_EOL;fflush(STDOUT);
$result=match($mode){
    'PROCESS_COST_POST'=>(function()use($id){try{return app(App\Services\Uom\BaseProcessingService::class)->post(1,1,(int)$id,1)['operation_id'];}catch(\RuntimeException $e){if(str_contains($e->getMessage(),'UOM_PROCESS_COST_SOURCE_ALREADY_USED'))return 0;throw $e;}})(),
    'MANUAL_REVERSAL_PROBE'=>app(App\Domain\Accounting\Services\JournalService::class)->reverseManual(1,(int)$id,['entry_date'=>'2026-10-26','reason'=>'QA manual reversal race','created_by'=>1],1),
    'SHIPMENT_PURCHASE_POST'=>(function()use($id){app(App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,(int)$id,1,1);return (int)$id;})(),
    'SHIPMENT_PURCHASE_VOID'=>(function()use($id){try{return app(App\Services\EnterpriseInvoiceService::class)->void('PURCHASE',1,(int)$id,1,'QA mixed paid shipment concurrent void',1)['reversal_journal_id'];}catch(\RuntimeException $e){if($e->getMessage()==='يمكن عكس الفاتورة المرحلة فقط.')return 0;throw $e;}})(),
    'PROCESS_POST'=>app(App\Services\Uom\BaseProcessingService::class)->post(1,1,(int)$id,1)['operation_id'],
    'PROCESS_VOID'=>app(App\Services\Uom\BaseProcessingService::class)->void(1,1,(int)$id,1,'QA concurrent process reversal')['operation_id'],
    'ORDER_CONVERT'=>app(App\Services\CommercialDocumentService::class)->convert('PURCHASE_ORDER',1,(int)$id,1,1)['invoice_id'],
    'OPENING_POST'=>app(App\Services\OpeningBalanceService::class)->post(1,(int)$id,1,true,1)['journal_entry_id'],
    'ADJUSTMENT_OUT'=>app(App\Services\Uom\BaseAdjustmentService::class)->post(1,1,1,['item_id'=>(int)$id,'request_key'=>'QA-RACE-OUT-0001','movement_type'=>'OUT','quantity'=>'3','unit_code'=>'PCS','movement_date'=>'2026-10-25','notes'=>'QA concurrent adjustment issue'])['adjustment_id'],
    'ADJUSTMENT_VOID'=>app(App\Services\Uom\BaseAdjustmentService::class)->void(1,1,(int)$id,1,'QA concurrent adjustment reversal')['adjustment_id'],
};
echo 'QA_RESULT '.$result.PHP_EOL;
