<?php
/** Explicit metadata readiness now; production capture stays approval-gated. */
require_once __DIR__.'/history-archive.php';
require_once __DIR__.'/../../backend/scripts/release_metadata_collect.php';
const HISTORY_PINNED_SHA='901f01f5069078cc1174f5bdd913609abbf72f2cadab9852cff05133a5a9047f';
const HISTORY_PINNED_FILE='/home/mgye-api-sulb/sulb-private-schema-88b4a61422be64ba8a53bdee/schema-private.json';
function historyBudget(int $estimated,int $free,int $available):array {
    $required=max(4294967296,$estimated*8+2147483648);
    if($available<1610612736)throw new RuntimeException('HISTORY_MEMORY_HEADROOM_REQUIRED');
    if($free<$required)throw new RuntimeException('HISTORY_DISK_HEADROOM_REQUIRED');
    return ['estimated_source_bytes'=>$estimated,'required_free_bytes'=>$required,'free_bytes'=>$free,'max_archive_bytes'=>min($estimated*3+536870912,intdiv($free-2147483648,6)),'memory_max_bytes'=>268435456,'cpu_quota'=>'25000 100000','pids_max'=>32,'io_bytes_per_second'=>1048576];
}
final class HistorySchemaSelectRequired extends RuntimeException {
    public function __construct(public readonly array $evidence,public readonly string $sqlForReview){parent::__construct('HISTORY_SCHEMA_SELECT_REQUIRED');}
}
function historySchemaReadEvidence(array $grants):array {
    $present=[];
    foreach($grants as$grant){
        if(!is_string($grant)||stripos($grant,'WITH GRANT OPTION')!==false||!preg_match('/^GRANT (.*?) ON (.*?) TO /s',$grant,$m))throw new RuntimeException('HISTORY_READ_SCOPE_UNVERIFIED');
        $privileges=array_map('trim',explode(',',$m[1]));$scope=str_replace('`','',$m[2]);
        if($scope==='*.*'&&$privileges===['USAGE'])continue;
        if($scope!=='sulbprod.*'||array_diff($privileges,['SELECT','SHOW VIEW']))throw new RuntimeException('HISTORY_READ_SCOPE_UNVERIFIED');
        $present=array_merge($present,$privileges);
    }
    // This snapshot supports only BASE TABLE/InnoDB; inventory still rejects VIEW.
    // SELECT covers row reads and SHOW CREATE TABLE. SHOW VIEW is optional here.
    return ['required_schema_privileges'=>['SELECT'],'schema_wide_select'=>in_array('SELECT',$present,true),'show_view_granted'=>in_array('SHOW VIEW',$present,true),'base_tables_only'=>true];
}
function historyRequiredSchemaReadPrivileges(PDO $db):array {
    // Keep verifyReleaseMetadataReader() first: all role/global/write checks remain.
    $evidence=historySchemaReadEvidence($db->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN));
    if(!$evidence['schema_wide_select']){
        $identity=explode('@',(string)$db->query('SELECT CURRENT_USER()')->fetchColumn(),2);
        if(count($identity)!==2||$identity[0]!=='sulbmetadata')throw new RuntimeException('DEDICATED_READER_REQUIRED');
        $sql='GRANT SELECT ON `sulbprod`.* TO '.$db->quote($identity[0]).'@'.$db->quote($identity[1]).';';
        throw new HistorySchemaSelectRequired($evidence,$sql);
    }
    return $evidence;
}
function historyPolicyAllowsCapture(array $release):bool{return ($release['history_capture']['production_extraction_authorized']??false)===true;}
function historyApprovedReceipt(array $release,string $work,string $file):array {
    $expected=$release['history_capture']['approved_readiness_sha256']??null;
    if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_file($file)||is_link($file)||!hash_equals($expected,hash_file('sha256',$file)))throw new RuntimeException('HISTORY_APPROVED_READINESS_REQUIRED');
    $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($r['private_work_directory']??null)!==$work||!is_int($r['created_unix']??null)||time()-$r['created_unix']>86400||$r['created_unix']>time())throw new RuntimeException('HISTORY_APPROVED_READINESS_EXPIRED_OR_MOVED');
    return $r;
}
function historyPrivateDirectory(string $work):void {
    if(!preg_match('#^/home/mgye-api-sulb/sulb-history-ready-[a-f0-9]{16}$#D',$work)||!is_dir($work)||is_link($work)||realpath($work)!==$work||fileowner($work)!==0||(fileperms($work)&0077)!==0)throw new RuntimeException('HISTORY_PRIVATE_DIRECTORY_REQUIRED');
}
function historyHiddenReader():PDO {
    $tty=fopen('/dev/tty','r+');if(!$tty)throw new RuntimeException('HISTORY_TTY_REQUIRED');
    fwrite($tty,'Password for read-only sulbmetadata (hidden): ');exec('stty -echo < /dev/tty',$unused,$status);if($status!==0)throw new RuntimeException('HISTORY_HIDDEN_INPUT_REQUIRED');
    try{$line=fgets($tty);}finally{exec('stty echo < /dev/tty');fwrite($tty,"\n");fclose($tty);}
    if($line===false||rtrim($line,"\r\n")==='')throw new RuntimeException('HISTORY_PASSWORD_INPUT_REQUIRED');
    $password=rtrim($line,"\r\n");unset($line);
    try{$db=new PDO('mysql:host=localhost;dbname=sulbprod;charset=utf8mb4','sulbmetadata',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);$db->exec('SET SESSION lock_wait_timeout=5');$db->exec('SET SESSION max_execution_time=600000');return $db;}finally{unset($password);}
}
function historyCaptureReadiness(PDO $db,int $free,int $available):array {
    $gate=verifyReleaseMetadataReader($db);$scope=historyRequiredSchemaReadPrivileges($db);
    if($gate['server']['family']!=='Percona'||!str_starts_with($gate['server']['version'],'8.4.'))throw new RuntimeException('HISTORY_PERCONA84_REQUIRED');
    if(!is_file(HISTORY_PINNED_FILE)||is_link(HISTORY_PINNED_FILE)||realpath(HISTORY_PINNED_FILE)!==HISTORY_PINNED_FILE||filesize(HISTORY_PINNED_FILE)>16777216||!hash_equals(HISTORY_PINNED_SHA,hash_file('sha256',HISTORY_PINNED_FILE)))throw new RuntimeException('HISTORY_PINNED_SCHEMA_REQUIRED');
    $inventory=historyInventory($db);historyMatchPinned($inventory,json_decode(file_get_contents(HISTORY_PINNED_FILE),true,512,JSON_THROW_ON_ERROR));
    $estimated=array_sum(array_column($inventory,'estimated_bytes'));$budget=historyBudget($estimated,$free,$available);
    return ['mode'=>'HISTORY_READINESS','created_unix'=>time(),'server_uuid'=>$db->query('SELECT @@server_uuid')->fetchColumn(),'schema_capture_sha256'=>HISTORY_PINNED_SHA,'inventory_sha256'=>historyInventoryHash($inventory),'table_count'=>count($inventory),'authorization'=>['dedicated_reader'=>true,'roles_and_grants_verified'=>true]+$scope,'budget'=>$budget,'source_load_qualified'=>false,'limits_scope'=>'collector and isolated restore; source SELECT session timeouts do not throttle production server cgroup','production_rows_read'=>false,'production_extraction_authorized'=>false,'release_decision'=>'NO-GO'];
}
function historyCaptureCli(array $argv):int {
    ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
    try{
        $mode=$argv[1]??'';$work=$argv[2]??'';
        if(!in_array($mode,['--check-readiness','--capture-approved'],true))throw new RuntimeException('HISTORY_EXPLICIT_MODE_REQUIRED');
        $release=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);
        if($mode==='--capture-approved'&&!historyPolicyAllowsCapture($release))throw new RuntimeException('HISTORY_EXTRACTION_NOT_AUTHORIZED');
        if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('HISTORY_LINUX_ROOT_REQUIRED');
        historyPrivateDirectory($work);
        if(!preg_match('#^0::/sulb-schema-[a-f0-9]{32}$#D',trim(file_get_contents('/proc/self/cgroup'))))throw new RuntimeException('HISTORY_OWNED_COLLECTOR_CGROUP_REQUIRED');
        $limits=posix_getrlimit();if(!in_array($limits['soft core']??null,[0,'0'],true)||!in_array($limits['hard core']??null,[0,'0'],true))throw new RuntimeException('HISTORY_CORE_DUMPS_MUST_BE_DISABLED');
        if(!extension_loaded('sodium')||!extension_loaded('pdo_mysql'))throw new RuntimeException('HISTORY_REQUIRED_EXTENSION_MISSING');
        $available=0;if(preg_match('/^MemAvailable:\s+(\d+) kB/m',file_get_contents('/proc/meminfo'),$m))$available=(int)$m[1]*1024;
        if($mode==='--capture-approved')$approvedReceipt=historyApprovedReceipt($release,$work,$work.'/readiness-private.json');
        $db=historyHiddenReader();$ready=historyCaptureReadiness($db,(int)disk_free_space($work),$available);$ready['private_work_directory']=$work;
        if($mode==='--check-readiness'){
            $file=$work.'/readiness-private.json';if(file_exists($file))throw new RuntimeException('HISTORY_NEW_READINESS_REQUIRED');
            historyPrivateJson($file,$ready);
            echo json_encode(['history_readiness'=>'PASS','private_work_directory'=>$work,'readiness_sha256'=>hash_file('sha256',$file),'table_count'=>$ready['table_count'],'authorization'=>$ready['authorization'],'budget'=>$ready['budget'],'source_load_qualified'=>false,'production_rows_read'=>false,'snapshot_created'=>false,'production_extraction_authorized'=>false,'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;return 0;
        }
        $receiptFile=$work.'/readiness-private.json';$receipt=$approvedReceipt;
        if(time()-$receipt['created_unix']>86400||$receipt['created_unix']>time()||$receipt['server_uuid']!==$ready['server_uuid']||$receipt['inventory_sha256']!==$ready['inventory_sha256'])throw new RuntimeException('HISTORY_READINESS_EXPIRED_OR_CHANGED');
        $tty=fopen('/dev/tty','r+');fwrite($tty,"Separate production-row approval required. Type APPROVED_HISTORY_CAPTURE: ");$confirmation=trim(fgets($tty));fclose($tty);if($confirmation!=='APPROVED_HISTORY_CAPTURE')throw new RuntimeException('HISTORY_EXTRACTION_NOT_AUTHORIZED');
        $keyRoot='/home/mgye-api-sulb/sulb-history-keys';if(!is_dir($keyRoot))mkdir($keyRoot,0700);
        if(is_link($keyRoot)||realpath($keyRoot)!==$keyRoot||fileowner($keyRoot)!==0||(fileperms($keyRoot)&0077)!==0)throw new RuntimeException('HISTORY_PRIVATE_KEY_DIRECTORY_REQUIRED');
        $keyfile=$keyRoot.'/'.substr(basename($work),strlen('sulb-history-ready-')).'.key';$key=sodium_crypto_secretstream_xchacha20poly1305_keygen();$f=fopen($keyfile,'xb');historyWriteAll($f,$key);fflush($f);fsync($f);fclose($f);chmod($keyfile,0600);
        $db->exec('SET SESSION max_execution_time=600000');
        $manifest=historyCapture($db,$work.'/snapshot.enc',$key,$ready['budget']['max_archive_bytes']);historyVerify($work.'/snapshot.enc',$key,$manifest);
        historyPrivateJson($work.'/manifest-private.json',$manifest);sodium_memzero($key);
        echo json_encode(['history_capture'=>'PASS','snapshot_sha256'=>$manifest['file_sha256'],'private_work_directory'=>$work,'consistent_snapshot'=>true,'archive_integrity_verified'=>true,'encrypted'=>true,'restored'=>false,'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;return 0;
    }catch(Throwable $e){$allowed=['DEDICATED_READER_REQUIRED','ROLES_NOT_ALLOWED','MANDATORY_ROLES_NOT_ALLOWED','GRANT_OPTION_NOT_ALLOWED','PRIVILEGE_OUTSIDE_READ_SCOPE','UNVERIFIED_GRANT','UNVERIFIED_GRANT_SCOPE','NO_GRANT_EVIDENCE','UNSUPPORTED_METADATA_SERVER'];$code=preg_match('/^HISTORY_[A-Z0-9_]+$/D',$e->getMessage())||in_array($e->getMessage(),$allowed,true)?$e->getMessage():'HISTORY_READINESS_OR_CAPTURE_FAILED';$result=['history_status'=>'BLOCKED','reason'=>$code,'sqlstate'=>$e instanceof PDOException?(string)($e->errorInfo[0]??''):null,'mysql_code'=>$e instanceof PDOException?(int)($e->errorInfo[1]??0):null,'release_decision'=>'NO-GO'];
        if($e instanceof HistorySchemaSelectRequired){$result['authorization']=$e->evidence;$result['sql_for_review_only']=$e->sqlForReview;$result['privileges_modified']=false;}
        echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;return 78;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(historyCaptureCli($argv));
