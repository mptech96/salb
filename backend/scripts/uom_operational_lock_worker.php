<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
if(Illuminate\Support\Facades\DB::table('companies')->where('id',1)->value('company_name')!=='UOM lock only')exit(2);
$kind=$argv[1];$company=(int)$argv[2];$id=(int)$argv[3];$coordinated=($argv[4]??'')==='coordinated';$phase=$argv[5]??'';
$connection=Illuminate\Support\Facades\DB::connection();$connection->statement('SET SESSION innodb_lock_wait_timeout=8');fwrite(STDOUT,'READY '.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");fflush(STDOUT);
$fired=false;$connection->beforeExecuting(function($sql)use($phase,&$fired){
 $s=strtolower($sql);if(str_contains($s,'for update')||str_contains($s,'insert into `sulb_document_sequences`')){fwrite(STDOUT,'LOCK_SQL '.substr($s,0,110)."\n");fflush(STDOUT);}
 if(str_contains($s,'insert into `uom_item_modes`')){fwrite(STDOUT,"MODE_INSERT\n");fflush(STDOUT);}
 if($phase==='fault-before-lot'&&str_contains($s,'insert into `inventory_lots`'))throw new RuntimeException('INJECTED_AFTER_JOURNAL');
 if($phase==='fault-after-lot'&&str_contains($s,'insert into `inventory_lot_movements`'))throw new RuntimeException('INJECTED_AFTER_LOT');
 if($phase==='fault-after-journal'&&str_contains($s,'update `purchase_invoices`')&&str_contains($s,'`document_status`'))throw new RuntimeException('INJECTED_AFTER_JOURNAL');
 if(!$fired&&$phase==='before-lot'&&str_contains($s,'insert into `inventory_lots`')){$fired=true;fwrite(STDOUT,"BEFORE_LOT\n");fflush(STDOUT);$db=Illuminate\Support\Facades\DB::connection()->getDatabaseName();$v=Illuminate\Support\Facades\DB::selectOne('SELECT GET_LOCK(?,15) AS acquired',[$db.'-barrier'])->acquired;if(!$v)throw new RuntimeException('TEST_BARRIER_TIMEOUT');Illuminate\Support\Facades\DB::selectOne('SELECT RELEASE_LOCK(?)',[$db.'-barrier']);}
});
Illuminate\Support\Facades\DB::listen(function($query){
 if(str_contains(strtolower($query->sql),'sulb_document_sequences')&&str_contains(strtolower($query->sql),'for update'))fwrite(STDOUT,'LOT_WAIT_MS '.$query->time."\n");
});
try{
 if($kind==='mode'){
  Illuminate\Support\Facades\DB::transaction(fn()=>Illuminate\Support\Facades\DB::table('uom_item_modes')->insert(['company_id'=>$company,'item_id'=>$id,'mode'=>'BASE_ONLY']));
  fwrite(STDOUT,"COMMITTED\n");exit(0);
 }
 if($coordinated){require __DIR__.'/../tests/Support/UomPostingCoordinatorProof.php';$r=Tests\Support\UomPostingCoordinatorProof::post($kind,$company,$id);}
 else $r=$kind==='purchase'?app(App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',$company,$id,$company,$company):app(App\Services\OpeningBalanceService::class)->post($company,$id,$company,true,$company);
 fwrite(STDOUT,"COMMITTED\n");
}catch(Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage()."\n");exit(1);}
