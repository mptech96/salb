<?php
use Illuminate\Support\Facades\DB;
return new class {
    public function up():void {
        \Sulb\Release\ProductionUpgradeContext::guard();
        \Sulb\Release\ProductionUpgradeContext::guard();
        if(!config('uom.schema_install_approved'))throw new RuntimeException('QA_PERMISSION_INSTALL_APPROVAL_REQUIRED');
        DB::transaction(function(){foreach(config('manual_journal.permissions',[]) as $permission)DB::table('permissions')->insertOrIgnore([...$permission,'created_at'=>now()]);});
    }
    public function down():void {
        throw new \RuntimeException('PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN');throw new RuntimeException('Permission removal requires explicit role review');}
};
