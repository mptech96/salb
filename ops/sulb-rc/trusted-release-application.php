<?php
/** Privileged tools bootstrap immutable candidate code, never the writable live application. */
function trustedReleaseApplication(PDO $pdo,string $mode='production') {
    $root=dirname(__DIR__,2);$actual=$pdo->query('SELECT DATABASE() db,@@datadir d')->fetch(PDO::FETCH_ASSOC);
    if($mode==='isolated-rehearsal'){if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$actual['db']??'')||!preg_match('#/sulb-shipment-[a-f0-9]{32}$#D',rtrim(str_replace('\\','/',$actual['d']),'/')))throw new RuntimeException('TRUSTED_APPLICATION_OWNED_QA_REQUIRED');}
    elseif($mode==='production'){if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||$root!=='/home/sulb-release'||getcwd()!==$root||$actual['db']!=='sulbprod')throw new RuntimeException('TRUSTED_APPLICATION_PRODUCTION_CONTEXT');}
    else throw new RuntimeException('TRUSTED_APPLICATION_EXPLICIT_MODE');
    if($mode==='production'){foreach(['/home/sulb-release/backend','/home/sulb-release/ops/sulb-rc']as$dir)if(fileowner($dir)!==0||(fileperms($dir)&0022)!==0)throw new RuntimeException('TRUSTED_CANDIDATE_OWNERSHIP_REQUIRED');}
    if(is_file($root.'/backend/.env')||is_file($root.'/backend/.env.testing')||is_file($root.'/backend/bootstrap/cache/config.php'))throw new RuntimeException('TRUSTED_CANDIDATE_ENV_OR_CACHE_FORBIDDEN');
    foreach(['APP_CONFIG_CACHE'=>'config.php','APP_SERVICES_CACHE'=>'services.php','APP_PACKAGES_CACHE'=>'packages.php','APP_ROUTES_CACHE'=>'routes-v7.php','APP_EVENTS_CACHE'=>'events.php']as$key=>$file){$value='bootstrap/cache/'.$file;putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
    $value=$root.'/backend/vendor';putenv('COMPOSER_VENDOR_DIR='.$value);$_ENV['COMPOSER_VENDOR_DIR']=$value;$_SERVER['COMPOSER_VENDOR_DIR']=$value;
    require_once $root.'/backend/vendor/autoload.php';$app=require $root.'/backend/bootstrap/app.php';
    if($mode==='production'){$app->useEnvironmentPath('/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech');$app->useStoragePath('/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/storage');}
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class,function($app)use($pdo,$actual){$app['config']->set('database.default','mysql');$app['config']->set('database.connections.mysql.database',$actual['db']);$app['config']->set('logging.default','null');$c=$app['db']->connection('mysql');$c->setPdo($pdo);$c->setReadPdo($pdo);$c->setReconnector(static function(){throw new RuntimeException('RELEASE_RECONNECT_FORBIDDEN');});});
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if($mode==='isolated-rehearsal'){require_once $root.'/backend/tests/Support/UomStageAProof.php';\Tests\Support\UomStageAProof::guard();}
    elseif(!$app->environment('production'))throw new RuntimeException('LIVE_PRODUCTION_ENV_REQUIRED');return$app;
}
