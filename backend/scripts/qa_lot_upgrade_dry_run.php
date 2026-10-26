<?php
/** Read-only QA executable: schema company year. No apply flag exists. */
require __DIR__.'/../vendor/autoload.php';
$schema=$argv[1]??'';$company=$argv[2]??'';$year=$argv[3]??'';
if(count($argv)!==4||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$schema)||!ctype_digit($company)||!ctype_digit($year))throw new RuntimeException('Explicit owned QA schema, company and year required');
$directory=getenv('SULB_SHIPMENT_TEST_DIR');if(!$directory||!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$directory))throw new RuntimeException('Owned QA directory required');
foreach(['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_DATABASE'=>$schema,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','APP_CONFIG_CACHE'=>sys_get_temp_dir().'/qa-dry-no-cache-'.bin2hex(random_bytes(8)).'.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(['UomStageAProof','HistoricalLotBackfill','LotUpgradeDryRun'] as $file)require_once __DIR__.'/../tests/Support/'.$file.'.php';
$result=Tests\Support\LotUpgradeDryRun::run((int)$company,(int)$year);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['ready']?0:2);
