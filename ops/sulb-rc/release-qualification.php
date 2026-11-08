<?php
/** Qualification of implementation and accepted evidence. Fresh window receipts remain mandatory at execution. */
require_once __DIR__.'/production-upgrade-runtime.php';
require_once __DIR__.'/rollback-policy.php';

function releaseQualificationSources(): array {
    return [
        'staging_passed'=>['approved-history-rehearsal.php','production-upgrade/catalog.json'],
        'production_executor_qualified'=>['production-upgrade-runtime.php','production-upgrade.php','production-schema-gate.php','production-upgrade/catalog.json'],
        'write_fence_qualified'=>['release-fence.php','release-fence-evidence.php','release-process-fence.php','writer-signoff-policy.php','writer-window-policy.php','release-site-runtime.php'],
        'deployment_backup_restore_qualified'=>['release-backup.php','backup-receipt.php','asset-backup.php','history-stage.py','run-private-backup-check.php'],
        'production_derived_balance_executor_qualified'=>['production-derived.php','approved-history-rehearsal.php'],
        'deployment_pipeline_qualified'=>['deploy-reviewed-release.sh','release-app-control.php','release-postcheck.php','release-activate.php','release-site-runtime.php'],
        'rollback_strategy_qualified'=>['rollback-policy.php'],
    ];
}

function releaseQualificationHash(string $root, string $relative): string {
    $file=$root.'/ops/sulb-rc/'.$relative;
    if (is_link($file) || !is_file($file)) throw new RuntimeException('RELEASE_QUALIFICATION_SOURCE_REQUIRED');
    return hash('sha256',str_replace("\r\n","\n",file_get_contents($file)));
}

function releaseSecurityRequiredPaths(): array {
    return [
        'accounting'=>['backend/app/Http/Controllers/Api/JournalEntryController.php','backend/app/Domain/Accounting/Services/JournalService.php','backend/app/Domain/Accounting/Repositories/JournalRepository.php','backend/app/Domain/Accounting/Services/FinancialYearService.php','backend/app/Http/Controllers/Api/FinancialYearController.php','backend/app/Http/Controllers/Api/AccountingReportController.php'],
        'payroll'=>['backend/app/Http/Controllers/Api/PayrollController.php','backend/app/Http/Controllers/Api/WorkerController.php','backend/app/Services/Payroll/WorkerAccountingSupport.php','backend/app/Services/Payroll/PayrollApprover.php','backend/app/Services/Payroll/PayrollPayment.php','backend/app/Services/Payroll/PayrollService.php'],
        'drivers'=>['backend/app/Http/Controllers/Api/DriverController.php','backend/app/Services/DriverShipmentStatement.php','backend/app/Services/DriverSettlementIsolation.php'],
        'l3'=>['backend/app/Http/Controllers/Api/ShipmentCostController.php','backend/app/Services/ShipmentCostService.php','backend/app/Services/ShipmentAllocationLedger.php','backend/app/Services/ShipmentInventoryLockScope.php','backend/app/Services/Uom/BaseLotCapitalization.php','backend/app/Services/Uom/BaseShipmentDistribution.php'],
    ];
}

