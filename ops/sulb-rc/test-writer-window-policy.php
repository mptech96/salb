<?php
/** Pure, synthetic window-policy and existing database-fence tests. Never connects to a server. */
require __DIR__.'/release-fence-evidence.php';
require __DIR__.'/writer-window-prepare.php';
require __DIR__.'/release-process-fence.php';
require __DIR__.'/test-writer-signoff-fixture.php';

$checks=0;
function windowTest(bool $condition): void {
    global $checks; if (!$condition) throw new RuntimeException('WINDOW_TEST_'.($checks+1)); $checks++;
}
function windowReject(callable $call, string $reason): void {
    try { $call(); } catch (RuntimeException $e) { windowTest($e->getMessage()===$reason); return; }
    windowTest(false);
}
$legacy=writerSignoffFixture();
[$source,$facts,$pending,$factsSha,$sourceSha]=$legacy;
$source['plan']['site_uid']=19001;
$source['plan']['php_fpm_unit']=null; // Real ondemand may have no idle child.
$source['plan']['writer_units']=[];
$path='/etc/php/8.5/fpm/pool.d/SYNTHETIC.conf'; $configSha=hash('sha256','SYNTHETIC_CONFIG_NOT_REAL');
$source['surface_files']=[['path'=>$path,'sha256'=>$configSha]];
$source['external_writer_source_files']=[];
$source['review']=['complete'=>false,'external_writers_and_schedules_reviewed'=>false];
$facts['service_candidates']=[['path'=>$path,'sha256'=>$configSha,'candidate_principals'=>['synthetic_app@localhost']]];
$pending['approved']=false;
$pool=['configured_pool_binding'=>'PASS','listener_owned_by_master'=>true,'site_uid'=>19001,
    'config_hashes'=>[$path=>$configSha],'mode'=>'ondemand','queue_absence_proven'=>false];
$ref=['file'=>'pool-binding-'.str_repeat('a',16).'.json','sha256'=>hash('sha256','SYNTHETIC_POOL_JSON')];
$original=serialize([$source,$facts,$pending,$pool]);
[$profile,$signed]=buildWriterWindowCandidate($source,$facts,$pending,$pool,$ref);
windowTest(serialize([$source,$facts,$pending,$pool])===$original);
windowTest($signed['approved']===false && $profile['review']['window_review_complete']===false);
windowTest($signed['services'][0]['mysql_authentication_proven']===false
    && $signed['services'][0]['account_principals']===[] && $profile['plan']['php_fpm_unit']==='php8.5-fpm.service');
