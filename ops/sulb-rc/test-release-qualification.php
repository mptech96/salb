<?php
require __DIR__.'/release-qualification.php';
$root=dirname(__DIR__,2);
$release=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;
$assert=function(bool $value)use(&$checks){if(!$value)throw new LogicException('QUALIFICATION_TEST_'.($checks+1));$checks++;};
$reject=function(callable $mutate,string $expected)use($release,$root,$assert){
    $changed=$release;$mutate($changed);
    try{verifyReleaseQualificationBindings($changed,$root);}
    catch(RuntimeException $error){$assert($error->getMessage()===$expected);return;}
    $assert(false);
};
verifyReleaseQualificationBindings($release,$root);$assert(true);
$assert(releasePendingGates($release)===['deployment_authorized','complete_security_review']);
$assert($release['release_decision']==='NO-GO');
$assert($release['production_backup']['creation_authorized']===false);
$assert($release['write_fence_plan']['temporary_account_lock_authorized']===false);
foreach(releaseQualificationSources()as$gate=>$files){
    $reject(function(&$r)use($gate){unset($r['integrated_qualification']['gate_evidence'][$gate]);},'RELEASE_QUALIFICATION_EVIDENCE_REQUIRED');
    $reject(function(&$r)use($gate,$files){$r['integrated_qualification']['gate_evidence'][$gate]['source_sha256'][$files[0]]=str_repeat('0',64);},'RELEASE_QUALIFICATION_SOURCE_CHANGED');
}
$reject(function(&$r){array_pop($r['approved_migration_paths']);},'EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
$reject(function(&$r){$r['approved_migration_paths']=array_reverse($r['approved_migration_paths']);},'EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
$reject(function(&$r){$r['production_upgrade']['catalog_sha256']=str_repeat('0',64);},'EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
$reject(function(&$r){$r['production_upgrade']['catalog_sha256_normalization']='UNKNOWN';},'EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
$reject(function(&$r){$r['production_upgrade']['qualified_backup_issuer_sha256']=str_repeat('0',64);},'UPGRADE_QUALIFIED_BACKUP_ISSUER_REQUIRED');
$reject(function(&$r){$r['rollback_strategy']['automatic_database_restore']=true;},'REVIEWED_ROLLBACK_STRATEGY_REQUIRED');
$reject(function(&$r){$r['write_fence_plan']['profile_authorized']=false;},'RELEASE_ACCEPTED_WRITER_PROFILE_REQUIRED');
$reject(function(&$r){$r['write_fence_plan']['qualification_mode']='LEGACY';},'RELEASE_ACCEPTED_WRITER_PROFILE_REQUIRED');
$reject(function(&$r){$r['production_upgrade']['derived_artifact_sha256']=null;$r['production_upgrade']['derived_artifact_owner_approval']['derived_artifact_sha256']=null;},'RELEASE_ACCEPTED_HISTORY_BINDING_REQUIRED');
$reject(function(&$r){$r['production_upgrade']['derived_artifact_owner_approval']['generator_sha256']=str_repeat('0',64);},'RELEASE_ACCEPTED_HISTORY_BINDING_REQUIRED');
$reject(function(&$r){$r['operator_schema_execution']['schema_only_rehearsal']='BLOCKED';},'RELEASE_ACCEPTED_SCHEMA_REHEARSAL_REQUIRED');
$reject(function(&$r){$r['expected_production_schema_sha256']=str_repeat('0',64);},'RELEASE_ACCEPTED_SCHEMA_REHEARSAL_REQUIRED');
$before=serialize($release);verifyReleaseQualificationBindings($release,$root);$assert(serialize($release)===$before);
echo 'PASS '.$checks.' qualification evidence, source/hash drift, ordered migrations and pending final approvals; production_connections=0'.PHP_EOL;