function verifyReleaseSecurityBindings(array $release,string $root): void {
    if (($release['complete_security_review']??false)!==true) return;
    $review=$release['security_semantic_review']??[];
    if (($review['status']??null)!=='TECHNICAL_REVIEW_COMPLETE' || ($review['unresolved_findings']??null)!==[]
        || !preg_match('/^[a-f0-9]{40}$/D',$review['review_basis_commit']??'')
        || empty($review['review_reference']) || empty($review['accepted_evidence_references'])) {
        throw new RuntimeException('RELEASE_SECURITY_REVIEW_RECORD_REQUIRED');
    }
    $hashes=$review['source_sha256']??[];
    foreach(['backend/routes/api.php','backend/app/Http/Middleware/EnsureRoutePermission.php','backend/app/Http/Middleware/EnsureSupportAccess.php','backend/app/Http/Middleware/EnforceTenantScope.php','backend/app/Http/Middleware/ResolveAuthenticatedContext.php','backend/app/Support/TenantScope.php','backend/app/Services/Tenant/TenantResourceScopeService.php','backend/app/Support/ReleaseWriteFence.php','backend/app/Providers/AppServiceProvider.php']as$path){
        if(!isset($hashes[$path]))throw new RuntimeException('RELEASE_SECURITY_CONTEXT_HASH_REQUIRED');
    }
    foreach(releaseSecurityRequiredPaths()as$domain=>$required){
        $scope=$review['domains'][$domain]??[];
        if(($scope['status']??null)!=='PASS' || !is_array($scope['paths']??null)
            || array_diff($required,$scope['paths']))throw new RuntimeException('RELEASE_SECURITY_SCOPE_INCOMPLETE');
        foreach($scope['paths']as$path)if(!isset($hashes[$path]))throw new RuntimeException('RELEASE_SECURITY_SCOPE_HASH_REQUIRED');
    }
    foreach($hashes as$path=>$hash){
        if(!preg_match('#^backend/(app|routes)/[A-Za-z0-9_./-]+\.php$#D',$path)||str_contains($path,'..')
            || is_link($root.'/'.$path)||!is_file($root.'/'.$path)
            || !hash_equals($hash,hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/'.$path))))) {
            throw new RuntimeException('RELEASE_SECURITY_REVIEW_SOURCE_CHANGED');
        }
    }
    if(($review['writer_source_inventory_sha256']??null)!==releaseQualificationHash($root,'source-writer-scope.json')) {
        throw new RuntimeException('RELEASE_WRITER_SOURCE_INVENTORY_CHANGED');
    }
    $inventory=json_decode(file_get_contents($root.'/ops/sulb-rc/source-writer-scope.json'),true,512,JSON_THROW_ON_ERROR);
    $seen=[];
    foreach($inventory['writers']??[]as$row){
        $path=$row['path']??'';
        if(!preg_match('#^backend/app/[A-Za-z0-9_./-]+\.php$#D',$path)||str_contains($path,'..')||isset($seen[$path])
            ||is_link($root.'/'.$path)||!is_file($root.'/'.$path)
            ||($row['sha256']??null)!==hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/'.$path)))) {
            throw new RuntimeException('RELEASE_WRITER_SOURCE_CHANGED');
        }
        $seen[$path]=true;
    }
    if(count($seen)!==115||($inventory['application_writer_files']??null)!==115)throw new RuntimeException('RELEASE_WRITER_SOURCE_SET_CHANGED');
}

/** Pin an existing private artifact without inventing the unreported cache timestamp. */
function requirePinnedSiteRuntimeDescriptor(array $e,string $policySha,string $profileSha):void {
    if(($e['format']??null)!=='SULB_PINNED_SITE_RUNTIME_EVIDENCE_V1'
        ||!preg_match('#^/home/mgye-api-sulb/sulb-writer-signoff-330e13362e20f1e0/site-runtime-[a-f0-9]{16}\.json$#D',$e['file']??'')
        ||!preg_match('/^[a-f0-9]{64}$/D',$e['sha256']??'')||!preg_match('/^[a-f0-9]{40}$/D',$e['inspection_commit']??'')
        ||($e['policy_source_sha256']??null)!==$policySha||($e['approved_profile_sha256']??null)!==$profileSha
        ||($e['site_runtime']??null)!=='PASS'||($e['shared_service_operations']??null)!==false
        ||($e['production_database_connections']??null)!==0)throw new RuntimeException('RELEASE_EFFECTIVE_SITE_RUNTIME_EVIDENCE_REQUIRED');
    $f=$e['reported_facts']??[];$keys=array_keys($f);sort($keys);
    $expected=['uid','sapi','php_major','php_minor','opcache_enabled','opcache_status_proven','validate_timestamps','revalidate_freq','file_update_protection','preload_configured','file_cache_configured'];sort($expected);
    if($keys!==$expected||!is_int($f['uid'])||$f['uid']<=0||$f['sapi']!=='fpm-fcgi'||$f['php_major']!==8||$f['php_minor']!==5
        ||$f['opcache_enabled']!==true||$f['opcache_status_proven']!==true||$f['validate_timestamps']!==true
        ||!is_int($f['revalidate_freq'])||!is_int($f['file_update_protection'])
        ||min($f['revalidate_freq'],$f['file_update_protection'])<0||max($f['revalidate_freq'],$f['file_update_protection'])>30
        ||$f['preload_configured']!==false||$f['file_cache_configured']!==false)throw new RuntimeException('RELEASE_REPORTED_SITE_RUNTIME_FACTS_REQUIRED');
}
function verifyPinnedSiteRuntimeBytes(string $bytes,array $e):array {
    if(strlen($bytes)>32768||!hash_equals($e['sha256'],$actualHash=hash('sha256',$bytes)))throw new RuntimeException('RELEASE_SITE_RUNTIME_EVIDENCE_CHANGED');
    $a=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    foreach(['site_runtime','inspection_commit','policy_source_sha256','approved_profile_sha256','shared_service_operations','production_database_connections']as$key)
        if(($a[$key]??null)!==($e[$key]??null))throw new RuntimeException('RELEASE_SITE_RUNTIME_PROVENANCE_MISMATCH');
    if(($a['master_signalled']??null)!==false||!is_array($a['facts']??null))throw new RuntimeException('RELEASE_SITE_RUNTIME_PROVENANCE_MISMATCH');
    foreach($e['reported_facts']as$key=>$value)if(($a['facts'][$key]??null)!==$value)throw new RuntimeException('RELEASE_SITE_RUNTIME_FACT_MISMATCH');
    require_once __DIR__.'/release-site-runtime.php';$wait=releaseSiteCachePolicy($a['facts']);
    if(($a['cache_wait_seconds']??null)!==$wait)throw new RuntimeException('RELEASE_SITE_RUNTIME_FACT_MISMATCH');
    return ['site_runtime_evidence'=>'PASS','evidence_sha256'=>$actualHash,'inspection_commit'=>$a['inspection_commit'],'cache_wait_seconds'=>$wait,'database_connections'=>0,'services_changed'=>false];
}
function verifyPrivateSiteRuntimeEvidence(array $release):array {
    if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()!==0||getcwd()!=='/home/sulb-release')throw new RuntimeException('RELEASE_SITE_EVIDENCE_ROOT_CHECKOUT_REQUIRED');
    $e=$release['runtime_execution_corrections']['accepted_evidence']??[];
    requirePinnedSiteRuntimeDescriptor($e,releaseQualificationHash(dirname(__DIR__,2),'release-site-runtime.php'),$release['write_fence_plan']['profile_sha256']??'');
    require_once __DIR__.'/review-account-evidence.php';$file=$e['file'];accountPrivate($file);
    if(realpath($file)!==$file||fileowner(dirname($file))!==0||(fileperms(dirname($file))&0077)!==0||filesize($file)>32768)throw new RuntimeException('RELEASE_SITE_RUNTIME_EVIDENCE_CHANGED');
    return verifyPinnedSiteRuntimeBytes(file_get_contents($file),$e);
}

