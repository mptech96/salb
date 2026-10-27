<?php
/** Only a new offline Percona container socket is accepted. No TCP fallback. */
try {
    [$script,$socket,$datadir,$capture,$sha,$proof]=$argv;
    if(PHP_OS_FAMILY==='Windows'||filetype($socket)!=='socket'||is_link($socket)
       ||!preg_match('#^/var/lib/mysql/sulb-shipment-[a-f0-9]{32}$#D',$datadir)
       ||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!hash_equals($sha,hash_file('sha256',$capture)))throw new RuntimeException('OWNED_SOCKET_REQUIRED');
    $selfNs=@readlink('/proc/self/ns/net');$hostNs=@readlink('/proc/1/ns/net');
    if(!$selfNs||!$hostNs||$selfNs===$hostNs)throw new RuntimeException('PRIVATE_NETWORK_NAMESPACE_REQUIRED');
    $routes=@file_get_contents('/proc/net/route');
    if($routes===false)throw new RuntimeException('PRIVATE_NETWORK_NAMESPACE_REQUIRED');
    foreach(array_slice(preg_split('/\r?\n/',trim($routes)),1)as$route){if(trim($route)!==''&&preg_split('/\s+/',trim($route))[0]!=='lo')throw new RuntimeException('PRIVATE_NETWORK_NAMESPACE_REQUIRED');}
    $admin=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>10]);
    $server=$admin->query('SELECT VERSION() version,@@version_comment distribution,@@datadir datadir,@@skip_networking offline')->fetch(PDO::FETCH_ASSOC);
    if(!preg_match('/^8\.4\./',$server['version'])||stripos($server['distribution'],'Percona')===false
       ||rtrim($server['datadir'],'/')!==$datadir||(int)$server['offline']!==1)throw new RuntimeException('OFFLINE_PERCONA_REQUIRED');
    if((int)$admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql','sys','information_schema','performance_schema')")->fetchColumn()!==0)throw new RuntimeException('EMPTY_INSTANCE_REQUIRED');
    $owned='sulb_ship_test_'.bin2hex(random_bytes(8));
    if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$owned)||$owned==='sulbprod')throw new RuntimeException('OWNED_NAME_REQUIRED');
    $admin->exec('CREATE DATABASE `'.$owned.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(function()use($admin,$owned){try{$admin->exec('DROP DATABASE IF EXISTS `'.$owned.'`');}catch(Throwable $e){}});
    $root=dirname(__DIR__,2);
    if(glob($root.'/backend/.env')||glob($root.'/backend/.env.testing')||is_file($root.'/backend/bootstrap/cache/config.php'))throw new RuntimeException('NO_ENV_OR_CONFIG_CACHE_ALLOWED');
    $env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
      'DB_CONNECTION'=>'mysql','DB_URL'=>'','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339',
      'DB_SOCKET'=>$socket,'DB_DATABASE'=>$owned,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
      'SULB_SHIPMENT_TEST_DIR'=>$datadir,'SULB_PRIVATE_SCHEMA_FILE'=>$capture,'SULB_PRIVATE_SCHEMA_SHA256'=>$sha,
      'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'null',
      'APP_CONFIG_CACHE'=>dirname($proof).'/no-existing-config-'.bin2hex(random_bytes(8)).'.php'];
    foreach($env as$k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
    require $root.'/backend/vendor/autoload.php';chdir($root.'/backend');
    // Laravel config retains the owned host/port guard; PDO uses ONLY DB_SOCKET.
    $exit=(new PHPUnit\TextUI\Application)->run(['phpunit','--no-configuration','tests/Integration/Uom/PrivateSchemaMigrationRehearsalTest.php']);
    if($exit!==0)throw new RuntimeException('SCHEMA_REHEARSAL_FAILED');
    $admin->exec('DROP DATABASE `'.$owned.'`');
    $result=['schema_only_rehearsal'=>'PASS','server_version'=>$server['version'],'server_family'=>'Percona',
      'networking_disabled'=>true,'php_network_namespace_isolated'=>true,'connection_transport'=>'owned Unix socket only','schema_file_sha256'=>$sha,
      'uom_migrations_applied'=>14,'lot_l3_dependencies_applied'=>4,'business_rows_imported'=>false,
      'owned_database_removed'=>true,'production_connected'=>false,'historical_validation_passed'=>false,
      'release_decision'=>'NO-GO'];
    if(file_put_contents($proof,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('PROOF_WRITE_FAILED');
}catch(Throwable $e){
    $allowed=['OWNED_SOCKET_REQUIRED','PRIVATE_NETWORK_NAMESPACE_REQUIRED','OFFLINE_PERCONA_REQUIRED','EMPTY_INSTANCE_REQUIRED','OWNED_NAME_REQUIRED','NO_ENV_OR_CONFIG_CACHE_ALLOWED','SCHEMA_REHEARSAL_FAILED','PROOF_WRITE_FAILED'];
    $reason=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'RUNTIME_FAILURE';
    if($e instanceof PDOException)$reason='PDO_FAILURE_'.preg_replace('/[^0-9A-Z]/','',(string)($e->errorInfo[0]??'')).'_'.(int)($e->errorInfo[1]??0);
    fwrite(STDERR,"STOP_CODE=$reason; isolated rehearsal failed; no DDL or credential diagnostics emitted.\n");exit(1);
}
