<?php
/** Bind existing private evidence without a database connection or rehearsal. */
require_once __DIR__.'/review-account-evidence.php';

function inspectApprovedHistoryEvidence(string $file, array $release): array {
    if (!preg_match('#^/home/mgye-api-sulb/sulb-history-restore-[a-f0-9]{16}/approved-history-details-private\.json$#D', $file)
        || realpath($file) !== $file || fileowner(dirname($file)) !== 0 || (fileperms(dirname($file)) & 0077) !== 0) {
        throw new RuntimeException('HISTORY_PRIVATE_ARTIFACT_REQUIRED');
    }
    $read = static function (string $path, int $limit): string {
        accountPrivate($path);
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        if ($bytes === false || strlen($bytes) > $limit) throw new RuntimeException('HISTORY_PRIVATE_FILE_UNREADABLE');
        return $bytes;
    };
    $bytes = $read($file, 64 * 1024 * 1024);
    $artifact = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    $resultBytes = $read(dirname($file).'/history-result.json', 65536);
    $result = json_decode($resultBytes, true, 512, JSON_THROW_ON_ERROR);
    $selection = $release['production_upgrade']['derived_artifact_owner_approval'] ?? [];
    if (hash('sha256', $bytes) !== ($release['production_upgrade']['derived_artifact_sha256'] ?? null)
        || hash('sha256', $resultBytes) !== ($selection['history_result_sha256'] ?? null)
        || basename(dirname($file)) !== 'sulb-history-restore-'.($selection['round_id'] ?? '')) {
        throw new RuntimeException('HISTORY_SELECTED_ARTIFACT_BINDING_MISMATCH');
    }
    $generator = hash('sha256', str_replace("\r\n", "\n", file_get_contents(__DIR__.'/approved-history-rehearsal.php')));
    $packet = $release['legacy_allocation_accounting_approval']['prepared_packet_sha256'] ?? null;
    $authority = $release['history_accounting_authority_sha256'] ?? null;
    if (($artifact['format'] ?? null) !== 'SULB_DERIVED_BALANCES_V1'
        || ($artifact['generator_sha256'] ?? null) !== $generator
        || !is_string($authority) || ($artifact['authority_file_sha256'] ?? null) !== $authority
        || hash('sha256', $read(dirname($file).'/authority-private.json', 1048576)) !== $authority
        || !is_string($packet) || ($artifact['accounting_packet_sha256'] ?? null) !== $packet
        || ($result['accounting_packet_sha256'] ?? null) !== $packet) {
        throw new RuntimeException('HISTORY_ARTIFACT_APPROVAL_BINDING_MISMATCH');
    }
    if (($artifact['production_derived_isolated_passed'] ?? null) !== true
        || ($artifact['original_source_preserved'] ?? null) !== true
        || ($artifact['production_derived_idempotency'] ?? null) !== 'PASS'
        || ($artifact['production_derived_rollback'] ?? null) !== 'PASS'
        || ($result['historical_status'] ?? null) !== 'PASS'
        || ($result['LOT_GL'] ?? null) !== 'PASS'
        || ($result['snapshot_restore_rehearsal'] ?? null) !== 'PASS'
        || ($result['production_connected'] ?? null) !== false
        || ($result['owned_database_removed'] ?? null) !== true) {
        throw new RuntimeException('HISTORY_ACCEPTED_RESULT_REQUIRED');
    }
    return ['derived_artifact_sha256'=>hash('sha256', $bytes),
        'history_result_sha256'=>hash('sha256', $resultBytes),
        'generator_sha256'=>$generator, 'authority_file_sha256'=>$authority,
        'accounting_packet_sha256'=>$packet, 'backfill'=>'PASS', 'LOT_GL'=>'PASS',
        'snapshot_restore_rehearsal'=>'PASS', 'database_connections'=>0];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0 || count($argv) !== 2) {
        throw new RuntimeException('HISTORY_PRIVATE_INSPECTION_CONTEXT_REQUIRED');
    }
    $release = json_decode(file_get_contents(__DIR__.'/release.json'), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(inspectApprovedHistoryEvidence($argv[1], $release), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $reason = $error->getMessage();
    fwrite(STDERR, (preg_match('/^(HISTORY_[A-Z0-9_]+|PRIVATE_EVIDENCE_REQUIRED)$/D', $reason)
        ? $reason : 'HISTORY_PRIVATE_INSPECTION_FAILED').PHP_EOL);
    exit(78);
}
