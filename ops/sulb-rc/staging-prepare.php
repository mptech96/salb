<?php
/** Preparation only. No SQL executor, row exporter or staging importer is included. */
function stagingPlan(array $metadata):array {
    if(($metadata['mode']??null)!=='READ_ONLY_PREFLIGHT')throw new RuntimeException('METADATA_REQUIRED');
    $columns=$metadata['metadata'][3]??[];
    if(!$columns)throw new RuntimeException('COLUMNS_REQUIRED');
    $seen=[];$policy=[];
    foreach($columns as$c){
        $key=$c['TABLE_NAME'].'.'.$c['COLUMN_NAME'];
        if(isset($seen[$key]))throw new RuntimeException('DUPLICATE_COLUMN');$seen[$key]=true;
        // Never guess from column names: JSON/text, audit payloads, identifiers and
        // free-form financial references can carry personal data or secrets.
        $policy[]=['table'=>$c['TABLE_NAME'],'column'=>$c['COLUMN_NAME'],'type'=>$c['COLUMN_TYPE'],'decision'=>'UNREVIEWED','transform'=>null];
    }
    return ['mode'=>'OFFLINE_STAGING_PLAN','production_reads'=>false,'row_export_enabled'=>false,
        'release_decision'=>'NO-GO','schema_sha256'=>$metadata['schema_sha256']??null,
        'required_review'=>['Every column requires an approved explicit policy; no name-based auto classification.',
        'Sensitive primary/foreign/business identifiers require one consistent collision-free mapping across the whole relational closure.',
        'Preserve exact approved UOM quantities, FIFO order, monetary values, FX, timestamps/order and GL/LOT source relationships.',
        'Embedded JSON, documents, notes, audit payloads and credentials require semantic review; deleting arbitrary fields can break evidence hashes.',
        'No production rows may be exported by this tool. If safe transformation cannot be proven, use an operator-controlled private isolated staging enclave; test code moves to data, not data to GitHub/chat.',
        'Representative history and its permitted destination must be approved independently before any extraction.'],
        'columns'=>$policy];
}
function cloudPanelMasterCredentials(string $output):array {
    $output=preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/','',$output);$fields=[];
    foreach(preg_split('/\r?\n/',$output)as$line){
        if(preg_match('/^\s*\|\s*(Host|Host Name|User|User Name|Username|Password|Port)\s*\|([^|]*)\|\s*$/i',$line,$m)
          ||preg_match('/^\s*(Host|Host Name|User|User Name|Username|Password|Port)\s*:\s*(.*?)\s*$/i',$line,$m)){
            $key=match(strtolower($m[1])){'host','host name'=>'host','user','user name','username'=>'user',default=>strtolower($m[1])};
            if(isset($fields[$key]))throw new RuntimeException('AMBIGUOUS_MASTER_OUTPUT');$fields[$key]=trim($m[2]);
        }
    }
    if(count($fields)!==4||!in_array($fields['host']??null,['127.0.0.1','localhost'],true)
       ||!preg_match('/^[1-9][0-9]{0,4}$/D',$fields['port']??'')||(int)$fields['port']>65535
       ||!preg_match('/^[a-zA-Z0-9_.-]+$/D',$fields['user']??'')||($fields['password']??'')===''
       ||in_array(strtolower($fields['user']),['sulbprod','sulbmetadata','sulb_metadata_ro'],true))throw new RuntimeException('MASTER_OUTPUT_UNVERIFIED');
    return $fields;
}
function schemaCapture(callable $read):array {
    $quote=fn($s)=>'`'.str_replace('`','``',$s).'`';$out=['mode'=>'PRIVATE_SCHEMA_ONLY','business_rows_exported'=>false,'automatic_import_authorized'=>false,'definitions'=>[]];
    $out['server']=$read('SELECT VERSION() AS version, @@version_comment AS distribution');
    // Reject inherited roles rather than infer complete visibility from partial lists.
    $roles=$read('SELECT CURRENT_ROLE() AS role_name');
    if(!in_array($roles[0]['role_name']??null,[null,'NONE'],true))throw new RuntimeException('DIRECT_ADMIN_VISIBILITY_REQUIRED');
    $grants=$read('SHOW GRANTS FOR CURRENT_USER');$global=[];
    foreach($grants as$row){$g=(string)array_values($row)[0];if(preg_match('/^GRANT (.*?) ON \*\.\* TO /',$g,$m))$global=array_merge($global,explode(', ',$m[1]));}
    if(!in_array('ALL PRIVILEGES',$global,true)&&array_diff(['SELECT','SHOW VIEW','TRIGGER','EVENT'],$global))throw new RuntimeException('COMPLETE_ADMIN_VISIBILITY_UNPROVEN');
    // Grant statements may contain authentication material; never include them.
    foreach($read("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA='sulbprod' ORDER BY TABLE_NAME")as$r){
        $kind=$r['TABLE_TYPE']==='VIEW'?'VIEW':'TABLE';$out['definitions'][]=['kind'=>$kind,'name'=>$r['TABLE_NAME'],'ddl'=>$read('SHOW CREATE '.$kind.' `sulbprod`.'.$quote($r['TABLE_NAME']))];
    }
    foreach(['TRIGGER'=>['TRIGGERS','TRIGGER_NAME','TRIGGER_SCHEMA'], 'PROCEDURE'=>['ROUTINES','ROUTINE_NAME','ROUTINE_SCHEMA'], 'FUNCTION'=>['ROUTINES','ROUTINE_NAME','ROUTINE_SCHEMA'], 'EVENT'=>['EVENTS','EVENT_NAME','EVENT_SCHEMA']]as$kind=>[$table,$column,$schema]){
        $filter=$table==='ROUTINES'?" AND ROUTINE_TYPE='$kind'":'';
        foreach($read("SELECT $column AS object_name FROM information_schema.$table WHERE $schema='sulbprod'$filter ORDER BY $column")as$r)$out['definitions'][]=['kind'=>$kind,'name'=>$r['object_name'],'ddl'=>$read('SHOW CREATE '.$kind.' `sulbprod`.'.$quote($r['object_name']))];
    }
    $out['visibility']='direct global administrator grants verified; no roles assumed';
    return $out;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    $mode=$argv[1]??'--plan';
    if($mode==='--plan'){
        echo "PREPARATION ONLY: no connection or production extraction.\nSchema capture requires separate approval and --execute-schema-only.\nNo production-row export implemented: all-column privacy policy and representative-history approval required.\nNO-GO\n";exit(0);
    }
    if($mode==='--policy-template'){
        $j=json_decode(file_get_contents($argv[2]??''),true,512,JSON_THROW_ON_ERROR);echo json_encode(stagingPlan($j),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
    }
    if(!in_array($mode,['--execute-schema-only','--execute-schema-only-cloudpanel'],true))throw new RuntimeException('ROW_EXTRACTION_DISABLED');
    if(PHP_OS_FAMILY==='Windows')throw new RuntimeException('VPS_TTY_REQUIRED');
    umask(0077);$tty=fopen('/dev/tty','r+');if(!$tty)throw new RuntimeException('TTY_REQUIRED');
    fwrite($tty,"Separate schema-only approval required. Type APPROVED_SCHEMA_ONLY: ");if(trim(fgets($tty))!=='APPROVED_SCHEMA_ONLY')throw new RuntimeException('APPROVAL_REQUIRED');
    if($mode==='--execute-schema-only-cloudpanel'){
        fclose($tty);
        if(!function_exists('posix_geteuid')||posix_geteuid()!==0||!is_executable('/usr/bin/clpctl'))throw new RuntimeException('CLOUDPANEL_ROOT_REQUIRED');
        // Official read-only CLI; capture credentials only in process memory.
        // Never eval the returned Connect Command or put passwords in argv/env/files.
        $process=proc_open(['/usr/bin/clpctl','db:show:master-credentials','--no-ansi'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('MASTER_LOOKUP_FAILED');
        $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);unset($error);
        if($code!==0)throw new RuntimeException('MASTER_LOOKUP_FAILED');
        try{$credentials=cloudPanelMasterCredentials($output);}finally{unset($output);}
        try{$db=new PDO('mysql:host='.$credentials['host'].';port='.$credentials['port'].';dbname=sulbprod;charset=utf8mb4',$credentials['user'],$credentials['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10]);}finally{unset($credentials);}
    }else{
    fwrite($tty,'Existing authorized administrator username (not application or metadata account): ');$user=trim(fgets($tty));
    if(!$user||in_array(strtolower($user),['sulbprod','sulbmetadata','sulb_metadata_ro'],true))throw new RuntimeException('EXISTING_ADMIN_REQUIRED');
    fwrite($tty,'Password (hidden): ');exec('stty -echo < /dev/tty',$unused,$status);if($status!==0)throw new RuntimeException('HIDDEN_INPUT_REQUIRED');
    try{$password=rtrim(fgets($tty),"\r\n");}finally{exec('stty echo < /dev/tty');fwrite($tty,"\n");fclose($tty);}
    try{$db=new PDO('mysql:host=localhost;dbname=sulbprod;charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}finally{unset($password);}
    }
    $read=function($sql)use($db){if(!preg_match('/^(SELECT |SHOW (CREATE |GRANTS ))/D',$sql))throw new RuntimeException('READ_ONLY_REQUIRED');return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);};
    $result=schemaCapture($read);
    $base='/home/mgye-api-sulb/sulb-private-schema-'.bin2hex(random_bytes(12));if(!mkdir($base,0700))throw new RuntimeException('PRIVATE_OUTPUT_REQUIRED');
    $file=$base.'/schema-private.json';$json=json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);if(file_put_contents($file,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('OUTPUT_FAILED');chmod($file,0600);
    $counts=[];foreach($result['definitions']as$object)$counts[$object['kind']]=($counts[$object['kind']]??0)+1;
    $summary=['capture_completed'=>true,'schema_file_sha256'=>hash_file('sha256',$file),'object_counts'=>$counts,'business_rows_exported'=>false,'automatic_import_authorized'=>false,'release_decision'=>'NO-GO'];
    if(file_put_contents($base.'/verification.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('SUMMARY_FAILED');chmod($base.'/verification.json',0600);
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'PRIVATE_SCHEMA_FILE='.$file.PHP_EOL.'SHA256='.hash_file('sha256',$file).PHP_EOL."Keep on private operator-controlled staging only; DDL can contain sensitive literals/definers. Do not send raw DDL to chat or GitHub. NO-GO\n";
}catch(Throwable $e){fwrite(STDERR,"STOP: preparation/capture failed; do not widen grants or export rows.\n");exit(1);}
