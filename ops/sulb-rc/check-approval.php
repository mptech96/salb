<?php
// No application bootstrap, credentials, filesystem mutation or database access.
$release=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);
$gates=['deployment_authorized','vps_schema_verified','approved_history_verified','staging_passed','complete_security_review','production_backup_restore_rehearsed','backward_compatible_schema_proven','production_executor_qualified','write_fence_qualified','deployment_backup_restore_qualified','production_derived_balance_executor_qualified','deployment_pipeline_qualified'];
$missing=array_values(array_filter($gates,fn($gate)=>($release[$gate]??false)!==true));
if(($release['release_decision']??'')!=='GO'||$missing||!preg_match('/^[a-f0-9]{64}$/D',$release['expected_production_schema_sha256']??'')||!count($release['approved_migration_paths']??[])){
    fwrite(STDERR,"NO-GO: release approval, exact schema evidence, staging and ordered production executor are not certified.\n");exit(78);
}
require_once __DIR__.'/production-upgrade-runtime.php';
$plan=\Sulb\Release\ProductionUpgradeExecutor::plan(dirname(__DIR__,2));
if($release['approved_migration_paths']!==array_column($plan,'source_path'))throw new RuntimeException('EXACT_ORDERED_18_PRODUCTION_ADAPTERS_REQUIRED');
if(($argv[1]??'')==='--migrations')foreach($release['approved_migration_paths'] as $path)echo $path.PHP_EOL;
