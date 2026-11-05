<?php
require_once __DIR__.'/../../backend/scripts/release_metadata_collect.php';

function releaseSchemaMetadata(PDO $db): array {
    $sets = [];
    foreach (releaseMetadataQueries($db) as $statement) {
        $result = $db->query($statement);
        if (str_starts_with(strtoupper($statement), 'SELECT') || str_starts_with($statement, 'SHOW SESSION VARIABLES')) {
            $sets[] = $result->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    foreach (releaseExtendedMetadataQueries($db) as $statement) {
        $sets[] = $db->query($statement)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $sets;
}

function releaseSchemaFingerprint(array $sets): string {
    return hash('sha256', json_encode($sets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function requireProductionSchemaFingerprint(string $expected, callable $read): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) {
        throw new RuntimeException('UPGRADE_SCHEMA_BASELINE_REQUIRED');
    }
    try {
        $actual = releaseSchemaFingerprint($read());
    } catch (Throwable $error) {
        throw new RuntimeException('UPGRADE_SCHEMA_METADATA_UNREADABLE', 0, $error);
    }
    if (!hash_equals($expected, $actual)) {
        throw new RuntimeException('UPGRADE_SCHEMA_FINGERPRINT_MISMATCH');
    }
}

function verifyProductionSchemaFingerprint(PDO $db, string $expected): void {
    requireProductionSchemaFingerprint($expected, function () use ($db): array {
        if ($db->inTransaction() || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('SCHEMA_METADATA_CONNECTION_STATE');
        }
        $stringify = $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if (!$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false)) {
            throw new RuntimeException('SCHEMA_METADATA_FETCH_MODE');
        }
        try {
            return releaseSchemaMetadata($db);
        } finally {
            try {
                if ($db->inTransaction()) $db->rollBack();
            } finally {
                if (!$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify)) {
                    throw new RuntimeException('SCHEMA_METADATA_FETCH_MODE_RESTORE');
                }
            }
        }
    });
}
