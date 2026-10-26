<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
if(Illuminate\Support\Facades\DB::table('companies')->where('id',1)->value('company_name')!=='Isolated')exit(2);
config(['uom.contract_testing_enabled'=>true]);
Illuminate\Support\Facades\Auth::login(App\Models\User::where('username','uom-test')->firstOrFail());
fwrite(STDOUT,'READY '.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");fflush(STDOUT);
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function($sql){if(str_contains(strtolower($sql),'for update')||str_contains(strtolower($sql),'insert into `stock_movements`')){fwrite(STDOUT,"LOCK_ATTEMPT\n");fflush(STDOUT);}});
try{
 if(($argv[1]??'')==='issue'){$r=(new App\Services\Uom\BaseCostContract)->issue(1,(int)$argv[2],$argv[3],$argv[4]);$id=$r->id;}
 elseif(($argv[1]??'')==='price')$id=(new App\Services\Uom\ItemUnitService)->revisePrice(1,1,(int)$argv[2],'SAR',$argv[3],$argv[4]);
 elseif(($argv[1]??'')==='legacy')$id=Illuminate\Support\Facades\DB::table('stock_movements')->insertGetId(['company_id'=>1,'branch_id'=>1,'item_id'=>(int)$argv[2],'movement_type'=>'IN','source_type'=>'UOM_CLOSURE_RACE','source_id'=>1,'movement_date'=>'2026-10-14','qty'=>'1','total_cost'=>'1']);
 elseif(($argv[1]??'')==='fy-then-item'){
  Illuminate\Support\Facades\DB::beginTransaction();Illuminate\Support\Facades\DB::table('financial_years')->where('id',1)->lockForUpdate()->first();fwrite(STDOUT,"FY_LOCKED\n");fflush(STDOUT);Illuminate\Support\Facades\DB::table('items')->where('id',1)->lockForUpdate()->first();Illuminate\Support\Facades\DB::rollBack();$id=0;
 }
 else exit(2);
 fwrite(STDOUT,'SUCCESS '.$id."\n");
}catch(Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage()."\n");exit(1);}
