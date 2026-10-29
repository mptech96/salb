<?php
/** One ordered executor entry, never a general artisan migrate/down wrapper. */
require_once __DIR__.'/production-upgrade-runtime.php';
define('SULB_UPGRADE_ROOT',dirname(__DIR__,2));
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
$exitCode=0;
try {
    $mode=$argv[1]??'--plan';if(count($argv)!==2||!in_array($mode,['--plan','--execute','--resume-derived'],true))throw new RuntimeException('UPGRADE_EXPLICIT_COMMAND_REQUIRED');
    $plan=\Sulb\Release\ProductionUpgradeExecutor::plan(SULB_UPGRADE_ROOT);
    if($mode==='--plan'){echo json_encode(['mode'=>'ORDERED_PRODUCTION_PLAN','migration_count'=>count($plan),'schedule'=>$plan,'original_qa_sources_untouched'=>true,'database_connections'=>0,'deployment_authorized'=>false],JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
    // Authorization happens before Laravel/.env/PDO. Never weaken this ordering.
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/check-approval.php'),$approval);if($approval!==0)exit(78);
    if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||getcwd()!=='/home/sulb-release'||SULB_UPGRADE_ROOT!=='/home/sulb-release'||!posix_setrlimit(POSIX_RLIMIT_CORE,0,0))throw new RuntimeException('UPGRADE_APPROVED_LINUX_CHECKOUT_REQUIRED');
    $receipt=getenv('SULB_UPGRADE_AUTHORIZATION_FILE')?:'';
    if(!preg_match('#^/home/mgye-api-sulb/sulb-release-backup\.[A-Za-z0-9]+/upgrade-authorization\.json$#D',$receipt)||is_link($receipt)||!is_file($receipt)||realpath($receipt)!==$receipt||fileowner($receipt)!==0||(fileperms($receipt)&0077)!==0||filesize($receipt)>65536)throw new RuntimeException('UPGRADE_PRIVATE_RECEIPT_REQUIRED');
    $proof=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);
    require_once __DIR__.'/release-fence-evidence.php';require_once __DIR__.'/trusted-release-application.php';$admin=releaseAdminConnection();$app=trustedReleaseApplication($admin);
    $release=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);$derivedFile=getenv('SULB_APPROVED_DERIVED_ARTIFACT')?:'';if(!preg_match('#^/home/mgye-api-sulb/sulb-history-restore-[a-f0-9]{16}/approved-history-details-private\.json$#D',$derivedFile))throw new RuntimeException('UPGRADE_PRIVATE_DERIVED_PLAN_REQUIRED');accountPrivate($derivedFile);$expected=$release['production_upgrade']['derived_artifact_sha256']??'';if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($expected,hash_file('sha256',$derivedFile)))throw new RuntimeException('UPGRADE_APPROVED_DERIVED_PLAN_HASH');$derived=json_decode(file_get_contents($derivedFile),true,512,JSON_THROW_ON_ERROR);require_once __DIR__.'/production-derived.php';if(!isset($derived['generator_sha256'])||($derived['authority_file_sha256']??null)!==($release['history_accounting_authority_sha256']??null)||($derived['production_derived_isolated_passed']??false)!==true||($derived['accounting_packet_sha256']??null)!==($release['legacy_allocation_accounting_approval']['prepared_packet_sha256']??null))throw new RuntimeException('UPGRADE_ACTUAL_DERIVED_EVIDENCE_REQUIRED');verifyDerivedSource($admin,$derived);
    \Sulb\Release\ProductionUpgradeContext::begin('production',$proof,$receipt);
    if($mode==='--resume-derived'){$journal=dirname($receipt).'/ordered-upgrade-events.jsonl';accountPrivate($journal);if(filesize($journal)>262144)throw new RuntimeException('UPGRADE_RESUME_JOURNAL_SIZE');$rows=array_map(fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),file($journal,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));verifyCompletedUpgradeJournal($rows,$plan);foreach($plan as$m)if(!\Illuminate\Support\Facades\DB::table('migrations')->where('migration',$m['repository_name'])->exists())throw new RuntimeException('UPGRADE_RESUME_MIGRATION_MISSING');$result=['ordered_adapters_applied'=>18,'resumed_derived_only'=>true];}
    else $result=\Sulb\Release\ProductionUpgradeExecutor::run(SULB_UPGRADE_ROOT,dirname($receipt).'/ordered-upgrade-events.jsonl');
    recordDerivedEvent(dirname($receipt),['state'=>'START','artifact_sha256'=>$expected]);$derivedResult=applyProductionDerived($derived,$mode==='--resume-derived');verifyCurrentBackupProjection($admin,dirname($receipt));recordDerivedEvent(dirname($receipt),['state'=>'COMMIT','artifact_sha256'=>$expected,'derived_rows'=>$derivedResult['derived_rows']]);$outFile=dirname($receipt).'/derived-applied-private.json';if(file_exists($outFile)){accountPrivate($outFile);if((json_decode(file_get_contents($outFile),true,512,JSON_THROW_ON_ERROR)['artifact_sha256']??null)!==$expected)throw new RuntimeException('UPGRADE_DERIVED_AUDIT_CONFLICT');}else historyPrivateJson($outFile,$derivedResult+['artifact_sha256'=>$expected]);
    echo json_encode($result+['derived_balances_applied'=>true]+['requires_post_upgrade_checks'=>true,'write_fence_release_authorized'=>false],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){echo json_encode(['upgrade_status'=>'BLOCKED','reason'=>preg_match('/^UPGRADE_[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'UPGRADE_PRIVATE_FAILURE','automatic_database_restore'=>false,'retain_write_fence'=>true]).PHP_EOL;$exitCode=78;}
finally{\Sulb\Release\ProductionUpgradeContext::end();}
exit($exitCode);
