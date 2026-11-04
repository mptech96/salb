<?php
/** Uses the existing private packet only. No DB, application bootstrap, network or service operations. */
require_once __DIR__.'/writer-window-policy.php';

function buildWriterWindowCandidate(array $profile, array $facts, array $pending, array $pool, array $poolReference): array {
    writerWindowAssert(($pending['approved'] ?? null) === false, 'WRITER_PENDING_DECISIONS_REQUIRED');
    writerWindowAssert(writerSignoffText($pending['reviewer'] ?? null)
        && in_array($pending['role'] ?? null, ['AUTHORIZED_TECHNICAL_OWNER','AUTHORIZED_DATABASE_ADMIN'], true),
        'WRITER_AUTHORIZED_DECISION_REQUIRED');
    writerSignoffIdentity($pending, $pending['signed_document_sha256'] ?? '');
    $identity = array_intersect_key($pending, array_flip(['reviewer','review_reference','attested_at_utc','signed_document_sha256']));
    $sourceAccounts = writerSignoffSet($profile['database_accounts'] ?? [], 'principal');
    $factsAccounts = writerSignoffSet($facts['accounts'] ?? [], 'principal');
    $decisions = writerSignoffSet($pending['accounts'] ?? [], 'principal');
    writerWindowAssert(array_keys($sourceAccounts) === array_keys($decisions)
        && array_keys($sourceAccounts) === array_keys($factsAccounts), 'WRITER_ALL_ACCOUNT_DECISIONS_REQUIRED');
    $window = $pending;
    $window['qualification_mode'] = WRITER_WINDOW_MODE;
    $window['qualification_scope'] = 'MAINTENANCE_EXCLUSION_ONLY';
    $window['production_changes_authorized'] = false;
    $window['deployment_authorized'] = false;
    $window['window_commitments'] = array_fill_keys(writerWindowCommitments(), true);
    $window['runtime_limitations'] = ['external_writer_absence_proven'=>false,
        'all_runtime_connections_authenticated'=>false, 'scheduler_launcher_proven'=>false];
    $window['proof_resolutions'] = [];
    $window['retained_unproved_categories'] = array_column($facts['missing_proofs'], 'code');
    $window['window_evidence'] = ['pool_binding'=>$poolReference];
    $window['accounts'] = []; $writers = []; $admins = []; $count = 0;
    foreach ($profile['database_accounts'] as $account) {
        $principal = $account['principal'];
        $row = $decisions[$principal]; writerSignoffIdentity($row, $identity['signed_document_sha256']);
        foreach ($identity as $key=>$value) writerWindowAssert(($row[$key] ?? null) === $value, 'WRITER_WINDOW_ACCOUNT_IDENTITY_MISMATCH');
        // Do not manufacture functional ownership or authenticated consumers for a disabled account.
        $row['maintenance_responsible_owner'] = $identity['reviewer'];
        $row['decision'] = $account['locked'] ? 'KEEP_LOCKED_EXCLUDED' : (!$account['can_write_sulbprod']
            ? 'CONFIRM_READ_ONLY' : ($account['global_write_or_admin'] ? 'EXCLUSIVE_ADMIN_CHANNEL' : 'EXCLUDE_DURING_WINDOW'));
        if ($row['decision'] === 'EXCLUDE_DURING_WINDOW') $writers[] = $principal;
        if ($row['decision'] === 'EXCLUSIVE_ADMIN_CHANNEL') $admins[] = $principal;
        $row['binding_basis'] = $row['decision'] === 'EXCLUSIVE_ADMIN_CHANNEL'
            ? 'SIGNED_EXCLUSIVE_ADMIN_OPERATOR' : 'CAPTURED_ACCOUNT_INVENTORY';
        // These are maintenance decisions. Prior permanent justifications, including nulls, remain unasserted.
        $caps = writerSignoffSet($row['capabilities'] ?? [], 'capability');
        $captured = array_fill_keys($account['capability_evidence'], true); ksort($captured);
        writerWindowAssert(array_keys($caps) === array_keys($captured), 'WRITER_APPROVED_CAPABILITIES_CHANGED');
        foreach ($row['capabilities'] as &$capability) {
            $capability['decision'] = null;
            $capability['permanent_audit_status'] = 'DEFERRED_NOT_APPROVED';
            if (!$account['locked'] && $account['can_write_sulbprod']) $count++;
        } unset($capability);
        $window['accounts'][] = $row;
    }
    $window['permanent_capability_audit'] = ['status'=>'OPEN_NOT_CERTIFIED',
        'responsible_owner'=>$identity['reviewer'],
        'tracking_reference'=>$identity['review_reference'].'/PERMANENT-CAPABILITY-AUDIT',
        'active_writer_capability_count'=>$count];
    // Only captured pool configuration is supported by this first, deliberately narrow window mode.
    $window['services'] = [];
    foreach ($facts['service_candidates'] as $service) {
        $window['services'][] = $identity + ['path'=>$service['path'], 'source_sha256'=>$service['sha256'],
            'owner'=>$identity['reviewer'], 'classification'=>'PHP_FPM_POOL_CONFIGURATION',
            'decision'=>'REVIEW_CONFIGURATION_FOR_WINDOW', 'account_principals'=>[],
            'candidate_principals'=>$service['candidate_principals'] ?? [],
            'mysql_authentication_proven'=>false, 'scheduler_launcher_proven'=>false];
    }
    $reviewed = $profile;
    $reviewed['review']['qualification_mode'] = WRITER_WINDOW_MODE;
    $reviewed['review']['reviewer'] = $identity['reviewer'];
    $reviewed['review']['reference'] = $identity['review_reference'];
    $reviewed['review']['window_review_complete'] = false;
    $reviewed['review']['external_writers_and_schedules_reviewed'] = false;
    $reviewed['plan']['writer_accounts'] = $writers;
    $reviewed['plan']['trusted_privileged_accounts'] = $admins;
    $originalAdmins=$profile['plan']['trusted_privileged_accounts']??[];
    $selectedAdmins=$admins;sort($originalAdmins);sort($selectedAdmins);
    writerWindowAssert(count($admins)===1 && $originalAdmins===$selectedAdmins,
        'WRITER_WINDOW_ADMIN_NOT_IN_ORIGINAL_PLAN');
    $reviewed['external_writer_source_files'] ??= [];
    $known = [];
    foreach (array_merge($reviewed['surface_files'], $reviewed['external_writer_source_files']) as $row) $known[$row['path']]=$row['sha256'];
    foreach ($pool['config_hashes'] ?? [] as $path=>$sha) {
        if (isset($known[$path])) writerWindowAssert($known[$path] === $sha, 'WRITER_WINDOW_POOL_SOURCE_CHANGED');
        else $reviewed['external_writer_source_files'][] = ['path'=>$path,'sha256'=>$sha];
    }
    // An unobserved FPM worker in ondemand is not a reason to fabricate its runtime principal.
    $services = $facts['service_candidates'];
    writerWindowAssert(count($services)>0 && preg_match('#^/etc/php/([0-9]+\.[0-9]+)/fpm/pool\.d/#', $services[0]['path'], $version)===1,
        'WRITER_WINDOW_UNSUPPORTED_SERVICE_REQUIRES_REVIEW');
    $unit='php'.$version[1].'-fpm.service';
    writerWindowAssert(($reviewed['plan']['php_fpm_unit'] ?? null) === null || $reviewed['plan']['php_fpm_unit'] === $unit,
        'WRITER_WINDOW_POOL_UNIT_MISMATCH');
    $reviewed['plan']['php_fpm_unit']=$unit;
    return [$reviewed,$window];
}

