<?php
/** Read-only process label and policy inspection. Never load/replace/cache a profile. */
function nativePolicySummary(string $text):array {
    $rules=[];$deny=false;$variables=false;
    foreach(preg_split('/\r?\n/',$text)as$line){$line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
        if(preg_match('/\bdeny\b/',$line))$deny=true;if(str_contains($line,'@{'))$variables=true;
        // Only known infrastructure paths; never export complete policy/other users' rules.
        if(preg_match('#^\s*(?:(?:audit|deny|owner)\s+)*(?:"?/(?:var/lib/mysql|var/log/mysql|var/run/mysqld|run/mysqld|tmp|proc|sys)(?:/|\b))#',$line))$rules[]=$line;
    }
    return ['preprocessed_sha256'=>hash('sha256',$text),'relevant_path_rules'=>array_slice(array_values(array_unique($rules)),0,60),'rule_output_truncated'=>count(array_unique($rules))>60,'deny_rules_present'=>$deny,'variables_present'=>$variables,
      'effective_path_permission_proven'=>false];
}
function nativePolicyRead(array $argv):array {
    $p=proc_open($argv,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('INSPECTION_COMMAND_UNAVAILABLE');$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($p);unset($error);return [$status,$out];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('LINUX_ROOT_READ_REQUIRED');
    [$status,$pid]=nativePolicyRead(['systemctl','show','mysql.service','--property=MainPID','--value']);$pid=trim($pid);if($status!==0||!ctype_digit($pid)||(int)$pid<2)throw new RuntimeException('RUNNING_SERVICE_PID_UNAVAILABLE');
    $exe=readlink('/proc/'.$pid.'/exe');$label=trim(file_get_contents('/proc/'.$pid.'/attr/current'));if(!$exe||$label==='')throw new RuntimeException('LIVE_LABEL_UNAVAILABLE');
    $name=preg_replace('/\s+\((?:enforce|complain|kill|unconfined)\)$/','',$label);
    $parser=is_executable('/usr/sbin/apparmor_parser')?'/usr/sbin/apparmor_parser':(is_executable('/sbin/apparmor_parser')?'/sbin/apparmor_parser':null);
    $files=[];
    foreach(glob('/etc/apparmor.d/*')as$file){
        if(!is_file($file)||is_link($file)||filesize($file)>2097152)continue;
        $text=file_get_contents($file);
        if(!str_contains($text,$exe)&&!in_array(basename($file),['usr.sbin.mysqld','mysqld'],true)&&!($name!=='unconfined'&&preg_match('/\bprofile\s+"?'.preg_quote($name,'/').'"?(?:\s|\{)/',$text)))continue;
        $entry=['file'=>$file,'source_sha256'=>hash('sha256',$text),'declares_active_label'=>false,'preprocess'=>'UNAVAILABLE'];
        if($parser){
            // -N names, -p preprocess; Q=no kernel load,K=no cache,T=no cache read.
            [$s,$names]=nativePolicyRead([$parser,'-N','-Q','-K','-T',$file]);
            if($s===0)$entry['declares_active_label']=in_array($name,array_map('trim',preg_split('/\r?\n/',$names)),true);
            [$s,$expanded]=nativePolicyRead([$parser,'-p','-Q','-K','-T',$file]);
            if($s===0){$entry['preprocess']='PASS_READ_ONLY';$entry['review']=nativePolicySummary($expanded);}else $entry['preprocess']='FAILED_STOP_REVIEW';
            unset($expanded,$names);
        }
        $files[]=$entry;unset($text);
    }
    // Abort if the service process changed while evidence was being read.
    [$status,$after]=nativePolicyRead(['systemctl','show','mysql.service','--property=MainPID','--value']);
    if($status!==0||trim($after)!==$pid)throw new RuntimeException('SERVICE_PROCESS_CHANGED');
    echo json_encode(['mode'=>'READ_ONLY_APPARMOR_REVIEW','database_connections'=>0,'mysqld_started'=>false,'policy_loaded_or_changed'=>false,'cache_write_requested'=>false,
      'running_executable'=>$exe,'actual_process_label'=>$label,'profile_source_candidates'=>$files,
      'source_equals_loaded_policy_proven'=>false,
      'native_design'=>'Private mount/PID/network namespace: empty owned data/socket/log directories mounted at EXISTING allowed paths only within child namespace; original host paths stay unchanged. Same executable retains AppArmor. No renamed/unconfined binary or policy bypass.',
      'proposed_schema_only_limits'=>['mysqld_memory_mib'=>512,'experiment_total_memory_mib'=>2048,'cpu_cores_max'=>1,'pids_max'=>256,'private_disk_budget_gib'=>5,'io_limit'=>'requires block-device/cgroup review before launch'],
      'native_staging_approved'=>false,'remaining_review'=>['Resolve expanded variables/deny/owner rules and on-disk versus loaded policy discrepancy.','Prove private mount propagation and host data/socket invisibility before any mysqld initialization.','Verify delegated cgroup limits and I/O controls; no policy changes allowed.'],
      'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"STOP: AppArmor inspection incomplete; no profile/service/database changes attempted.\n");exit(1);}
