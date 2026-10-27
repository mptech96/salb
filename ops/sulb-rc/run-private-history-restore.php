<?php
/** Restore only inside the approved fresh, offline native Percona instance. */
require_once __DIR__.'/history-archive.php';
require_once __DIR__.'/history-native-context.php';
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
$admin=null;$owned=[];$proof=null;
try{
    if(count($argv)!==8)throw new RuntimeException('HISTORY_RESTORE_ARGUMENTS_REQUIRED');
    [$script,$socket,$datadir,$file,$keyfile,$manifestfile,$context,$proof]=$argv;
    verifyHistoryNativeContext($context,$socket,$datadir);
    $work=dirname($proof);
    if(!preg_match('#^/home/mgye-api-sulb/sulb-history-restore-[a-f0-9]{16}$#D',$work))throw new RuntimeException('HISTORY_PRIVATE_RESTORE_WORK_REQUIRED');
    foreach([$file,$keyfile,$manifestfile,$context]as$p)if(dirname($p)!==$work||is_link($p)||!is_file($p)||fileowner($p)!==0||(fileperms($p)&0077)!==0)throw new RuntimeException('HISTORY_PRIVATE_RESTORE_FILES_REQUIRED');
    $key=file_get_contents($keyfile);$manifest=json_decode(file_get_contents($manifestfile),true,512,JSON_THROW_ON_ERROR);historyVerify($file,$key,$manifest);
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,PDO::ATTR_TIMEOUT=>10];
    $admin=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',$options);
    $server=$admin->query('SELECT @@datadir d,@@skip_networking offline,VERSION() version,@@version_comment distribution')->fetch(PDO::FETCH_ASSOC);
    if(rtrim($server['d'],'/')!==$datadir||(int)$server['offline']!==1||!preg_match('/^8\.4\./',$server['version'])||stripos($server['distribution'],'Percona')===false)throw new RuntimeException('HISTORY_OFFLINE_PERCONA_REQUIRED');
    if((int)$admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql','sys','information_schema','performance_schema')")->fetchColumn()!==0)throw new RuntimeException('HISTORY_EMPTY_INSTANCE_REQUIRED');
    $result=['snapshot_restore_rehearsal'=>'PASS','two_restores_verified'=>true,'encrypted_archive_verified'=>true,'production_connected'=>false,'historical_validation_passed'=>false,'migrations_applied'=>0,'release_decision'=>'NO-GO'];
    for($i=0;$i<2;$i++){
        $db='sulb_hist_restore_'.bin2hex(random_bytes(8));$admin->exec('CREATE DATABASE '.historyId($db).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$owned[]=$db;
        $target=new PDO('mysql:unix_socket='.$socket.';dbname='.$db.';charset=utf8mb4','root','',$options);
        $p=historyRestore($target,$db,$datadir,$file,$key,$manifest);unset($target);
        if($p['foreign_key_orphans']>0){$result['snapshot_restore_rehearsal']='BLOCKED';$result['reason']='HISTORY_FK_ORPHANS_PRESENT';$result['foreign_key_orphans']=$p['foreign_key_orphans'];$result['two_restores_verified']=false;break;}
    }
    sodium_memzero($key);
}catch(Throwable $e){$result=['snapshot_restore_rehearsal'=>'BLOCKED','reason'=>preg_match('/^(HISTORY_|NATIVE_)[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'HISTORY_RESTORE_RUNTIME_FAILURE','sqlstate'=>$e instanceof PDOException?(string)($e->errorInfo[0]??''):null,'mysql_code'=>$e instanceof PDOException?(int)($e->errorInfo[1]??0):null,'production_connected'=>false,'release_decision'=>'NO-GO'];
}finally{
    if($admin)foreach($owned as$db)if(preg_match('/^sulb_hist_restore_[a-f0-9]{16}$/D',$db))try{$admin->exec('DROP DATABASE '.historyId($db));}catch(Throwable $e){$result['snapshot_restore_rehearsal']='BLOCKED';$result['reason']='HISTORY_OWNED_DATABASE_CLEANUP_FAILED';}
}
if($proof!==null&&preg_match('#^/home/mgye-api-sulb/sulb-history-restore-[a-f0-9]{16}/history-result\.json$#D',$proof)){file_put_contents($proof,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);chmod($proof,0600);}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['snapshot_restore_rehearsal']==='PASS'?0:78);
