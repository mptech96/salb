<?php
/** Standalone owned QA only. Never runs a Laravel migration. */
$dir=getenv('SULB_COMPAT_QA_DIR');
if(!$dir||!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$dir))throw new RuntimeException('OWNED_QA_DIR_REQUIRED');
$admin=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(rtrim(str_replace('\\','/',$admin->query('SELECT @@datadir')->fetchColumn()),'/')!==rtrim(str_replace('\\','/',$dir),'/'))throw new RuntimeException('WRONG_QA_INSTANCE');
$db='sulb_compat_test_'.bin2hex(random_bytes(8));$admin->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($admin,$db){$admin->exec('DROP DATABASE IF EXISTS `'.$db.'`');});
foreach(['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'APP_CONFIG_CACHE'=>sys_get_temp_dir().'/sulb-compat-no-cache-'.bin2hex(random_bytes(8)).'.php','DB_CONNECTION'=>'mysql','DB_URL'=>'','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_SOCKET'=>'','DB_DATABASE'=>$db,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array']as$k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
require __DIR__.'/../vendor/autoload.php';chdir(dirname(__DIR__));
try{$result=(new PHPUnit\TextUI\Application)->run(['phpunit','--no-configuration','tests/Integration/Compatibility/NoMigrationAccountParentTest.php']);}finally{$admin->exec('DROP DATABASE `'.$db.'`');}
exit($result);
