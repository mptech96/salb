<?php
/** File fingerprints only. Never boot Laravel, read env/cache or access a database. */
function releaseCodePath(string $path):bool {
    if(!preg_match('#^(backend|frontend)/[A-Za-z0-9_./@()\[\] -]+$#D',$path)||str_contains($path,'..'))return false;
    if(preg_match('#(^|/)(\.env[^/]*|vendor|node_modules|storage|bootstrap/cache|\.next[^/]*|uploads|\.git)(/|$)#i',$path))return false;
    if(str_starts_with($path,'backend/')&&!preg_match('#^backend/(app/|config/|routes/|resources/|lang/|database/(migrations|schema)/|bootstrap/(app|providers)\.php$|composer\.(json|lock)$)#',$path))return false;
    if(str_starts_with($path,'frontend/')&&!preg_match('#^frontend/((app|src|components|lib|utils|hooks|types|styles)/|[^/]+$)#',$path))return false;
    return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['php','ts','tsx','js','jsx','mjs','cjs','json','lock','css','scss','sql','xml'],true);
}
function releaseCodeFingerprint(string $path):?array {
    if(is_link($path)||!is_file($path))return null;
    if(filesize($path)>16777216)throw new RuntimeException('SOURCE_FILE_TOO_LARGE');
    $bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('SOURCE_UNREADABLE');
    return ['raw'=>hash('sha256',$bytes),'lf'=>hash('sha256',str_replace("\r\n","\n",$bytes))];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    if(($argv[1]??'')!=='--compare-live')throw new RuntimeException('EXPLICIT_COMPARE_REQUIRED');
    $root=dirname(__DIR__,2);$targets=['backend'=>'/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech','frontend'=>'/home/mgye-sulb/htdocs/sulb.mgye.tech'];
    $p=proc_open(['git','--no-optional-locks','-c','safe.directory='.$root,'-C',$root,'ls-files','-z','--','backend','frontend'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('SOURCE_INVENTORY_FAILED');$raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($p);unset($err);if($status!==0)throw new RuntimeException('SOURCE_INVENTORY_FAILED');
    $rows=[];$known=[];$counts=[];
    foreach(explode("\0",$raw)as$path){if(!releaseCodePath($path))continue;$known[$path]=true;[$component,$suffix]=explode('/',$path,2);
        $candidatePath=$root.'/'.$path;$livePath=$targets[$component].'/'.$suffix;
        $liveReal=realpath($livePath);if($liveReal!==false&&!str_starts_with(str_replace('\\','/',$liveReal),$targets[$component].'/'))throw new RuntimeException('LIVE_PATH_ESCAPES_ROOT');
        $candidate=releaseCodeFingerprint($candidatePath);if(!$candidate)throw new RuntimeException('CANDIDATE_SOURCE_UNAVAILABLE');$live=releaseCodeFingerprint($livePath);
        $comparison=$live===null?'CANDIDATE_ONLY':($candidate['raw']===$live['raw']?'IDENTICAL':($candidate['lf']===$live['lf']?'LINE_ENDINGS_ONLY':'CONTENT_DIFFERS'));
        $counts[$comparison]=($counts[$comparison]??0)+1;$rows[]=['path'=>$path,'comparison'=>$comparison,'candidate'=>$candidate,'live'=>$live];
    }
    // Detect live-only code in application directories; never traverse generated/private trees.
    $extra=[];foreach($targets as$component=>$base){
        if(!is_dir($base)||is_link($base))throw new RuntimeException('LIVE_ROOT_UNAVAILABLE');
        $iter=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),function($file,$key,$iterator)use($base,$component){if($file->isLink())return false;$relative=$component.'/'.substr($file->getPathname(),strlen($base)+1);if($file->isDir())return !preg_match('#(^|/)(\.env[^/]*|vendor|node_modules|storage|cache|\.next[^/]*|uploads|\.git)(/|$)#i',$relative);return releaseCodePath($relative);}));
        foreach($iter as$file){$path=$component.'/'.substr($file->getPathname(),strlen($base)+1);if(!isset($known[$path]))$extra[]=['path'=>$path,'live'=>releaseCodeFingerprint($file->getPathname())];if(count($extra)+count($rows)>20000)throw new RuntimeException('INVENTORY_LIMIT_REACHED');}
    }
    $out='/home/mgye-api-sulb/sulb-code-diff-'.bin2hex(random_bytes(12));umask(0077);if(!mkdir($out,0700))throw new RuntimeException('PRIVATE_OUTPUT_REQUIRED');
    $report=['mode'=>'LIVE_CODE_FINGERPRINT_DIFF','database_connections'=>0,'file_contents_exported'=>false,'env_or_generated_files_read'=>false,'counts'=>$counts,'live_only_count'=>count($extra),'files'=>$rows,'live_only_files'=>$extra,'release_decision'=>'NO-GO','limits'=>['Fingerprints do not certify behavioral or schema compatibility.','Line ending normalization is diagnostic only.','Unknown live-only customizations block replacement until reviewed.','Scope is runtime source/config/package manifests; static assets, QA files and generated/private trees excluded.']];
    $file=$out.'/code-diff.json';if(file_put_contents($file,json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('OUTPUT_FAILED');chmod($file,0600);
    echo json_encode(['comparison_completed'=>true,'counts'=>$counts,'live_only_count'=>count($extra),'database_connections'=>0,'file_contents_exported'=>false,'report_file'=>$file,'sha256'=>hash_file('sha256',$file),'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"STOP: code comparison incomplete; no deployment action performed.\n");exit(1);}
