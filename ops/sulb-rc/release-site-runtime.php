<?php
/** Helpers of the existing executor. Never reload a master, stop another site or reset shared OPcache. */
function releaseSiteCachePolicy(array $facts): int {
    if(($facts['sapi']??null)!=='fpm-fcgi'||($facts['php_major']??null)!==8||($facts['php_minor']??null)!==5
        ||($facts['preload_configured']??true)!==false||($facts['file_cache_configured']??true)!==false)throw new RuntimeException('RELEASE_SITE_FPM_RUNTIME_UNQUALIFIED');
    if(($facts['opcache_enabled']??null)===false)return 0;
    if(($facts['opcache_enabled']??null)!==true||($facts['validate_timestamps']??null)!==true
        ||!is_int($facts['revalidate_freq']??null)||!is_int($facts['file_update_protection']??null)
        ||min($facts['revalidate_freq'],$facts['file_update_protection'])<0
        ||max($facts['revalidate_freq'],$facts['file_update_protection'])>30
        ||!is_int($facts['max_site_cached_timestamp']??null)||$facts['max_site_cached_timestamp']>time())throw new RuntimeException('RELEASE_SITE_OPCACHE_POLICY_UNPROVEN');
    return $facts['revalidate_freq']+$facts['file_update_protection']+2;
}
function requireReleaseSiteRuntimeEvidenceMetadata(array $e,string $policySha,string $profileSha):void {
    if(!preg_match('#^/home/mgye-api-sulb/sulb-writer-signoff-330e13362e20f1e0/site-runtime-[a-f0-9]{16}\.json$#D',$e['file']??'')
        ||!preg_match('/^[a-f0-9]{64}$/D',$e['sha256']??'')||($e['site_runtime']??null)!=='PASS'
        ||($e['policy_source_sha256']??null)!==$policySha||($e['approved_profile_sha256']??null)!==$profileSha
        ||($e['shared_service_operations']??null)!==false||!is_array($e['facts']??null))throw new RuntimeException('RELEASE_EFFECTIVE_SITE_RUNTIME_EVIDENCE_REQUIRED');
    releaseSiteCachePolicy($e['facts']);
}
function releaseSiteFcgi(string $socket,string $script): array {
    $s=stream_socket_client((str_starts_with($socket,'/')?'unix://'.$socket:'tcp://'.$socket),$error,$message,5);if(!$s)throw new RuntimeException('RELEASE_SITE_FCGI_UNAVAILABLE');stream_set_timeout($s,15);
    $record=static function(int $type,string $bytes)use($s):void{$packet=pack('CCnnCC',1,$type,1,strlen($bytes),0,0).$bytes;while($packet!==''){$n=fwrite($s,$packet);if(!$n)throw new RuntimeException('RELEASE_SITE_FCGI_WRITE_FAILED');$packet=substr($packet,$n);}};
    $record(1,pack('nCxxxxx',1,0));$params='';
    foreach(['GATEWAY_INTERFACE'=>'CGI/1.1','SERVER_PROTOCOL'=>'HTTP/1.1','REQUEST_METHOD'=>'GET','SCRIPT_FILENAME'=>$script,'SCRIPT_NAME'=>'/private-runtime-inspection.php','REQUEST_URI'=>'/private-runtime-inspection.php','SERVER_NAME'=>'api.sulb.mgye.tech','SERVER_PORT'=>'443','REMOTE_ADDR'=>'127.0.0.1','CONTENT_LENGTH'=>'0','PHP_ADMIN_VALUE'=>"auto_prepend_file=\nauto_append_file=\n"]as$k=>$v)$params.=chr(strlen($k)).(strlen($v)<128?chr(strlen($v)):pack('N',strlen($v)|0x80000000)).$k.$v;
    $record(4,$params);$record(4,'');$record(5,'');$out='';$finished=false;
    $read=static function(int $length)use($s):string{$v='';while(strlen($v)<$length){$b=fread($s,$length-strlen($v));if($b===false||$b==='')throw new RuntimeException('RELEASE_SITE_FCGI_RESPONSE_INCOMPLETE');$v.=$b;}return$v;};
    try{for($i=0;$i<256;$i++){$h=unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved',$read(8));if($h['version']!==1||$h['id']!==1)throw new RuntimeException('RELEASE_SITE_FCGI_PROTOCOL');$b=$read($h['length']);if($h['padding'])$read($h['padding']);if($h['type']===6)$out.=$b;if(strlen($out)>16384)throw new RuntimeException('RELEASE_SITE_FCGI_RESPONSE_LIMIT');if($h['type']===3){$end=unpack('Nstatus/Cprotocol',substr($b,0,5));$finished=$end['status']===0&&$end['protocol']===0;break;}}}finally{fclose($s);}
    if(!$finished||!str_contains($out,"\r\n\r\n"))throw new RuntimeException('RELEASE_SITE_FCGI_FAILED');return json_decode(explode("\r\n\r\n",$out,2)[1],true,512,JSON_THROW_ON_ERROR);
}
function inspectReleaseSiteRuntime(array $release): array {
    $r=releaseWithReviewedFencePlan($release);$snapshot=releaseProcessSnapshot($r,true);
    $pool='/etc/php/8.5/fpm/pool.d/api.sulb.mgye.tech.conf';
    $values=parse_ini_file($pool,true,INI_SCANNER_RAW);$listen=[];foreach($values as$v)if(is_array($v)&&isset($v['listen'])){$listen[]=$v['listen'];if(($v['pm']??null)!=='ondemand')throw new RuntimeException('RELEASE_SITE_ONDEMAND_REQUIRED');}
    if(count($listen)!==1||!preg_match('#^(?:(?:/run|/var/run)/(?:php|php-fpm)/[A-Za-z0-9_.-]+\.sock|127\.0\.0\.1:[1-9][0-9]{0,4})$#D',$listen[0])||!is_file($pool))throw new RuntimeException('RELEASE_SITE_OWNED_LISTENER_REQUIRED');
    if(($r['write_fence_plan']['writer_units']??null)!==[])throw new RuntimeException('RELEASE_SITE_WORKER_RESUME_REVIEW_REQUIRED');
    $site=posix_getpwnam('mgye-api-sulb');$dir='/tmp/sulb-runtime-probe-'.bin2hex(random_bytes(8));$file=$dir.'/inspect.php';$nonce=bin2hex(random_bytes(16));
    if(realpath('/tmp')!=='/tmp'||fileowner('/tmp')!==0||(fileperms('/tmp')&01000)===0||!mkdir($dir,0700)||!chgrp($dir,$site['gid'])||!chmod($dir,0710))throw new RuntimeException('RELEASE_SITE_PRIVATE_PROBE_REQUIRED');
    $program= <<<'PHP_PROBE'
<?php
ini_set('display_errors','0');ini_set('log_errors','0');header('Content-Type: application/json');
$active=function_exists('opcache_get_status')?opcache_get_status(true):false;$max=0;
if(is_array($active))foreach($active['scripts']??[]as$path=>$entry)if(str_starts_with($path,'/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/'))$max=max($max,(int)($entry['timestamp']??0));
echo json_encode(['nonce'=>NONCE_VALUE,'uid'=>function_exists('posix_geteuid')?posix_geteuid():null,'sapi'=>PHP_SAPI,'php_major'=>PHP_MAJOR_VERSION,'php_minor'=>PHP_MINOR_VERSION,'opcache_enabled'=>is_array($active)&&($active['opcache_enabled']??false),'opcache_status_proven'=>is_array($active)||!filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOL),'validate_timestamps'=>filter_var(ini_get('opcache.validate_timestamps'),FILTER_VALIDATE_BOOL),'revalidate_freq'=>(int)ini_get('opcache.revalidate_freq'),'file_update_protection'=>(int)ini_get('opcache.file_update_protection'),'max_site_cached_timestamp'=>$max,'preload_configured'=>trim((string)ini_get('opcache.preload'))!=='','file_cache_configured'=>trim((string)ini_get('opcache.file_cache'))!=='','user_ini_filename'=>(string)ini_get('user_ini.filename')],JSON_THROW_ON_ERROR);
PHP_PROBE;
    try{$program=str_replace('NONCE_VALUE',var_export($nonce,true),$program);$h=fopen($file,'xb');if(!$h||!chmod($file,0640)||!chgrp($file,$site['gid']))throw new RuntimeException('RELEASE_SITE_PRIVATE_PROBE_REQUIRED');try{if(fwrite($h,$program)!==strlen($program)||!fflush($h)||!fsync($h))throw new RuntimeException('RELEASE_SITE_PRIVATE_PROBE_REQUIRED');}finally{fclose($h);}$facts=releaseSiteFcgi($listen[0],$file);if(($facts['nonce']??null)!==$nonce||($facts['uid']??null)!==$snapshot['site_uid']||($facts['opcache_status_proven']??false)!==true)throw new RuntimeException('RELEASE_SITE_RUNTIME_IDENTITY_UNPROVEN');$ini=$facts['user_ini_filename']??null;if(!is_string($ini)||($ini!==''&&!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D',$ini)))throw new RuntimeException('RELEASE_SITE_USER_INI_UNPROVEN');if($ini!=='')foreach(['/home/mgye-api-sulb',$backend='/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech',$backend.'/public']as$ancestor)if(file_exists($ancestor.'/'.$ini)||is_link($ancestor.'/'.$ini))throw new RuntimeException('RELEASE_SITE_DIRECTORY_INI_REVIEW_REQUIRED');$wait=releaseSiteCachePolicy($facts);unset($facts['nonce'],$facts['user_ini_filename']);return ['site_runtime'=>'PASS','facts'=>$facts,'cache_wait_seconds'=>$wait,'master_signalled'=>false,'shared_service_operations'=>false,'production_database_connections'=>0];}
    finally{if(is_file($file)&&!unlink($file))throw new RuntimeException('RELEASE_SITE_PROBE_CLEANUP_FAILED');if(is_dir($dir)&&!rmdir($dir))throw new RuntimeException('RELEASE_SITE_PROBE_CLEANUP_FAILED');}
}
function releaseSitePhpFiles(string $backend): array {
    if(!in_array($backend,['/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech','/home/sulb-release/backend'],true)||is_link($backend)||realpath($backend)!==$backend)throw new RuntimeException('RELEASE_SITE_PHP_ROOT_REQUIRED');
    $files=[];foreach(['app','bootstrap','config','routes','vendor']as$folder){$path=$backend.'/'.$folder;if(!is_dir($path)||is_link($path))throw new RuntimeException('RELEASE_SITE_PHP_TREE_REQUIRED');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));foreach($it as$f){if($f->isLink())throw new RuntimeException('RELEASE_SITE_PHP_FILE_UNSAFE');if(strtolower($f->getExtension())!=='php')continue;$st=lstat($f->getPathname());if($f->isLink()||!$f->isFile()||$st['nlink']!==1||count($files)>30000)throw new RuntimeException('RELEASE_SITE_PHP_FILE_UNSAFE');$files[]=$f->getPathname();}}
    $files[]=$backend.'/public/index.php';foreach($files as$file)if(is_link($file)||!is_file($file)||lstat($file)['nlink']!==1)throw new RuntimeException('RELEASE_SITE_PHP_FILE_UNSAFE');return $files;
}
function publishReleaseSitePhpTimes(string $backend): void {
    if($backend!=='/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech')throw new RuntimeException('RELEASE_SITE_PHP_ROOT_REQUIRED');
    $files=releaseSitePhpFiles($backend);sleep(2);$now=time();foreach($files as$file)if(!touch($file,$now))throw new RuntimeException('RELEASE_SITE_PHP_TIMESTAMP_FAILED');
}
function requireReleaseOperationalProof(array $proof,string $commit,string $postHash): void {
    if(($proof['operational_readiness']??null)!=='PASS'||($proof['approved_commit']??null)!==$commit||($proof['postcheck_sha256']??null)!==$postHash||($proof['backend_http']??null)!=='FENCED_503'||($proof['frontend_http']??null)!==200||!is_int($proof['created_unix']??null)||$proof['created_unix']>time()||time()-$proof['created_unix']>300)throw new RuntimeException('RELEASE_OPERATIONAL_READINESS_REQUIRED');
}
function verifyReleaseHttpReadiness(callable $http): array {
    $backend=$http('https://api.sulb.mgye.tech/up',true);$front=$http('https://sulb.mgye.tech/',false);
    if(($backend['status']??null)!==503||($backend['message']??null)!=='SULB_RELEASE_WRITE_FENCE'||($front['status']??null)!==200)throw new RuntimeException('RELEASE_FENCED_HTTP_READINESS_FAILED');
    return ['backend_http'=>'FENCED_503','frontend_http'=>200];
}