$signed['approved']=true; // Synthetic approved fixture, not a real recorded decision.
$base=[$profile,$facts,$signed,$factsSha,$sourceSha,[],['pool_binding'=>$pool]];
validateWriterSignoff(...$base); windowTest(true);
$reject=function(callable $mutate,string $reason)use($base){$v=$base;$mutate($v);windowReject(fn()=>validateWriterSignoff(...$v),$reason);};
$reject(function(&$v){$v[2]['approved']=false;},'WRITER_REVIEWED_SIGNOFF_REQUIRED');
$reject(function(&$v){$v[2]['deployment_authorized']=true;},'WRITER_REVIEWED_SIGNOFF_REQUIRED');
$reject(function(&$v){$v[2]['signed_document_sha256']=str_repeat('f',64);},'WRITER_SIGNED_DATE_AND_DOCUMENT_REQUIRED');
$reject(function(&$v){$v[2]['role']='UNAUTHORIZED';},'WRITER_AUTHORIZED_DECISION_REQUIRED');
$reject(function(&$v){$v[2]['attested_at_utc']='2999-01-01T00:00:00Z';},'WRITER_SIGNED_DATE_AND_DOCUMENT_REQUIRED');
$reject(function(&$v){$v[2]['evidence_sha256']=str_repeat('f',64);},'WRITER_SIGNOFF_EVIDENCE_BINDING');
$reject(function(&$v){$v[2]['source_profile_sha256']=str_repeat('f',64);},'WRITER_SIGNOFF_EVIDENCE_BINDING');
$reject(function(&$v){$v[2]['qualification_mode']='UNKNOWN';},'WRITER_UNKNOWN_QUALIFICATION_MODE');
$reject(function(&$v){unset($v[0]['review']['qualification_mode']);},'WRITER_WINDOW_MODE_NOT_EXPLICIT');
$reject(function(&$v){$v[0]['review']['reviewer']='OTHER';},'WRITER_WINDOW_REVIEW_IDENTITY_MISMATCH');
$reject(function(&$v){$v[2]['retained_unproved_categories']=[];},'WRITER_WINDOW_ORIGINAL_LIMITATIONS_REQUIRED');
$reject(function(&$v){$v[2]['runtime_limitations']['external_writer_absence_proven']=true;},'WRITER_WINDOW_LIMITATIONS_MUST_REMAIN_EXPLICIT');
$reject(function(&$v){$v[2]['proof_resolutions']=[['resolution'=>'RESOLVED_WITH_PRIVATE_EVIDENCE']];},'WRITER_WINDOW_NO_SYNTHETIC_PROOF_RESOLUTIONS');
$reject(function(&$v){$v[2]['window_commitments']['drain_existing_database_sessions']=false;},'WRITER_WINDOW_COMMITMENT_REQUIRED');
$reject(function(&$v){$v[2]['window_commitments']['admin_channel_exclusive_during_window']=false;},'WRITER_WINDOW_COMMITMENT_REQUIRED');
$reject(function(&$v){array_pop($v[2]['accounts']);},'WRITER_ALL_ACCOUNT_DECISIONS_REQUIRED');
$reject(function(&$v){$v[0]['database_accounts'][0]['capability_evidence'][]='GLOBAL:Super_priv';},'WRITER_APPROVED_CAPABILITIES_CHANGED');
$reject(function(&$v){$v[0]['database_accounts'][0]['global_write_or_admin']=true;},'WRITER_APPROVED_ACCOUNT_FACTS_CHANGED');
$reject(function(&$v){$v[2]['accounts'][0]['maintenance_responsible_owner']='OTHER';},'WRITER_WINDOW_ACCOUNT_RESPONSIBILITY_REQUIRED');
$reject(function(&$v){$v[2]['accounts'][0]['signed_document_sha256']=str_repeat('f',64);},'WRITER_SIGNED_DATE_AND_DOCUMENT_REQUIRED');
$reject(function(&$v){$v[2]['accounts'][0]['decision']='EXCLUSIVE_ADMIN_CHANNEL';},'WRITER_WINDOW_ACCOUNT_DECISION_REJECTED');
$reject(function(&$v){$v[2]['accounts'][0]['capabilities']=[];},'WRITER_EVERY_CAPABILITY_REQUIRES_DECISION');
$reject(function(&$v){$v[2]['accounts'][0]['capabilities'][0]['decision']='APPROVE_SCOPED_CAPABILITY';},'WRITER_WINDOW_PERMANENT_PRIVILEGES_NOT_APPROVED');
$reject(function(&$v){$v[2]['permanent_capability_audit']['status']='PASS';},'WRITER_WINDOW_PERMANENT_AUDIT_TRACKING_REQUIRED');
$reject(function(&$v){$v[0]['plan']['trusted_privileged_accounts'][]='unreviewed@localhost';},'WRITER_WINDOW_EXACT_ACCOUNT_PLAN_REQUIRED');
$reject(function(&$v){$v[0]['plan']['writer_accounts'][]='unreviewed@localhost';},'WRITER_WINDOW_EXACT_ACCOUNT_PLAN_REQUIRED');
$reject(function(&$v){$v[2]['services'][0]['mysql_authentication_proven']=true;},'WRITER_WINDOW_POOL_CLASSIFICATION_REQUIRED');
$reject(function(&$v){$v[2]['services'][0]['scheduler_launcher_proven']=true;},'WRITER_WINDOW_POOL_CLASSIFICATION_REQUIRED');
$reject(function(&$v){$v[2]['services'][0]['account_principals']=['synthetic_app@localhost'];},'WRITER_WINDOW_POOL_CLASSIFICATION_REQUIRED');
$reject(function(&$v){$v[6]['pool_binding']['listener_owned_by_master']=false;},'WRITER_WINDOW_POOL_EVIDENCE_REQUIRED');
$reject(function(&$v){$v[6]['pool_binding']['site_uid']=19002;},'WRITER_WINDOW_POOL_EVIDENCE_REQUIRED');
$reject(function(&$v){$v[6]['pool_binding']['config_hashes'][$v[1]['service_candidates'][0]['path']]=str_repeat('f',64);},'WRITER_WINDOW_POOL_SOURCE_NOT_REVIEWED');
$reject(function(&$v){$v[0]['plan']['writer_units']=['cron.service'];},'WRITER_WINDOW_WORKER_UNIT_REVIEW_REQUIRED');
$reject(function(&$v){$v[0]['plan']['writer_units']=['sulb-frontend.service'];},'WRITER_WINDOW_WORKER_UNIT_REVIEW_REQUIRED');

