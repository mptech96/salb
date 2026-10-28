<?php
/** One ordered executor entry, never a general artisan migrate/down wrapper. */
require_once __DIR__.'/production-upgrade-runtime.php';
define('SULB_UPGRADE_ROOT',dirname(__DIR__,2));
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
$exitCode=0;
try {
    $mode=$argv[1]??'--plan';if(count($argv)!==2||!in_array($mode,['--plan','--execute'],true))throw new RuntimeException('UPGRADE_EXPLICIT_COMMAND_REQUIRED');
    $plan=\Sulb\Release\ProductionUpgradeExecutor::plan(SULB_UPGRADE_ROOT);
    if($mode==='--plan'){echo json_encode(['mode'=>'ORDERED_PRODUCTION_PLAN','migration_count'=>count($plan),'schedule'=>$plan,'original_qa_sources_untouched'=>true,'database_connections'=>0,'deployment_authorized'=>false],JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
    // Authorization happens before Laravel/.env/PDO. Never weaken this ordering.
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/check-approval.php'),$approval);if($approval!==0)exit(78);
    if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||getcwd()!=='/home/sulb-release'||SULB_UPGRADE_ROOT!=='/home/sulb-release'||!posix_setrlimit(POSIX_RLIMIT_CORE,0,0))throw new RuntimeException('UPGRADE_APPROVED_LINUX_CHECKOUT_REQUIRED');
    $receipt=getenv('SULB_UPGRADE_AUTHORIZATION_FILE')?:'';
    if(!preg_match('#^/home/mgye-api-sulb/sulb-release-backup\.[A-Za-z0-9]+/upgrade-authorization\.json$#D',$receipt)||is_link($receipt)||!is_file($receipt)||realpath($receipt)!==$receipt||fileowner($receipt)!==0||(fileperms($receipt)&0077)!==0||filesize($receipt)>65536)throw new RuntimeException('UPGRADE_PRIVATE_RECEIPT_REQUIRED');
    $proof=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);
    require '/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/vendor/autoload.php';$app=require '/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['logging.default'=>'null']);
    \Sulb\Release\ProductionUpgradeContext::begin('production',$proof,$receipt);
    $result=\Sulb\Release\ProductionUpgradeExecutor::run(SULB_UPGRADE_ROOT,dirname($receipt).'/ordered-upgrade-events.jsonl');
    echo json_encode($result+['requires_post_upgrade_checks'=>true,'write_fence_release_authorized'=>false],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){echo json_encode(['upgrade_status'=>'BLOCKED','reason'=>preg_match('/^UPGRADE_[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'UPGRADE_PRIVATE_FAILURE','automatic_database_restore'=>false,'retain_write_fence'=>true]).PHP_EOL;$exitCode=78;}
finally{\Sulb\Release\ProductionUpgradeContext::end();}
exit($exitCode);
