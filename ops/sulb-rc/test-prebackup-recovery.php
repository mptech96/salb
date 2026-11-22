<?php
require_once __DIR__.'/release-prebackup-recovery.php';
$count=0;
function recoveryTest(bool $ok): void {global $count;if(!$ok)throw new RuntimeException('RECOVERY_TEST_FAILED_'.($count+1));$count++;}
function recoveryReject(callable $call,string $reason): void {try{$call();recoveryTest(false);}catch(RuntimeException $e){recoveryTest($e->getMessage()===$reason);}}
$head=str_repeat('a',40);
$entries=['/home/sulb-release/ops/sulb-rc/release-backup.php'=>str_repeat('b',64)];
$v=['deployment_authorized'=>true,'database'=>'sulbprod','approved_commit'=>$head,
    'allowed_entry_sha256'=>$entries,'created_unix'=>time()-86400];
recoveryAssertPermit($v,$head,$entries);recoveryTest(true);
foreach (['deployment_authorized'=>false,'database'=>'another_db','approved_commit'=>str_repeat('c',40),
    'allowed_entry_sha256'=>[],'created_unix'=>time()+3600] as $key=>$bad) {
    $copy=$v;$copy[$key]=$bad;
    recoveryReject(fn()=>recoveryAssertPermit($copy,$head,$entries),'RECOVERY_PRIOR_PERMIT_IDENTITY_MISMATCH');
}
recoveryAssertPreBackupNames(['.','..','fence-summary.json','backup-summary.json','processes-before-refresh-abc.json']);recoveryTest(true);
foreach (SULB_RECOVERY_FORBIDDEN as $name) {
    recoveryReject(fn()=>recoveryAssertPreBackupNames([$name]),'RECOVERY_BACKUP_OR_UPGRADE_ALREADY_STARTED');
    recoveryReject(fn()=>recoveryAssertPreBackupNames([$name.'.partial']),'RECOVERY_BACKUP_OR_UPGRADE_ALREADY_STARTED');
}
$prior=['site_uid'=>1004,'php_fpm_unit'=>'php8.5-fpm.service','workers'=>[['pid'=>45,'start_ticks'=>'123']],'cli_writers'=>[]];
$current=['site_uid'=>1004,'php_fpm_unit'=>'php8.5-fpm.service','workers'=>[],'cli_writers'=>[]];
recoveryReject(fn()=>releaseAssertProcessDrain($prior,$current,fn($pid)=>['start_ticks'=>'123']),'RELEASE_OLD_PHP_WRITER_STILL_ALIVE');
recoveryTest(releaseAssertProcessDrain($prior,$current,fn($pid)=>null)['old_application_processes_drained']===true);
$current['cli_writers']=[['pid'=>66,'start_ticks'=>'4']];
recoveryReject(fn()=>releaseAssertProcessDrain($prior,$current,fn($pid)=>null),'RELEASE_UNREGISTERED_LIVE_CLI_WRITER');
echo "PASS $count pre-backup recovery policy checks; production_connections=0\n";

