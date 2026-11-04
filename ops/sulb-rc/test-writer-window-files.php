<?php
/** CI-only root-private synthetic fixtures. No production evidence, account, service or database is used. */
require __DIR__.'/release-fence-evidence.php';
require __DIR__.'/writer-window-prepare.php';
require __DIR__.'/test-writer-signoff-fixture.php';
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||getenv('GITHUB_ACTIONS')!=='true'
    ||!str_starts_with(dirname(__DIR__,2),'/home/runner/work/')){
    echo 'WINDOW_FILE_TEST_REQUIRES_ISOLATED_CI'.PHP_EOL;exit(78);
}
$dir='/home/mgye-api-sulb/sulb-writer-signoff-'.bin2hex(random_bytes(8));
$srcDir='/home/mgye-api-sulb/sulb-release-review-'.bin2hex(random_bytes(8));
if(file_exists($dir)||file_exists($srcDir))throw new RuntimeException('SYNTHETIC_FIXTURE_PATH_EXISTS');
mkdir($dir,0700,true);mkdir($srcDir,0700,true);$created=[];$n=0;
$fixtureCheckout=false;$originalCwd=getcwd();
$write=function($file,$bytes)use(&$created){if(!in_array($file,$created,true))$created[]=$file;file_put_contents($file,$bytes);chmod($file,0600);clearstatcache(true,$file);};
$json=fn($v)=>json_encode($v,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
$reject=function(callable $call,string $expected)use(&$n){try{$call();}catch(RuntimeException $e){if($e->getMessage()!==$expected)throw $e;$n++;return;}throw new RuntimeException('WINDOW_FILE_TEST_EXPECTED_'.$expected);};
try{
    [$source,$facts,$pending]=writerSignoffFixture();
    $path='/etc/php/8.5/fpm/pool.d/SYNTHETIC.conf';$configSha=hash('sha256','SYNTHETIC_CONFIG');
    $source['format']='SULB_PRIVATE_WRITER_PROFILE_V1';
    $source['plan']['site_uid']=19001;$source['plan']['php_fpm_unit']=null;$source['plan']['writer_units']=[];
    $source['surface_files']=[['path'=>$path,'sha256'=>$configSha]];
    $source['external_writer_source_files']=[];
    $source['review']=['complete'=>false,'external_writers_and_schedules_reviewed'=>false];
    $facts['service_candidates']=[['path'=>$path,'sha256'=>$configSha,'candidate_principals'=>['synthetic_app@localhost']]];
    $source['writer_linkage']=['missing_proofs'=>array_column($facts['missing_proofs'],'code'),
        'service_schedule_candidates'=>$facts['service_candidates']];
    $sourceFile=$srcDir.'/profile-private.json';$write($sourceFile,$json($source));
    $sourceSha=hash_file('sha256',$sourceFile);$facts['source_profile_sha256']=$sourceSha;
    $write($dir.'/evidence-private.json',$json($facts));$evidenceSha=hash_file('sha256',$dir.'/evidence-private.json');
    $pdf='%PDF-1.4 SYNTHETIC UNIT FIXTURE, NOT A REAL SIGNATURE';
    $write($dir.'/signed-decision.pdf',$pdf);$documentSha=hash('sha256',$pdf);
    $pending['source_profile_sha256']=$sourceSha;$pending['evidence_sha256']=$evidenceSha;
    $pending['signed_document_sha256']=$documentSha;$pending['approved']=false;
    foreach($pending['accounts']as&$a)$a['signed_document_sha256']=$documentSha;unset($a);
    $pool=['configured_pool_binding'=>'PASS','listener_owned_by_master'=>true,'site_uid'=>19001,'config_hashes'=>[$path=>$configSha]];
    $poolName='pool-binding-'.str_repeat('a',16).'.json';$poolFile=$dir.'/'.$poolName;
    $write($poolFile,$json($pool));$poolSha=hash_file('sha256',$poolFile);
    $reference=['file'=>$poolName,'sha256'=>$poolSha];
    [$profile,$signed]=buildWriterWindowCandidate($source,$facts,$pending,$pool,$reference);
    $signed['approved']=true; // Synthetic fixture only.
    $decisionFile=$dir.'/decisions-approved.json';$write($decisionFile,$json($signed));
    $profile['writer_signoff']=['packet_directory'=>$dir,'source_profile_file'=>$sourceFile,
        'evidence_sha256'=>$evidenceSha,'decision_sha256'=>hash_file('sha256',$decisionFile)];
    loadVerifiedWriterSignoff($profile);$n++;
    $write($dir.'/signed-decision.pdf',$pdf.'CHANGED');
    $reject(fn()=>loadVerifiedWriterSignoff($profile),'WRITER_SIGNED_DOCUMENT_FILE_REQUIRED');
    $write($dir.'/signed-decision.pdf',$pdf);
    $write($poolFile,$json($pool).' ');
    $reject(fn()=>loadVerifiedWriterSignoff($profile),'WRITER_WINDOW_PRIVATE_POOL_HASH_MISMATCH');
    $write($poolFile,$json($pool));
    chmod($poolFile,0644);clearstatcache(true,$poolFile);
    $reject(fn()=>loadVerifiedWriterSignoff($profile),'PRIVATE_EVIDENCE_REQUIRED');
    chmod($poolFile,0600);clearstatcache(true,$poolFile);
    unlink($poolFile);symlink($dir.'/evidence-private.json',$poolFile);clearstatcache(true,$poolFile);
    $reject(fn()=>loadVerifiedWriterSignoff($profile),'PRIVATE_EVIDENCE_REQUIRED');
    unlink($poolFile);$write($poolFile,$json($pool));
    $bad=$signed;$bad['window_evidence']['pool_binding']['file']='../outside.json';
    $write($decisionFile,$json($bad));$badProfile=$profile;$badProfile['writer_signoff']['decision_sha256']=hash_file('sha256',$decisionFile);
    $reject(fn()=>loadVerifiedWriterSignoff($badProfile),'WRITER_WINDOW_PRIVATE_POOL_FILE_REQUIRED');
    $bad=$signed;$bad['approved']=false;$write($decisionFile,$json($bad));
    $badProfile=$profile;$badProfile['writer_signoff']['decision_sha256']=hash_file('sha256',$decisionFile);
    $reject(fn()=>loadVerifiedWriterSignoff($badProfile),'WRITER_REVIEWED_SIGNOFF_REQUIRED');
    $write($decisionFile,$json($signed));
    $badProfile=$profile;$badProfile['database_accounts'][0]['capability_evidence'][]='GLOBAL:Super_priv';
    $reject(fn()=>loadVerifiedWriterSignoff($badProfile),'WRITER_SOURCE_FACTS_NOT_PRESERVED');
    loadVerifiedWriterSignoff($profile);$n++;
    $profileFile=$dir.'/profile-window-private.json';$write($profileFile,$json($profile));
    $release=['write_fence_plan'=>['profile_authorized'=>true,'qualification_mode'=>WRITER_WINDOW_MODE,
        'profile_file'=>$profileFile,'profile_sha256'=>hash_file('sha256',$profileFile)]];
    $reject(fn()=>releaseWithReviewedFencePlan($release),'RELEASE_WINDOW_REVIEW_REQUIRED');
    $badRelease=$release;unset($badRelease['write_fence_plan']['qualification_mode']);
    $reject(fn()=>releaseWithReviewedFencePlan($badRelease),'RELEASE_PRIVATE_WRITER_PROFILE_REQUIRED');
    $badRelease=$release;$badRelease['write_fence_plan']['qualification_mode']='UNKNOWN';
    $reject(fn()=>releaseWithReviewedFencePlan($badRelease),'RELEASE_UNKNOWN_WRITER_QUALIFICATION_MODE');
    $badProfile=$profile;$badProfile['review']['qualification_mode']='OTHER';
    $write($profileFile,$json($badProfile));$badRelease=$release;$badRelease['write_fence_plan']['profile_sha256']=hash_file('sha256',$profileFile);
    $reject(fn()=>releaseWithReviewedFencePlan($badRelease),'RELEASE_WRITER_QUALIFICATION_MODE_MISMATCH');
    $write($profileFile,$json($profile));$badRelease=$release;$badRelease['write_fence_plan']['profile_sha256']=str_repeat('f',64);
    $reject(fn()=>releaseWithReviewedFencePlan($badRelease),'RELEASE_WRITER_PROFILE_CHANGED');
    // Exercise the real preparation entry point using the exact Linux context, in this isolated CI VM only.
    if(file_exists('/home/sulb-release'))throw new RuntimeException('ISOLATED_CHECKOUT_ALREADY_EXISTS');
    mkdir('/home/sulb-release',0700);$fixtureCheckout=true;chdir('/home/sulb-release');
    unlink($decisionFile);unlink($profileFile);
    $write($dir.'/decisions-reviewed-pending.json',$json($pending));
    $write($dir.'/signature-receipt-private.json',$json([
        'signed_document_sha256'=>$documentSha,'review_reference'=>$pending['review_reference'],
        'owner_reaffirmation_recorded_at_utc'=>$pending['attested_at_utc']]));
    $hashes=[];foreach([$sourceFile,$dir.'/evidence-private.json',$dir.'/signed-decision.pdf',$dir.'/decisions-reviewed-pending.json']as$f)$hashes[$f]=hash_file('sha256',$f);
    foreach(['decisions-window-pending.json','profile-window-pending.json']as$name)$created[]=$dir.'/'.$name;
    $args=['SYNTHETIC_SCRIPT','--prepare-window',$dir,$sourceFile,$sourceSha,$poolName,$poolSha];
    ob_start();$code=writerWindowPrepareCli($args);$reply=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    if($code!==0||$reply['window_candidate_policy']!=='PASS'||$reply['approved']!==false)throw new RuntimeException('WINDOW_PREPARATION_FAILED');$n++;
    foreach($hashes as$f=>$h)if(hash_file('sha256',$f)!==$h)throw new RuntimeException('ORIGINAL_EVIDENCE_CHANGED');$n++;
    if(file_exists($decisionFile)||file_exists($profileFile))throw new RuntimeException('WINDOW_PREPARATION_ACTIVATED_APPROVAL');$n++;
    ob_start();$code=writerWindowPrepareCli($args);$reply=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);if($code!==0){echo json_encode($reply).PHP_EOL;throw new RuntimeException('WINDOW_PREPARATION_NOT_IDEMPOTENT_'.($reply['reason']??'UNKNOWN'));}$n++;
    $candidateFile=$dir.'/decisions-window-pending.json';$originalCandidate=file_get_contents($candidateFile);
    $write($candidateFile,$originalCandidate.' ');
    ob_start();$code=writerWindowPrepareCli($args);$reply=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    if($code!==78||$reply['reason']!=='WRITER_WINDOW_EXISTING_CANDIDATE_DIFFERS'||file_get_contents($candidateFile)!==$originalCandidate.' ')throw new RuntimeException('WINDOW_PREPARATION_OVERWROTE_CANDIDATE');$n++;
    echo 'PASS '.$n.' root-private window evidence and signature binding checks; production_connections=0; actual_approvals_recorded=false'.PHP_EOL;
}finally{
    chdir($originalCwd);if($fixtureCheckout)rmdir('/home/sulb-release');
    // Only explicitly created fixture files, never a recursive removal or customer archive.
    foreach(array_reverse($created)as$file)if(is_file($file)||is_link($file))unlink($file);
    rmdir($dir);rmdir($srcDir);
}
