<?php
// Default/status: no application bootstrap or database access. Explicit --readiness reads private evidence and schema metadata only.
$release=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);
require_once __DIR__.'/release-qualification.php';
try {
    verifyReleaseQualificationBindings($release,dirname(__DIR__,2));
    if (($argv[1]??'')==='--readiness') {
        ini_set('display_errors','0');ini_set('log_errors','0');
        $status=inspectReleaseReadiness($release,dirname(__DIR__,2));
        echo json_encode($status,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
        exit($status['qualification_status']==='BLOCKED'?78:0);
    }
    if (($argv[1]??'')==='--status') {
        echo json_encode(['qualification_bindings'=>'PASS','pending_gates'=>releasePendingGates($release),
            'release_decision'=>$release['release_decision'],'database_connections'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
        exit($release['release_decision']==='GO'&&!releasePendingGates($release)?0:78);
    }
} catch (Throwable $error) {
    $reason=preg_match('/^[A-Z0-9_]+$/D',$error->getMessage())?$error->getMessage():'RELEASE_QUALIFICATION_CHECK_FAILED';
    fwrite(STDERR,'NO-GO: '.$reason.PHP_EOL);exit(78);
}
$missing=releasePendingGates($release);
if(($release['release_decision']??'')!=='GO'||$missing||!preg_match('/^[a-f0-9]{64}$/D',$release['expected_production_schema_sha256']??'')||!count($release['approved_migration_paths']??[])){
    fwrite(STDERR,"NO-GO: release approval, exact schema evidence, staging and ordered production executor are not certified.\n");exit(78);
}
require_once __DIR__.'/rollback-policy.php';reviewedRollbackPolicy($release);
require_once __DIR__.'/production-upgrade-runtime.php';
$plan=\Sulb\Release\ProductionUpgradeExecutor::plan(dirname(__DIR__,2));
if($release['approved_migration_paths']!==array_column($plan,'source_path'))throw new RuntimeException('EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
if(($argv[1]??'')==='--migrations')foreach($release['approved_migration_paths'] as $path)echo $path.PHP_EOL;
