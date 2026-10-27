<?php
require_once __DIR__.'/staging-prepare.php';
function privateSchemaCounts(array $j):array {
    if(($j['mode']??null)!=='PRIVATE_SCHEMA_ONLY'||($j['business_rows_exported']??null)!==false)throw new RuntimeException('INVALID_CAPTURE');
    $counts=array_fill_keys(['TABLE','VIEW','TRIGGER','PROCEDURE','FUNCTION','EVENT'],0);$seen=[];
    foreach($j['definitions']as$o){$kind=$o['kind'];$key=$kind.':'.$o['name'];if(!array_key_exists($kind,$counts)||isset($seen[$key]))throw new RuntimeException('INVALID_OBJECT');$seen[$key]=true;
        $ddl=array_filter($o['ddl'][0]??[],fn($v,$k)=>str_starts_with(strtolower((string)$k),'create ')&&is_string($v)&&trim($v)!=='',ARRAY_FILTER_USE_BOTH);
        if(count($ddl)!==1)throw new RuntimeException('MISSING_CREATE_DEFINITION');$counts[$kind]++;
    }
    return $counts;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    if(!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('OS_ROOT_REQUIRED');
    $file=$argv[1]??'';$expected=$argv[2]??'';
    if(!preg_match('#^/home/mgye-api-sulb/sulb-private-schema-[a-f0-9]{24}/schema-private\.json$#D',$file)||is_link($file)||!is_file($file)||!hash_equals($expected,hash_file('sha256',$file)))throw new RuntimeException('CAPTURE_HASH_MISMATCH');
    $counts=privateSchemaCounts(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR));
    $p=proc_open(['/usr/bin/clpctl','db:show:master-credentials','--no-ansi'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('AUTH_FAILED');
    $raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($p);unset($error);if($status!==0)throw new RuntimeException('AUTH_FAILED');
    try{$c=cloudPanelMasterCredentials($raw);}finally{unset($raw);}
    try{$db=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname=sulbprod;charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>10]);}finally{unset($c);}
    $read=fn($sql)=>$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);schemaAdminVisibility($read);
    // Independent SHOW metadata inventory; no SHOW CREATE or business-row reads.
    $live=['TABLE'=>0,'VIEW'=>0];foreach($read('SHOW FULL TABLES FROM sulbprod')as$row){$type=$row['Table_type'];if(!in_array($type,['BASE TABLE','VIEW'],true))throw new RuntimeException('UNKNOWN_TABLE_TYPE');$live[$type==='VIEW'?'VIEW':'TABLE']++;}
    $live['TRIGGER']=count($read('SHOW TRIGGERS FROM sulbprod'));
    $live['PROCEDURE']=count($read("SHOW PROCEDURE STATUS WHERE Db='sulbprod'"));
    $live['FUNCTION']=count($read("SHOW FUNCTION STATUS WHERE Db='sulbprod'"));
    $live['EVENT']=count($read('SHOW EVENTS FROM sulbprod'));
    if($counts!==$live)throw new RuntimeException('INVENTORY_CHANGED_OR_INCOMPLETE');
    echo json_encode(['verification'=>'PASS','schema_file_sha256'=>$expected,'captured_counts'=>$counts,'admin_show_counts'=>$live,'zero_counts_explicitly_verified'=>true,'all_captured_objects_have_create_definition'=>true,'business_rows_exported'=>false,'ddl_printed'=>false,'schema_compatibility_approved'=>false,'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"STOP: private schema verification failed; do not share DDL or credentials.\n");exit(1);}
