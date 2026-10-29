<?php
function reviewedRollbackPolicy(array $release):void {
    $p=$release['rollback_strategy']??[];if(($release['rollback_strategy_qualified']??false)!==true||($p['automatic_database_restore']??null)!==false||($p['automatic_migration_down']??null)!==false||($p['failure_retains_write_fence']??null)!==true||($p['partial_ddl_requires_forward_repair']??null)!==true)throw new RuntimeException('REVIEWED_ROLLBACK_STRATEGY_REQUIRED');
    if(($release['backward_compatible_schema_proven']??false)!==true&&(($p['mode']??'')!=='FORWARD_REPAIR_ONLY'||($p['code_rollback_authorized']??null)!==false))throw new RuntimeException('UNPROVEN_OLD_CODE_ROLLBACK_FORBIDDEN');
}
