<?php
/** Window exclusion qualification only. Never approves permanent MySQL privileges. */
const WRITER_WINDOW_MODE = 'ACCOUNT_FENCE_WINDOW_V1';

function writerWindowAssert(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}

function writerWindowCommitments(): array {
    return ['exclude_all_ordinary_writers', 'drain_existing_database_sessions',
        'drain_old_workers_and_cli', 'reject_other_privileged_sessions',
        'preserve_preexisting_locks', 'admin_channel_exclusive_during_window',
        'admin_exception_is_administrative_trust', 'no_shared_cron_or_deal_stop',
        'retain_fence_after_partial_ddl_failure'];
}

function validateWriterWindowSignoff(array $profile, array $facts, array $signed, array $evidence): void {
    writerWindowAssert(($profile['review']['qualification_mode'] ?? null) === WRITER_WINDOW_MODE,
        'WRITER_WINDOW_MODE_NOT_EXPLICIT');
    writerWindowAssert(($signed['qualification_scope'] ?? null) === 'MAINTENANCE_EXCLUSION_ONLY',
        'WRITER_WINDOW_SCOPE_REQUIRED');
    foreach (writerWindowCommitments() as $key) {
        writerWindowAssert(($signed['window_commitments'][$key] ?? null) === true,
            'WRITER_WINDOW_COMMITMENT_REQUIRED');
    }
    writerWindowAssert(($signed['runtime_limitations']['external_writer_absence_proven'] ?? null) === false
        && ($signed['runtime_limitations']['all_runtime_connections_authenticated'] ?? null) === false
        && ($signed['runtime_limitations']['scheduler_launcher_proven'] ?? null) === false,
        'WRITER_WINDOW_LIMITATIONS_MUST_REMAIN_EXPLICIT');
    // Source facts stay immutable; a window decision must not manufacture resolved proofs.
    writerWindowAssert(($signed['proof_resolutions'] ?? null) === [], 'WRITER_WINDOW_NO_SYNTHETIC_PROOF_RESOLUTIONS');
    writerWindowAssert(($signed['retained_unproved_categories'] ?? null) === array_column($facts['missing_proofs'] ?? [], 'code'),
        'WRITER_WINDOW_ORIGINAL_LIMITATIONS_REQUIRED');
    writerWindowAssert(($profile['review']['reviewer'] ?? null) === $signed['reviewer']
        && ($profile['review']['reference'] ?? null) === $signed['review_reference'], 'WRITER_WINDOW_REVIEW_IDENTITY_MISMATCH');
    $inventory = writerSignoffSet($profile['database_accounts'] ?? [], 'principal');
    $observed = writerSignoffSet($facts['accounts'] ?? [], 'principal');
    $decisions = writerSignoffSet($signed['accounts'] ?? [], 'principal');
    writerWindowAssert($inventory && array_keys($inventory) === array_keys($observed)
        && array_keys($inventory) === array_keys($decisions), 'WRITER_ALL_ACCOUNT_DECISIONS_REQUIRED');
    $writers = []; $admins = []; $deferred = 0;
    foreach ($inventory as $principal => $account) {
        $fact = $observed[$principal]; $decision = $decisions[$principal];
        foreach (['locked'=>'locked', 'source_write_capable'=>'can_write_sulbprod', 'privileged'=>'global_write_or_admin'] as $left=>$right) {
            writerWindowAssert(is_bool($fact[$left] ?? null) && $fact[$left] === ($account[$right] ?? null),
                'WRITER_APPROVED_ACCOUNT_FACTS_CHANGED');
        }
        writerWindowAssert(($fact['observed_capabilities'] ?? null) === ($account['capability_evidence'] ?? null),
            'WRITER_APPROVED_CAPABILITIES_CHANGED');
        writerSignoffIdentity($decision, $signed['signed_document_sha256']);
        writerWindowAssert(($decision['maintenance_responsible_owner'] ?? null) === $signed['reviewer']
            && writerSignoffText($decision['maintenance_handling'] ?? null), 'WRITER_WINDOW_ACCOUNT_RESPONSIBILITY_REQUIRED');
        $expected = $account['locked'] ? 'KEEP_LOCKED_EXCLUDED' : (!$account['can_write_sulbprod']
            ? 'CONFIRM_READ_ONLY' : ($account['global_write_or_admin'] ? 'EXCLUSIVE_ADMIN_CHANNEL' : 'EXCLUDE_DURING_WINDOW'));
        writerWindowAssert(($decision['decision'] ?? null) === $expected, 'WRITER_WINDOW_ACCOUNT_DECISION_REJECTED');
        if ($expected === 'EXCLUDE_DURING_WINDOW') $writers[] = $principal;
        if ($expected === 'EXCLUSIVE_ADMIN_CHANNEL') $admins[] = $principal;
        // Inventory evidence establishes exclusion scope, not ownership of every runtime consumer.
        writerWindowAssert(($decision['binding_basis'] ?? null) === ($expected === 'EXCLUSIVE_ADMIN_CHANNEL'
            ? 'SIGNED_EXCLUSIVE_ADMIN_OPERATOR' : 'CAPTURED_ACCOUNT_INVENTORY'), 'WRITER_WINDOW_BINDING_BASIS_REQUIRED');
        $capabilities = writerSignoffSet($decision['capabilities'] ?? [], 'capability');
        $captured = array_fill_keys($fact['observed_capabilities'], true); ksort($captured);
        writerWindowAssert(array_keys($capabilities) === array_keys($captured), 'WRITER_EVERY_CAPABILITY_REQUIRES_DECISION');
        foreach ($capabilities as $capability) {
            writerWindowAssert(($capability['permanent_audit_status'] ?? null) === 'DEFERRED_NOT_APPROVED'
                && ($capability['decision'] ?? null) === null, 'WRITER_WINDOW_PERMANENT_PRIVILEGES_NOT_APPROVED');
            if (!$account['locked'] && $account['can_write_sulbprod']) $deferred++;
        }
    }
    sort($writers); sort($admins);
    $planW = $profile['plan']['writer_accounts'] ?? []; $planA = $profile['plan']['trusted_privileged_accounts'] ?? [];
    sort($planW); sort($planA);
    writerWindowAssert(count($admins) === 1 && $writers && $writers === $planW && $admins === $planA,
        'WRITER_WINDOW_EXACT_ACCOUNT_PLAN_REQUIRED');
    $audit = $signed['permanent_capability_audit'] ?? [];
    writerWindowAssert(($audit['status'] ?? null) === 'OPEN_NOT_CERTIFIED'
        && ($audit['responsible_owner'] ?? null) === $signed['reviewer']
        && writerSignoffText($audit['tracking_reference'] ?? null)
        && ($audit['active_writer_capability_count'] ?? null) === $deferred,
        'WRITER_WINDOW_PERMANENT_AUDIT_TRACKING_REQUIRED');
    $services = writerSignoffSet($facts['service_candidates'] ?? [], 'path');
    $serviceDecisions = writerSignoffSet($signed['services'] ?? [], 'path');
    writerWindowAssert($services && array_keys($services) === array_keys($serviceDecisions), 'WRITER_ALL_SERVICE_DECISIONS_REQUIRED');
    $pool = $evidence['pool_binding'] ?? [];
    writerWindowAssert(($pool['configured_pool_binding'] ?? null) === 'PASS'
        && ($pool['listener_owned_by_master'] ?? null) === true
        && ($pool['site_uid'] ?? null) === ($profile['plan']['site_uid'] ?? null)
        && is_int($profile['plan']['site_uid'] ?? null)
        && ($pool['config_hashes'] ?? []) !== [], 'WRITER_WINDOW_POOL_EVIDENCE_REQUIRED');
    $configHashes = [];
    foreach (array_merge($profile['surface_files'] ?? [], $profile['external_writer_source_files'] ?? []) as $row) {
        $configHashes[$row['path']] = $row['sha256'];
    }
    foreach ($pool['config_hashes'] as $path=>$sha) {
        writerWindowAssert(writerSignoffSha($sha) && ($configHashes[$path] ?? null) === $sha,
            'WRITER_WINDOW_POOL_SOURCE_NOT_REVIEWED');
    }
    foreach ($services as $path=>$service) {
        $decision = $serviceDecisions[$path]; writerSignoffIdentity($decision, $signed['signed_document_sha256']);
        writerWindowAssert(preg_match('#^/etc/php/([0-9]+\.[0-9]+)/fpm/pool\.d/[A-Za-z0-9_.-]+\.conf$#D', $path, $match) === 1
            && ($profile['plan']['php_fpm_unit'] ?? null) === 'php'.$match[1].'-fpm.service',
            'WRITER_WINDOW_UNSUPPORTED_SERVICE_REQUIRES_REVIEW');
        writerWindowAssert(($decision['source_sha256'] ?? null) === $service['sha256']
            && ($pool['config_hashes'][$path] ?? null) === $service['sha256']
            && ($decision['classification'] ?? null) === 'PHP_FPM_POOL_CONFIGURATION'
            && ($decision['decision'] ?? null) === 'REVIEW_CONFIGURATION_FOR_WINDOW'
            && ($decision['owner'] ?? null) === $signed['reviewer']
            && ($decision['mysql_authentication_proven'] ?? null) === false
            && ($decision['scheduler_launcher_proven'] ?? null) === false
            && ($decision['account_principals'] ?? null) === []
            && ($decision['candidate_principals'] ?? null) === ($service['candidate_principals'] ?? []),
            'WRITER_WINDOW_POOL_CLASSIFICATION_REQUIRED');
    }
    // This narrow mode currently qualifies only the observed pool configuration, not arbitrary workers.
    writerWindowAssert(($profile['plan']['writer_units'] ?? null) === [], 'WRITER_WINDOW_WORKER_UNIT_REVIEW_REQUIRED');
}

function loadWriterWindowEvidence(array $signed, string $directory): array {
    $row = $signed['window_evidence']['pool_binding'] ?? [];
    $path = $row['file'] ?? ''; $sha = $row['sha256'] ?? '';
    writerWindowAssert(is_string($path) && preg_match('/^pool-binding-[a-f0-9]{16}\.json$/D', $path) === 1
        && writerSignoffSha($sha), 'WRITER_WINDOW_PRIVATE_POOL_FILE_REQUIRED');
    $file = $directory.'/'.$path; accountPrivate($file);
    writerWindowAssert(filesize($file) <= 1048576 && hash_file('sha256', $file) === $sha,
        'WRITER_WINDOW_PRIVATE_POOL_HASH_MISMATCH');
    return ['pool_binding'=>json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)];
}
