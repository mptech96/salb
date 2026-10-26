<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
DB::statement('SET SESSION lock_wait_timeout=2');
fwrite(STDOUT,"INDEX_DDL_STARTED\n");fflush(STDOUT);
try{DB::statement('CREATE INDEX idx_stock_company_source ON stock_movements(company_id,source_type,source_id)');fwrite(STDOUT,"INDEX_CREATED\n");}
catch(Throwable $e){fwrite(STDOUT,'INDEX_DDL_FAILED '.$e->getMessage()."\n");exit(1);}
