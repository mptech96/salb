<?php
namespace App\Support {function is_dir($p){return $p===ReleaseWriteFence::MARKER&&($GLOBALS['syntheticFence']??false)?true:\is_dir($p);}}
namespace {
require dirname(__DIR__,2).'/backend/vendor/autoload.php';$n=0;$ok=function($v)use(&$n){if(!$v)throw new \RuntimeException('FENCE_INTEGRATION_'.($n+1));$n++;};
foreach(['APP_ENV'=>'testing','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','LOG_CHANNEL'=>'null','DB_CONNECTION'=>'mysql','DB_DATABASE'=>'sulb_forbidden_fixture','DB_HOST'=>'127.0.0.1','DB_PORT'=>'1']as$k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
$app=require dirname(__DIR__,2).'/backend/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$GLOBALS['syntheticFence']=true;
$c=\Illuminate\Support\Facades\DB::connection();$bad=false;try{$c->select('SELECT 1');}catch(\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException $e){$bad=$e->getStatusCode()===503;}$ok($bad);$ok(!($c->getRawPdo() instanceof \PDO));
// New named connections use the same provider listener; no PDO is opened.
config(['database.connections.fence_named'=>config('database.connections.mysql')]);$c=\Illuminate\Support\Facades\DB::connection('fence_named');$bad=false;try{$c->statement('UPDATE items SET company_id=4');}catch(\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException){$bad=true;}$ok($bad&&!($c->getRawPdo() instanceof \PDO));
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$property=new \ReflectionProperty($kernel,'middleware');$property->setAccessible(true);$middleware=$property->getValue($kernel);$ok(($middleware[0]??null)===\App\Http\Middleware\EnforceReleaseWriteFence::class);
foreach(['GET','POST','PUT','PATCH','DELETE']as$method){$entered=false;$request=\Illuminate\Http\Request::create('/api/anything',$method);$request->headers->set('X-Company-Id','3');$request->headers->set('X-Release-Bypass','1');$bad=false;try{(new \App\Http\Middleware\EnforceReleaseWriteFence)->handle($request,function()use(&$entered){$entered=true;});}catch(\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException){$bad=true;}$ok($bad&&!$entered);}
$bad=false;try{(new \App\Providers\AppServiceProvider($app))->boot();}catch(\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException){$bad=true;}$ok($bad);
$GLOBALS['syntheticFence']=false;$entered=false;(new \App\Http\Middleware\EnforceReleaseWriteFence)->handle(\Illuminate\Http\Request::create('/'),function()use(&$entered){$entered=true;});$ok($entered);
echo 'PASS '.$n.' application fence integration checks; database_connections=0; actual_marker_created=false'.PHP_EOL;
}