// Actual DB fence validator, unchanged: reject unknown writers, undrained A04 and extra A05 sessions.
$rows=$profile['database_accounts']; $reviewed=$rows; $rows[0]['locked']=true;
$plan=$profile['plan']+['reviewed_inventory'=>$reviewed];
$admin='synthetic_admin@localhost';
releaseFenceValidate($rows,$plan,$admin,[]); windowTest(true);
$unknown=$rows;$unknown[]=['principal'=>'unknown@%','locked'=>false,'can_write_sulbprod'=>true,'global_write_or_admin'=>false,'capability_evidence'=>[]];
windowReject(fn()=>releaseFenceValidate($unknown,$plan,$admin,[]),'WRITER_REVIEWED_INVENTORY_MEMBERSHIP_CHANGED');
$unlocked=$rows;$unlocked[0]['locked']=false;
windowReject(fn()=>releaseFenceValidate($unlocked,$plan,$admin,[]),'RELEASE_DATABASE_WRITER_NOT_EXCLUDED');
foreach (['synthetic_app','synthetic_admin','synthetic_disabled'] as $user) {
    windowReject(fn()=>releaseFenceValidate($rows,$plan,$admin,[['user'=>$user,'schema'=>null,'is_current'=>false]]),
        'RELEASE_OLD_DATABASE_CLIENTS_NOT_DRAINED');
}
$changed=$rows;$changed[0]['capability_evidence'][]='SOURCE_SCHEMA:Delete_priv';
windowReject(fn()=>releaseFenceValidate($changed,$plan,$admin,[]),'WRITER_REVIEWED_PRIVILEGES_CHANGED');
$changed=$rows;$changed[2]['locked']=false;
windowReject(fn()=>releaseFenceValidate($changed,$plan,$admin,[]),'RELEASE_UNREVIEWED_DATABASE_WRITER');

// Permanent capability debt is recorded, not justified or silently approved.
$large=$pending;$largeSource=$source;$largeFacts=$facts;
foreach ([0=>11,1=>54] as $i=>$count) {
    $caps=[];for($j=0;$j<$count;$j++)$caps[]='SYNTHETIC_CAPABILITY_'.$j;
    $largeSource['database_accounts'][$i]['capability_evidence']=$caps;
    $largeFacts['accounts'][$i]['observed_capabilities']=$caps;
    $large['accounts'][$i]['capabilities']=array_map(fn($c)=>['capability'=>$c,'decision'=>null,'justification'=>null,'required_for'=>null],$caps);
}
[$largeProfile,$largeDecision]=buildWriterWindowCandidate($largeSource,$largeFacts,$large,$pool,$ref);
windowTest($largeDecision['permanent_capability_audit']['active_writer_capability_count']===65);
windowTest($largeDecision['accounts'][0]['capabilities'][0]['justification']===null);

$receipt=['site_uid'=>19001,'php_fpm_unit'=>'php8.5-fpm.service',
    'workers'=>[['pid'=>123,'start_ticks'=>'100']], 'cli_writers'=>[['pid'=>124,'start_ticks'=>'101']]];
$current=['site_uid'=>19001,'php_fpm_unit'=>'php8.5-fpm.service','cli_writers'=>[]];
windowTest(releaseAssertProcessDrain($receipt,$current,fn($pid)=>null)['old_application_processes_drained']);
windowReject(fn()=>releaseAssertProcessDrain($receipt,$current,fn($pid)=>['start_ticks'=>$pid===123?'100':'CHANGED']),
    'RELEASE_OLD_PHP_WRITER_STILL_ALIVE');
windowReject(fn()=>releaseAssertProcessDrain($receipt,$current,fn($pid)=>['start_ticks'=>$pid===124?'101':'CHANGED']),
    'RELEASE_OLD_PHP_WRITER_STILL_ALIVE');
$newCli=$current;$newCli['cli_writers']=[['pid'=>125,'start_ticks'=>'102']];
windowReject(fn()=>releaseAssertProcessDrain($receipt,$newCli,fn($pid)=>null),'RELEASE_UNREGISTERED_LIVE_CLI_WRITER');
$wrongSite=$current;$wrongSite['site_uid']=19002;
windowReject(fn()=>releaseAssertProcessDrain($receipt,$wrongSite,fn($pid)=>null),'RELEASE_PROCESS_PROFILE_CHANGED');
windowTest(releaseAssertProcessDrain($receipt,$current,fn($pid)=>['start_ticks'=>'REUSED_PID_DIFFERENT_PROCESS'])['old_application_processes_drained']);
echo 'PASS '.$checks.' focused window qualification checks; actual_approvals_recorded=false; production_connections=0'.PHP_EOL;
