<?php
/** Explicit pre-backup recovery. Never unlocks accounts, kills clients, or executes DDL. */
require_once __DIR__.'/release-backup.php';
require_once __DIR__.'/production-schema-gate.php';

const SULB_RECOVERY_FROM_COMMIT = '90058f64b48452577dfa89f2afb19b8555e8178d';
const SULB_RECOVERY_DIRECTORY = '/home/mgye-api-sulb/sulb-release-backup.a744a4784d95555a';
const SULB_RECOVERY_FORBIDDEN = [
    'snapshot.enc', 'manifest-private.json', 'backup-metadata.json',
    'source-inventory.json', 'backend.assets.enc', 'frontend.assets.enc',
    'backup-verification.json', 'upgrade-authorization.json',
    'ordered-upgrade-events.jsonl', 'migration-summary.json',
    'application-copy-started', 'postcheck-private.json', 'release-summary.json',
    'operational-readiness-private.json', 'activation-summary.json',
];

function recoveryAssertPreBackupNames(array $names): void {
    foreach ($names as $name) {
        foreach (SULB_RECOVERY_FORBIDDEN as $blocked) {
            if ($name === $blocked || str_starts_with($name, $blocked.'.')) {
                throw new RuntimeException('RECOVERY_BACKUP_OR_UPGRADE_ALREADY_STARTED');
            }
        }
    }
}

function recoveryAssertPermit(array $permit, string $head, array $entries): void {
    if (($permit['deployment_authorized'] ?? null) !== true
        || ($permit['database'] ?? null) !== 'sulbprod'
        || ($permit['approved_commit'] ?? null) !== $head
        || ($permit['allowed_entry_sha256'] ?? null) !== $entries
        || !is_int($permit['created_unix'] ?? null)
        || $permit['created_unix'] > time()) {
        throw new RuntimeException('RECOVERY_PRIOR_PERMIT_IDENTITY_MISMATCH');
    }
}

