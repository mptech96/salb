<?php
// Explicit memory-only acceptance entry point; works around Windows is_readable bootstrap detection.
require __DIR__.'/../vendor/autoload.php';
$maria=($argv[1]??'')==='--mariadb';
$admin=null;$owned=null;
if($maria){
    $directory=getenv('SULB_SHIPMENT_TEST_DIR');
    if(!$directory||!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$directory))throw new RuntimeException('Owned temp server required');
    $admin=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $actual=$admin->query('SELECT @@datadir')->fetchColumn();
    if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',$directory),'/'))throw new RuntimeException('Not the owned disposable server');
    $owned='sulb_ship_test_'.bin2hex(random_bytes(8));
    $admin->exec('CREATE DATABASE `'.$owned.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    // PHPUnit fatal errors may bypass finally. Drop only this invocation's owned DB.
    register_shutdown_function(function()use($admin,&$owned){if($owned){try{$admin->exec('DROP DATABASE IF EXISTS `'.$owned.'`');fwrite(STDOUT,'OWNED_TEST_DATABASE_REMOVED '.$owned.PHP_EOL);}catch(\Throwable $e){fwrite(STDERR,'OWNED_TEST_CLEANUP_FAILED '.$e->getMessage().PHP_EOL);}}});
}
foreach (['APP_ENV'=>'testing','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','DB_URL'=>'',
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
if($maria)foreach(['DB_CONNECTION'=>'mysql','DB_DATABASE'=>$owned,'DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_USERNAME'=>'root','DB_PASSWORD'=>'']as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/shipment-no-cache-'.bin2hex(random_bytes(8)).'.php');
chdir(dirname(__DIR__));
$targets=$maria?(array_slice($argv,2)?:['tests/Integration/Shipments/ShipmentDeletionRaceTest.php']):array_slice($argv,1);
if (!$targets) $targets=['tests/Feature/Shipments/ShipmentStageOneSafetyTest.php'];
foreach ($targets as $target) {
    if (!preg_match('#^tests/(Feature|Unit|Integration)(/[A-Za-z0-9_.-]+)*$#D',$target)) throw new RuntimeException('Test path required');
}
$filter=getenv('SULB_TEST_FILTER')?:'';
if($filter!==''&&!preg_match('/^[A-Za-z0-9_|]+$/D',$filter))throw new RuntimeException('Named test alternatives required');
try{$result=(new PHPUnit\TextUI\Application)->run(['phpunit','--no-configuration',...($filter!==''?['--filter',$filter]:[]),...$targets]);}
finally{if($admin&&$owned){$admin->exec('DROP DATABASE `'.$owned.'`');fwrite(STDOUT,'OWNED_TEST_DATABASE_REMOVED '.$owned.PHP_EOL);$owned=null;}}
exit($result);