function verifyReleaseQualificationBindings(array $release, string $root): void {
    verifyReleaseSecurityBindings($release,$root);
    if((($release['write_fence_qualified']??false)===true||($release['deployment_pipeline_qualified']??false)===true)
        &&($release['runtime_execution_corrections']['effective_site_runtime_verified']??false)!==true)throw new RuntimeException('RELEASE_EFFECTIVE_SITE_RUNTIME_EVIDENCE_REQUIRED');
    if(($release['write_fence_qualified']??false)===true||($release['deployment_pipeline_qualified']??false)===true){
        $e=$release['runtime_execution_corrections']['accepted_evidence']??[];
        if(($e['format']??null)==='SULB_PINNED_SITE_RUNTIME_EVIDENCE_V1')requirePinnedSiteRuntimeDescriptor($e,releaseQualificationHash($root,'release-site-runtime.php'),$release['write_fence_plan']['profile_sha256']??'');
        else {require_once __DIR__.'/release-site-runtime.php';requireReleaseSiteRuntimeEvidenceMetadata($e,releaseQualificationHash($root,'release-site-runtime.php'),$release['write_fence_plan']['profile_sha256']??'');}
    }
    $bindings=$release['integrated_qualification']['gate_evidence']??[];
    foreach (releaseQualificationSources() as $gate=>$files) {
        if (($release[$gate]??false)!==true) continue;
        $binding=$bindings[$gate]??[];
        if (($binding['scope']??null)!=='IMPLEMENTATION_AND_ACCEPTED_EVIDENCE'
            || empty($binding['references']) || !is_array($binding['references'])) {
            throw new RuntimeException('RELEASE_QUALIFICATION_EVIDENCE_REQUIRED');
        }
        $hashes=$binding['source_sha256']??[];
        $keys=array_keys($hashes);$expected=$files;sort($keys);sort($expected);
        if ($keys!==$expected) throw new RuntimeException('RELEASE_QUALIFICATION_SOURCE_SET_CHANGED');
        foreach ($files as $file) {
            if (($hashes[$file]??null)!==releaseQualificationHash($root,$file)) {
                throw new RuntimeException('RELEASE_QUALIFICATION_SOURCE_CHANGED');
            }
        }
    }
    $plan=\Sulb\Release\ProductionUpgradeExecutor::plan($root);
    if (($release['production_upgrade']['catalog_sha256_normalization']??null)!=='LF'
        || ($release['approved_migration_paths']??null)!==array_column($plan,'source_path')
        || ($release['production_upgrade']['catalog_sha256']??null)!==releaseQualificationHash($root,'production-upgrade/catalog.json')) {
        throw new RuntimeException('EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
    }
    if (($release['deployment_backup_restore_qualified']??false)===true
        && ($release['production_upgrade']['qualified_backup_issuer_sha256']??null)!==hash_file('sha256',$root.'/ops/sulb-rc/release-backup.php')) {
        throw new RuntimeException('UPGRADE_QUALIFIED_BACKUP_ISSUER_REQUIRED');
    }
    if (($release['rollback_strategy_qualified']??false)===true) reviewedRollbackPolicy($release);
    if (($release['write_fence_qualified']??false)===true) {
        $writer=$release['write_fence_plan']??[];
        if (($writer['profile_authorized']??null)!==true || ($writer['qualification_mode']??null)!=='ACCOUNT_FENCE_WINDOW_V1'
            || preg_match('/^[a-f0-9]{64}$/D',$writer['profile_sha256']??'')!==1) {
            throw new RuntimeException('RELEASE_ACCEPTED_WRITER_PROFILE_REQUIRED');
        }
    }
    if (($release['production_derived_balance_executor_qualified']??false)===true || ($release['staging_passed']??false)===true) {
        $approval=$release['production_upgrade']['derived_artifact_owner_approval']??[];
        if (($release['approved_history_verified']??false)!==true
            || preg_match('/^[a-f0-9]{64}$/D',$release['production_upgrade']['derived_artifact_sha256']??'')!==1
            || preg_match('/^[a-f0-9]{64}$/D',$approval['history_result_sha256']??'')!==1
            || preg_match('/^[a-f0-9]{16}$/D',$approval['round_id']??'')!==1
            || preg_match('/^[a-f0-9]{64}$/D',$release['history_accounting_authority_sha256']??'')!==1
            || preg_match('/^[a-f0-9]{64}$/D',$release['legacy_allocation_accounting_approval']['prepared_packet_sha256']??'')!==1
            || ($approval['vps_inspector_result']??null)!=='PASS_REPORTED_BY_OPERATOR'
            || ($approval['backfill']??null)!=='PASS' || ($approval['LOT_GL']??null)!=='PASS'
            || ($approval['snapshot_restore_rehearsal']??null)!=='PASS'
            || ($approval['derived_artifact_sha256']??null)!==($release['production_upgrade']['derived_artifact_sha256']??null)
            || ($approval['generator_sha256']??null)!==releaseQualificationHash($root,'approved-history-rehearsal.php')
            || ($approval['authority_file_sha256']??null)!==($release['history_accounting_authority_sha256']??null)
            || ($approval['accounting_packet_sha256']??null)!==($release['legacy_allocation_accounting_approval']['prepared_packet_sha256']??null)) {
            throw new RuntimeException('RELEASE_ACCEPTED_HISTORY_BINDING_REQUIRED');
        }
    }
    if (($release['staging_passed']??false)===true) {
        $schema=$release['operator_schema_execution']??[];
        if (($schema['schema_only_rehearsal']??null)!=='PASS' || ($schema['uom_migrations_applied']??null)!==14
            || ($schema['lot_l3_dependencies_applied']??null)!==4 || ($schema['production_connected']??null)!==false
            || ($schema['owned_server_stopped']??null)!==true
            || preg_match('/^[a-f0-9]{64}$/D',$release['expected_production_schema_sha256']??'')!==1
            || ($release['expected_production_schema_sha256']??null)!==($release['received_production_schema_sha256']??null)) {
            throw new RuntimeException('RELEASE_ACCEPTED_SCHEMA_REHEARSAL_REQUIRED');
        }
    }
}

function releasePendingGates(array $release): array {
    $gates=['deployment_authorized','vps_schema_verified','approved_history_verified','staging_passed',
        'complete_security_review','production_backup_restore_rehearsed','rollback_strategy_qualified',
        'production_executor_qualified','write_fence_qualified','deployment_backup_restore_qualified',
        'production_derived_balance_executor_qualified','deployment_pipeline_qualified'];
    return array_values(array_filter($gates,fn($gate)=>($release[$gate]??false)!==true));
}

function inspectReleaseReadiness(array $release, string $root): array {
    if (PHP_OS_FAMILY!=='Linux' || posix_geteuid()!==0 || getcwd()!=='/home/sulb-release' || $root!=='/home/sulb-release') {
        throw new RuntimeException('RELEASE_READINESS_ROOT_CHECKOUT_REQUIRED');
    }
    verifyReleaseQualificationBindings($release,$root);
    require_once __DIR__.'/release-fence-evidence.php';
    require_once __DIR__.'/production-schema-gate.php';
    require_once __DIR__.'/inspect-approved-history.php';
    $checks=[];$errors=[];$connections=0;
    $check=function(string $name,callable $read)use(&$checks,&$errors):void{
        try {$read();$checks[$name]='PASS';}
        catch (Throwable $error) {
            $checks[$name]='BLOCKED';
            $errors[$name]=preg_match('/^[A-Z0-9_]+$/D',$error->getMessage())?$error->getMessage():'PRIVATE_EVIDENCE_CHECK_FAILED';
        }
    };
    $check('existing_history_artifact',function()use($release){
        $id=$release['production_upgrade']['derived_artifact_owner_approval']['round_id']??'';
        if (!preg_match('/^[a-f0-9]{16}$/D',$id)) throw new RuntimeException('HISTORY_APPROVED_ROUND_REQUIRED');
        inspectApprovedHistoryEvidence('/home/mgye-api-sulb/sulb-history-restore-'.$id.'/approved-history-details-private.json',$release);
    });
    $check('accepted_writer_profile_binding',function()use($release){
        $writer=$release['write_fence_plan'];$file=$writer['profile_file']??'';
        if (!preg_match('#^/home/mgye-api-sulb/sulb-writer-signoff-[a-f0-9]{16}/profile-window-private\.json$#D',$file)
            || realpath($file)!==$file) throw new RuntimeException('RELEASE_PRIVATE_WRITER_PROFILE_REQUIRED');
        accountPrivate($file);
        if (hash_file('sha256',$file)!==$writer['profile_sha256']) throw new RuntimeException('RELEASE_WRITER_PROFILE_CHANGED');
    });
    if(($release['runtime_execution_corrections']['effective_site_runtime_verified']??false)===true)$check('effective_site_runtime_evidence',fn()=>verifyPrivateSiteRuntimeEvidence($release));
    $check('current_schema_fingerprint',function()use($release,&$connections){
        $db=releaseAdminConnection();$connections++;
        verifyReleaseSourceUuid($db,$release);
        verifyProductionSchemaFingerprint($db,$release['expected_production_schema_sha256']??'');
    });
    return ['qualification_status'=>!$errors&&!releasePendingGates($release)?'QUALIFIED_PENDING_FINAL_GO_RECORD':'BLOCKED',
        'existing_evidence_checks'=>$checks,'evidence_errors'=>$errors,
        'pending_gates'=>releasePendingGates($release),
        'runtime_window_requirements'=>['actual_account_exclusion_and_worker_session_drain',
            'new_encrypted_backup_and_exact_isolated_restore','fresh_commit_bound_upgrade_receipt',
            'schema_fingerprint_and_financial_source_drift_check_before_ddl'],
        'runtime_window_evidence_created'=>false,'database_connections_created'=>$connections,
        'database_rows_written'=>false,'services_changed'=>false,'release_decision'=>$release['release_decision']];
}