function recoveryGit(array $args): string {
    $pipes=[];
    $p=proc_open(array_merge(['git','-C','/home/sulb-release'], $args),
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('RECOVERY_GIT_REQUIRED');
    $out=stream_get_contents($pipes[1],4194305);
    fclose($pipes[1]);
    if (proc_close($p)!==0 || strlen($out)>4194304) throw new RuntimeException('RECOVERY_GIT_FAILED');
    return $out;
}

function recoveryPrivateJson(string $path): array {
    accountPrivate($path);
    if (filesize($path)>1048576 || lstat($path)['nlink']!==1) throw new RuntimeException('RECOVERY_PRIVATE_FILE_REQUIRED');
    return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
}

function recoveryEntries(string $commit): array {
    $entries=[];
    foreach (['production-upgrade.php','release-backup.php','release-postcheck.php','release-fence.php'] as $name) {
        $path='ops/sulb-rc/'.$name;
        $entries['/home/sulb-release/'.$path]=hash('sha256',recoveryGit(['show',$commit.':'.$path]));
    }
    return $entries;
}

function recoveryInspect(string $backup, array $release, string $head): array {
    if ($backup!==SULB_RECOVERY_DIRECTORY) throw new RuntimeException('RECOVERY_EXACT_ATTEMPT_REQUIRED');
    releaseBackupPath($backup);
    $marker='/run/sulb-release.fence';
    if (realpath($marker)!==$marker || is_link($marker) || fileowner($marker)!==0 || (fileperms($marker)&0077)!==0) {
        throw new RuntimeException('RECOVERY_ROOT_FENCE_REQUIRED');
    }
    if (trim(recoveryGit(['status','--porcelain']))!=='') throw new RuntimeException('RECOVERY_CLEAN_COMMIT_REQUIRED');
    recoveryGit(['merge-base','--is-ancestor',SULB_RECOVERY_FROM_COMMIT,$head]);
    $allowed=['ops/sulb-rc/release-prebackup-recovery.php','ops/sulb-rc/test-prebackup-recovery.php',
        'ops/sulb-rc/deploy-reviewed-release.sh','ops/sulb-rc/test-deployment-orchestration.py',
        'ops/sulb-rc/release.json','ops/sulb-rc/release-qualification.php','.github/workflows/sulb-production-upgrade-qa.yml',
        'docs/erp-baseline/release-readiness-20261024.md'];
    $changes=array_filter(explode("\0", recoveryGit(['diff','--name-only','-z',SULB_RECOVERY_FROM_COMMIT,$head])));
    if (array_diff($changes,$allowed)) throw new RuntimeException('RECOVERY_UNREVIEWED_SOURCE_CHANGE');
    recoveryAssertPreBackupNames(scandir($backup));
    // Also reject a partially created encryption key, not only snapshot.enc.
    $key='/home/mgye-api-sulb/sulb-history-keys/a744a4784d95555a.key';
    if (file_exists($key) || is_link($key)) throw new RuntimeException('RECOVERY_BACKUP_KEY_ALREADY_CREATED');
    foreach (glob('/home/mgye-api-sulb/sulb-release-backup.*',GLOB_ONLYDIR) as $other) {
        if ($other===$backup) continue;
        foreach (['fence-summary.json','ordered-upgrade-events.jsonl','application-copy-started'] as $name) {
            if (file_exists($other.'/'.$name) && !is_file($other.'/release-summary.json')) {
                throw new RuntimeException('RECOVERY_OTHER_INCOMPLETE_ATTEMPT');
            }
        }
    }
    $down='/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/storage/framework/down';
    if (is_link($down) || realpath($down)!==$down || !is_file($down)) throw new RuntimeException('RECOVERY_MAINTENANCE_REQUIRED');
    $permit=recoveryPrivateJson($marker.'/permit.json');
    $prior=recoveryPrivateJson($marker.'/processes.json');
    $audit=recoveryPrivateJson($backup.'/account-lock-audit.json');
    $states=recoveryPrivateJson($backup.'/worker-status-private.json');
    if (($permit['approved_commit']??null)!==SULB_RECOVERY_FROM_COMMIT) {
        // A previous successful renewal must itself be bound to this exact attempt and commit.
        $record=recoveryPrivateJson($marker.'/prebackup-recovery.json');
        if (($record['approved_commit']??null)!==$head || ($record['backup_directory']??null)!==$backup
            || ($record['original_commit']??null)!==SULB_RECOVERY_FROM_COMMIT
            || ($record['permit_sha256']??null)!==hash_file('sha256',$marker.'/permit.json')) {
            throw new RuntimeException('RECOVERY_RENEWAL_BINDING_REQUIRED');
        }
        recoveryAssertPermit($permit,$head,recoveryEntries($head));
    } else {
        recoveryAssertPermit($permit,SULB_RECOVERY_FROM_COMMIT,recoveryEntries(SULB_RECOVERY_FROM_COMMIT));
    }
    $original=$audit['original']??null;
    $plan=$release['write_fence_plan'];
    if (($audit['format']??null)!=='RELEASE_ACCOUNT_LOCK_ORIGINAL_V1' || ($audit['database']??null)!=='sulbprod') throw new RuntimeException('RECOVERY_ACCOUNT_AUDIT_MISMATCH');
    if (!is_array($original) || count($original)!==count($plan['writer_accounts'])
        || array_column($original,'principal')!==$plan['writer_accounts']) throw new RuntimeException('RECOVERY_ACCOUNT_AUDIT_MISMATCH');
    foreach ($original as $row) {
        if (($row['locked']??null)!==false || ($row['global_write_or_admin']??null)!==false
            || ($row['can_write_sulbprod']??null)!==true) throw new RuntimeException('RECOVERY_ACCOUNT_AUDIT_MISMATCH');
    }
    if (array_keys($states)!==($plan['writer_units']??[])) throw new RuntimeException('RECOVERY_WORKER_AUDIT_MISMATCH');
    foreach ($states as $unit=>$active) {
        if (!is_bool($active) || releaseService($unit,'ActiveState')!=='inactive' || releaseService($unit,'MainPID')!=='0') {
            throw new RuntimeException('RECOVERY_WORKER_NOT_STOPPED');
        }
    }
    if (releaseService('sulb-frontend.service','ActiveState')!=='inactive' || releaseService('sulb-frontend.service','MainPID')!=='0') {
        throw new RuntimeException('RECOVERY_FRONTEND_NOT_STOPPED');
    }
    // These are current queries, not an extension of any stale receipt.
    $admin=releaseAdminConnection();
    verifyReleaseFence($admin,$release);
    $current=releaseProcessSnapshot($release,true);
    releaseAssertProcessDrain($prior,$current,'releaseProcess');
    if ($current['workers'] || $current['cli_writers']) throw new RuntimeException('RECOVERY_SITE_WRITERS_NOT_DRAINED');
    verifyProductionSchemaFingerprint($admin,$release['expected_production_schema_sha256']);
    $names=array_column(\Sulb\Release\ProductionUpgradeExecutor::plan(dirname(__DIR__,2)),'repository_name');
    $stmt=$admin->prepare('SELECT COUNT(*) FROM sulbprod.migrations WHERE migration IN ('.implode(',',array_fill(0,count($names),'?')).')');
    $stmt->execute($names);
    if ((int)$stmt->fetchColumn()!==0) throw new RuntimeException('RECOVERY_MIGRATIONS_ALREADY_STARTED');
    verifyReleaseFence($admin,$release);
    return ['processes'=>$current,'previous_permit_sha256'=>hash_file('sha256',$marker.'/permit.json'),
        'previous_processes_sha256'=>hash_file('sha256',$marker.'/processes.json')];
}

function recoveryCli(array $argv): int {
    ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
    try {
        if (count($argv)!==3 || !in_array($argv[1],['--check','--renew'],true)
            || PHP_OS_FAMILY!=='Linux' || posix_geteuid()!==0 || getcwd()!=='/home/sulb-release'
            || !posix_setrlimit(POSIX_RLIMIT_CORE,0,0)) throw new RuntimeException('RECOVERY_EXPLICIT_ROOT_CONTEXT_REQUIRED');
        $lock=fopen('/run/sulb-release.recovery.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('RECOVERY_ALREADY_RUNNING');
        $head=trim(recoveryGit(['rev-parse','HEAD']));
        if (getenv('SULB_APPROVED_COMMIT')!==$head) throw new RuntimeException('RECOVERY_APPROVED_COMMIT_REQUIRED');
        require_once __DIR__.'/release-qualification.php';
        $r=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);
        verifyReleaseQualificationBindings($r,dirname(__DIR__,2));
        verifyPrivateSiteRuntimeEvidence($r);
        $r=releaseWithReviewedFencePlan($r);
        $proof=recoveryInspect($argv[2],$r,$head);
        if ($argv[1]==='--check') {
            echo json_encode(['prebackup_recovery'=>'CHECKED','live_fence'=>'PASS','production_rows_written'=>false,'fence_released'=>false]).PHP_EOL;
            return 0;
        }
        if (($r['prebackup_recovery']['authorized']??false)!==true
            || ($r['prebackup_recovery']['from_commit']??null)!==SULB_RECOVERY_FROM_COMMIT
            || ($r['prebackup_recovery']['backup_directory']??null)!==$argv[2]) throw new RuntimeException('RECOVERY_OWNER_AUTHORIZATION_REQUIRED');
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/check-approval.php'),$gate);
        if ($gate!==0) return 78;
        // Only the serialized unified executor may renew the live permit.
        if (readlink('/proc/self/fd/9')!=='/run/sulb-release.execution.lock') throw new RuntimeException('RECOVERY_EXECUTOR_LOCK_REQUIRED');
        $executorLock=fopen('php://fd/9','r+');
        if (!$executorLock || !flock($executorLock,LOCK_EX|LOCK_NB)) throw new RuntimeException('RECOVERY_EXECUTOR_LOCK_REQUIRED');
        $marker='/run/sulb-release.fence';
        $audit=$argv[2].'/prebackup-renewal-'.bin2hex(random_bytes(8));
        if (!mkdir($audit,0700)) throw new RuntimeException('RECOVERY_AUDIT_CREATE_FAILED');
        foreach (['permit.json','processes.json','prebackup-recovery.json'] as $name) {
            if (!file_exists($marker.'/'.$name)) continue;
            accountPrivate($marker.'/'.$name);
            if (!copy($marker.'/'.$name,$audit.'/'.$name) || !chmod($audit.'/'.$name,0600)
                || hash_file('sha256',$marker.'/'.$name)!==hash_file('sha256',$audit.'/'.$name)) throw new RuntimeException('RECOVERY_AUDIT_RETAIN_FAILED');
        }
        foreach (['backup-summary.json','candidate-backend-build-private.log','candidate-frontend-build-private.log'] as $name) {
            if (!file_exists($argv[2].'/'.$name)) continue;
            accountPrivate($argv[2].'/'.$name);
            if (!copy($argv[2].'/'.$name,$audit.'/'.$name) || !chmod($audit.'/'.$name,0600)
                || hash_file('sha256',$argv[2].'/'.$name)!==hash_file('sha256',$audit.'/'.$name)) throw new RuntimeException('RECOVERY_AUDIT_RETAIN_FAILED');
        }
        // Check again immediately before publication. No service or account mutations.
        $proof=recoveryInspect($argv[2],$r,$head);
        $permit=['deployment_authorized'=>true,'database'=>'sulbprod','created_unix'=>time(),
            'approved_commit'=>$head,'allowed_entry_sha256'=>recoveryEntries($head)];
        historyPrivateJson($audit.'/renewed-permit.json',$permit);
        historyPrivateJson($audit.'/renewed-processes.json',$proof['processes']+['created_unix'=>time()]);
        historyPrivateJson($audit.'/renewal.json',['approved_commit'=>$head,'original_commit'=>SULB_RECOVERY_FROM_COMMIT,
            'backup_directory'=>$argv[2],'permit_sha256'=>hash_file('sha256',$audit.'/renewed-permit.json'),
            'previous_permit_sha256'=>$proof['previous_permit_sha256'],'previous_processes_sha256'=>$proof['previous_processes_sha256'],
            'retained_evidence_directory'=>$audit,'live_checks_completed_at_utc'=>gmdate('c')]);
        // Atomic replacements; a partial failure is fail-closed and never unlocks.
        foreach (['renewed-processes.json'=>'processes.json','renewal.json'=>'prebackup-recovery.json','renewed-permit.json'=>'permit.json'] as $from=>$to) {
            $temporary=$marker.'/.recovery-'.bin2hex(random_bytes(8));
            if (!copy($audit.'/'.$from,$temporary) || !chmod($temporary,0600) || !rename($temporary,$marker.'/'.$to)) throw new RuntimeException('RECOVERY_ATOMIC_PUBLICATION_FAILED');
        }
        verifyReleaseProcessFence($r);
        echo json_encode(['prebackup_recovery'=>'RENEWED','live_fence'=>'PASS','previous_evidence_preserved'=>true,
            'approved_commit'=>$head,'production_rows_written'=>false,'fence_released'=>false]).PHP_EOL;
        return 0;
    } catch (Throwable $e) {
        $reason=preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'RECOVERY_PRIVATE_CHECK_FAILED';
        echo json_encode(['prebackup_recovery'=>'BLOCKED','reason'=>$reason,'retain_write_fence'=>true]).PHP_EOL;
        return 78;
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) exit(recoveryCli($argv));

