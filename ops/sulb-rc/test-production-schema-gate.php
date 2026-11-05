<?php
require __DIR__.'/production-schema-gate.php';

$sets = [[['TABLE_NAME'=>'inventory_lots', 'ORDINAL_POSITION'=>1]]];
$expected = hash('sha256', json_encode($sets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
requireProductionSchemaFingerprint($expected, fn () => $sets);

foreach ([
    ['UPGRADE_SCHEMA_FINGERPRINT_MISMATCH', $expected, fn () => []],
    ['UPGRADE_SCHEMA_METADATA_UNREADABLE', $expected, fn () => throw new PDOException('private detail')],
    ['UPGRADE_SCHEMA_BASELINE_REQUIRED', '', fn () => throw new LogicException('unexpected read')],
] as [$reason, $baseline, $read]) {
    try {
        requireProductionSchemaFingerprint($baseline, $read);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $reason) continue;
        throw $error;
    }
    throw new LogicException('Expected rejection: '.$reason);
}
echo "PASS schema match, drift, unreadable metadata and missing baseline\n";