function writerWindowPrepareCli(array $argv): int {
    $stage='VALIDATE_CONTEXT';$privateFile=null;
    try {
        writerWindowAssert(PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0 && getcwd() === '/home/sulb-release',
            'WRITER_WINDOW_ROOT_CHECKOUT_REQUIRED');
        writerWindowAssert(count($argv) === 7 && $argv[1] === '--prepare-window', 'WRITER_WINDOW_PREPARE_ARGUMENTS_REQUIRED');
        [$script,$mode,$dir,$source,$sourceSha,$poolName,$poolSha] = $argv;
        writerWindowAssert(preg_match('#^/home/mgye-api-sulb/sulb-writer-signoff-[a-f0-9]{16}$#D',$dir)===1
            && realpath($dir)===$dir && fileowner($dir)===0 && (fileperms($dir)&0077)===0,
            'WRITER_PRIVATE_SIGNOFF_PACKET_REQUIRED');
        writerWindowAssert(preg_match('#^/home/mgye-api-sulb/sulb-release-review-[a-f0-9]{16}/profile-private\.json$#D',$source)===1
            && realpath($source)===$source && fileowner(dirname($source))===0 && (fileperms(dirname($source))&0077)===0,
            'WRITER_PRIVATE_SOURCE_REQUIRED');
        $read = function(string $file) use (&$stage,&$privateFile): array {
            $stage='READ_'.strtoupper(str_replace(['.','-'],['_','_'],basename($file)));$privateFile=$file;
            accountPrivate($file);
            writerWindowAssert(filesize($file)<=1048576,'WRITER_SIGNOFF_SIZE_LIMIT');
            return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        };
        $profile=$read($source); $facts=$read($dir.'/evidence-private.json');
        $pending=$read($dir.'/decisions-reviewed-pending.json'); $receipt=$read($dir.'/signature-receipt-private.json');
        writerWindowAssert(writerSignoffSha($sourceSha) && hash_file('sha256',$source)===$sourceSha
            && ($facts['source_profile_sha256']??null)===$sourceSha
            && ($pending['source_profile_sha256']??null)===$sourceSha
            && ($pending['evidence_sha256']??null)===hash_file('sha256',$dir.'/evidence-private.json'),
            'WRITER_SIGNOFF_EVIDENCE_BINDING');
        $pdf=$dir.'/signed-decision.pdf';$stage='CHECK_SIGNED_DOCUMENT';$privateFile=$pdf;accountPrivate($pdf);
        writerWindowAssert(filesize($pdf)<=16777216 && hash_file('sha256',$pdf)===($pending['signed_document_sha256']??null)
            && ($receipt['signed_document_sha256']??null)===$pending['signed_document_sha256']
            && ($receipt['review_reference']??null)===$pending['review_reference']
            && ($receipt['owner_reaffirmation_recorded_at_utc']??null)===$pending['attested_at_utc'],
            'WRITER_WINDOW_SIGNED_RECEIPT_REQUIRED');
        // The original profile/facts and existing PDF remain untouched.
        writerWindowAssert(($profile['writer_linkage']['missing_proofs']??null)===array_column($facts['missing_proofs'],'code')
            && ($profile['writer_linkage']['service_schedule_candidates']??null)===$facts['service_candidates'],
            'WRITER_SOURCE_FACTS_NOT_PRESERVED');
        $reference=['file'=>$poolName,'sha256'=>$poolSha];
        $stage='LOAD_POOL_EVIDENCE';$privateFile=$dir.'/'.$poolName;
        $evidence=loadWriterWindowEvidence(['window_evidence'=>['pool_binding'=>$reference]],$dir);
        [$reviewed,$candidate]=buildWriterWindowCandidate($profile,$facts,$pending,$evidence['pool_binding'],$reference);
        // Eligibility probe only: no approved=true is persisted and no reviewed profile is activated.
        $probe=$candidate; $probe['approved']=true;
        validateWriterSignoff($reviewed,$facts,$probe,hash_file('sha256',$dir.'/evidence-private.json'),$sourceSha,[],$evidence);
        $candidateBytes=json_encode($candidate,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        $reviewed['writer_signoff']=['packet_directory'=>$dir,'source_profile_file'=>$source,
            'evidence_sha256'=>hash_file('sha256',$dir.'/evidence-private.json'),
            'decision_sha256'=>hash('sha256',$candidateBytes)];
        foreach (['decisions-window-pending.json'=>$candidate,'profile-window-pending.json'=>$reviewed] as $name=>$value) {
            $file=$dir.'/'.$name; $bytes=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            $stage=$name==='decisions-window-pending.json'?'SAVE_DECISIONS_CANDIDATE':'SAVE_PROFILE_CANDIDATE';$privateFile=$file;
            clearstatcache(true,$file);
            if (file_exists($file) || is_link($file)) {
                accountPrivate($file);
                writerWindowAssert(file_get_contents($file)===$bytes,'WRITER_WINDOW_EXISTING_CANDIDATE_DIFFERS');
                continue;
            }
            $old=umask(0077); $h=fopen($file,'xb'); umask($old);
            writerWindowAssert(is_resource($h),'WRITER_WINDOW_CANDIDATE_CREATE_FAILED');
            if(!chmod($file,0600)){fclose($h);throw new RuntimeException('WRITER_WINDOW_CANDIDATE_PERMISSIONS_FAILED');}
            $written=fwrite($h,$bytes); $synced=fflush($h)&&fsync($h); fclose($h);
            writerWindowAssert($written===strlen($bytes)&&$synced,'WRITER_WINDOW_CANDIDATE_WRITE_FAILED');
        }
        echo json_encode(['window_candidate_policy'=>'PASS','writer_signoff_guard'=>'BLOCKED_PENDING_WINDOW_REVIEW',
            'approved'=>false,'accounts'=>count($candidate['accounts']),'services'=>count($candidate['services']),
            'deferred_active_capabilities'=>$candidate['permanent_capability_audit']['active_writer_capability_count'],
            'candidate_sha256'=>hash_file('sha256',$dir.'/decisions-window-pending.json'),
            'production_changes'=>false,'database_connections'=>0,'release_decision'=>'NO-GO']).PHP_EOL;
        return 0;
    } catch (Throwable $e) {
        $metadata=null;
        if($e->getMessage()==='PRIVATE_EVIDENCE_REQUIRED'&&is_string($privateFile)){
            clearstatcache(true,$privateFile);
            $metadata=['is_link'=>is_link($privateFile),'is_file'=>is_file($privateFile),
                'uid'=>is_file($privateFile)?fileowner($privateFile):null,
                'mode'=>is_file($privateFile)?sprintf('%04o',fileperms($privateFile)&0777):null];
        }
        echo json_encode(['window_candidate_policy'=>'BLOCKED','reason'=>preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'PRIVATE_WINDOW_CANDIDATE_FAILURE',
            'stage'=>$stage,'private_file_metadata'=>$metadata,'approved'=>false,'production_changes'=>false,'database_connections'=>0,'release_decision'=>'NO-GO']).PHP_EOL;
        return 78;
    }
}
