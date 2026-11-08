<?php
/** New artifact selection/provenance contracts only. All private bytes below are synthetic fixtures. */
require __DIR__.'/release-qualification.php';
$r=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);$root=dirname(__DIR__,2);
$n=0;$assert=function($ok)use(&$n){if(!$ok)throw new RuntimeException('SITE_BINDING_ASSERT_'.($n+1));$n++;};
$e=$r['runtime_execution_corrections']['accepted_evidence'];$policy=releaseQualificationHash($root,'release-site-runtime.php');$profile=$r['write_fence_plan']['profile_sha256'];
requirePinnedSiteRuntimeDescriptor($e,$policy,$profile);$assert(true);
$assert(!array_key_exists('max_site_cached_timestamp',$e['reported_facts']));
$assert($e['inspection_commit']==='79f9d05c7f0fb0ecfac5a85aeb7694753b24588d');
$assert($e['sha256']==='6ee8ea9ddb281e20194bb19b068e09b46655747a8748833659fd2f41b834f752');
$assert($r['deployment_authorized']===false&&$r['release_decision']==='NO-GO');
$assert($r['production_backup']['creation_authorized']===false&&$r['write_fence_plan']['temporary_account_lock_authorized']===false);
verifyReleaseQualificationBindings($r,$root);$assert(releasePendingGates($r)===['deployment_authorized']);
foreach(['file','sha256','inspection_commit','policy_source_sha256','approved_profile_sha256','reported_facts']as$key){$bad=$e;unset($bad[$key]);try{requirePinnedSiteRuntimeDescriptor($bad,$policy,$profile);$assert(false);}catch(RuntimeException $x){$assert(true);}}
foreach([['validate_timestamps',false],['revalidate_freq',31],['file_update_protection',31],['preload_configured',true],['file_cache_configured',true],['opcache_status_proven',false],['uid',null]]as[$key,$value]){$bad=$e;$bad['reported_facts'][$key]=$value;try{requirePinnedSiteRuntimeDescriptor($bad,$policy,$profile);$assert(false);}catch(RuntimeException $x){$assert(true);}}
// Do NOT pretend to possess the production artifact. Generate and hash unrelated in-memory test bytes.
$a=['site_runtime'=>'PASS','inspection_commit'=>$e['inspection_commit'],'policy_source_sha256'=>$policy,'approved_profile_sha256'=>$profile,'shared_service_operations'=>false,'production_database_connections'=>0,'master_signalled'=>false,'facts'=>$e['reported_facts']+['max_site_cached_timestamp'=>time()-10],'cache_wait_seconds'=>6];
$bytes=json_encode($a,JSON_THROW_ON_ERROR);$fixture=$e;$fixture['sha256']=hash('sha256',$bytes);
$pass=verifyPinnedSiteRuntimeBytes($bytes,$fixture);$assert($pass['site_runtime_evidence']==='PASS'&&$pass['cache_wait_seconds']===6);
try{verifyPinnedSiteRuntimeBytes($bytes.' ',$fixture);$assert(false);}catch(RuntimeException $x){$assert($x->getMessage()==='RELEASE_SITE_RUNTIME_EVIDENCE_CHANGED');}
foreach(['inspection_commit','policy_source_sha256','approved_profile_sha256','master_signalled','shared_service_operations','production_database_connections']as$key){$bad=$a;$bad[$key]=$key==='master_signalled'||$key==='shared_service_operations'?true:($key==='production_database_connections'?1:str_repeat('0',strlen($a[$key])));$b=json_encode($bad);$f=$fixture;$f['sha256']=hash('sha256',$b);try{verifyPinnedSiteRuntimeBytes($b,$f);$assert(false);}catch(RuntimeException $x){$assert($x->getMessage()==='RELEASE_SITE_RUNTIME_PROVENANCE_MISMATCH');}}
foreach(['uid','max_site_cached_timestamp','cache_wait_seconds']as$key){$bad=$a;if($key==='cache_wait_seconds')$bad[$key]=0;else$bad['facts'][$key]=$key==='uid'?1005:time()+3600;$b=json_encode($bad);$f=$fixture;$f['sha256']=hash('sha256',$b);try{verifyPinnedSiteRuntimeBytes($b,$f);$assert(false);}catch(RuntimeException $x){$assert(true);}}
$bad=$a;unset($bad['facts']['max_site_cached_timestamp']);$b=json_encode($bad);$f=$fixture;$f['sha256']=hash('sha256',$b);try{verifyPinnedSiteRuntimeBytes($b,$f);$assert(false);}catch(RuntimeException $x){$assert($x->getMessage()==='RELEASE_SITE_OPCACHE_POLICY_UNPROVEN');}
$entry=file_get_contents(__DIR__.'/check-approval.php');$assert(strpos($entry,'verifyPrivateSiteRuntimeEvidence($release)')!==false);
$assert(str_contains($entry,"'--verify-site-runtime'"));
$assert($r['runtime_execution_corrections']['runtime_window_executed']===false);
echo "PASS $n pinned-runtime digest/provenance/fact-drift and pre-execution guard contracts; actual_private_artifact_read=false; database_connections=0\n";
