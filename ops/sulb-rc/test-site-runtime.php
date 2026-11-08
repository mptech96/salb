<?php
require __DIR__.'/release-site-runtime.php';
$n=0;$assert=function(bool $v)use(&$n){if(!$v)throw new RuntimeException('SITE_RUNTIME_ASSERT_'.($n+1));$n++;};
$f=['sapi'=>'fpm-fcgi','php_major'=>8,'php_minor'=>5,'opcache_enabled'=>true,'validate_timestamps'=>true,'revalidate_freq'=>2,'file_update_protection'=>2,'max_site_cached_timestamp'=>time()-10,'preload_configured'=>false,'file_cache_configured'=>false];
$assert(releaseSiteCachePolicy($f)===6);$off=$f;$off['opcache_enabled']=false;$assert(releaseSiteCachePolicy($off)===0);
foreach([['sapi','cli'],['php_minor',4],['preload_configured',true],['file_cache_configured',true],['validate_timestamps',false],['revalidate_freq',31],['revalidate_freq',-1],['file_update_protection',31],['max_site_cached_timestamp',time()+300]]as[$key,$value]){$bad=$f;$bad[$key]=$value;try{releaseSiteCachePolicy($bad);$assert(false);}catch(RuntimeException $e){$assert(str_starts_with($e->getMessage(),'RELEASE_SITE_'));}}
$http=verifyReleaseHttpReadiness(fn($url,$backend)=>$backend?['status'=>503,'message'=>'SULB_RELEASE_WRITE_FENCE']:['status'=>200]);$assert($http['backend_http']==='FENCED_503');
foreach([['status'=>200],['status'=>503,'message'=>'unrelated error'],['status'=>502]]as$back){try{verifyReleaseHttpReadiness(fn($url,$backend)=>$backend?$back:['status'=>200]);$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='RELEASE_FENCED_HTTP_READINESS_FAILED');}}
try{verifyReleaseHttpReadiness(fn($url,$backend)=>$backend?['status'=>503,'message'=>'SULB_RELEASE_WRITE_FENCE']:['status'=>502]);$assert(false);}catch(RuntimeException $e){$assert(true);}
$proof=['operational_readiness'=>'PASS','approved_commit'=>str_repeat('a',40),'postcheck_sha256'=>str_repeat('b',64),'backend_http'=>'FENCED_503','frontend_http'=>200,'created_unix'=>time()];
requireReleaseOperationalProof($proof,str_repeat('a',40),str_repeat('b',64));$assert(true);
foreach(['operational_readiness','approved_commit','postcheck_sha256','backend_http','frontend_http','created_unix']as$key){$bad=$proof;unset($bad[$key]);try{requireReleaseOperationalProof($bad,str_repeat('a',40),str_repeat('b',64));$assert(false);}catch(RuntimeException $e){$assert(true);}}
foreach([time()-301,time()+300]as$t){$bad=$proof;$bad['created_unix']=$t;try{requireReleaseOperationalProof($bad,str_repeat('a',40),str_repeat('b',64));$assert(false);}catch(RuntimeException $e){$assert(true);}}
try{publishReleaseSitePhpTimes('/tmp');$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='RELEASE_SITE_PHP_ROOT_REQUIRED');}
$e=['file'=>'/home/mgye-api-sulb/sulb-writer-signoff-330e13362e20f1e0/site-runtime-'.str_repeat('a',16).'.json','sha256'=>str_repeat('b',64),'site_runtime'=>'PASS','shared_service_operations'=>false,'policy_source_sha256'=>str_repeat('c',64),'approved_profile_sha256'=>str_repeat('d',64),'facts'=>$f];requireReleaseSiteRuntimeEvidenceMetadata($e,str_repeat('c',64),str_repeat('d',64));$assert(true);
foreach(['file','sha256','site_runtime','shared_service_operations','policy_source_sha256','approved_profile_sha256','facts']as$key){$bad=$e;unset($bad[$key]);try{requireReleaseSiteRuntimeEvidenceMetadata($bad,str_repeat('c',64),str_repeat('d',64));$assert(false);}catch(RuntimeException $error){$assert(true);}}
$fence=file_get_contents(__DIR__.'/release-fence.php');$runtime=file_get_contents(__DIR__.'/release-site-runtime.php');$deploy=file_get_contents(__DIR__.'/deploy-reviewed-release.sh');
$assert(!preg_match("/\['systemctl','(?:reload|restart|stop)',\\\$before\['php_fpm_unit'\]/",$fence));
$assert(!str_contains($runtime,'opcache_reset(')&&!str_contains($runtime,'posix_kill('));
$assert(strpos($fence,'requireReleaseOperationalProof(')<strpos($fence,'releaseRestoreAccountStatus($admin'));
$assert(strpos($deploy,'--verify-readiness')<strpos($deploy,'--release "$backup"'));
$tail=substr($deploy,strpos($deploy,'--release "$backup"'));$assert(!str_contains($tail,'systemctl')&&!str_contains($tail,'curl ')&&!str_contains($tail,'--resume-workers'));
require_once __DIR__.'/release-fence.php';
foreach([['reload','php8.5-fpm.service'],['restart','php8.5-fpm.service'],['stop','php8.5-fpm.service'],['stop','cron.service'],['stop','deal.service'],['reload','sulb-frontend.service']]as[$action,$unit]){try{releaseOperationPolicy(['systemctl',$action,$unit]);$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='RELEASE_SHARED_SERVICE_OPERATION_FORBIDDEN');}}
try{releaseOperationPolicy(['/bin/systemctl','reload','php8.5-fpm.service']);$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='RELEASE_SHARED_SERVICE_OPERATION_FORBIDDEN');}
echo "PASS $n pool-cache policy, fenced HTTP readiness and final-unlock contracts; no_process_signals=true; production_connections=0\n";
