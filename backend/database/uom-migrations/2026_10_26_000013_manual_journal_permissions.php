<?php
use Illuminate\Support\Facades\DB;
return new class {
    public function up():void {
        require_once __DIR__.'/../../tests/Support/UomStageAProof.php';\Tests\Support\UomStageAProof::guard();
        if(!config('uom.schema_install_approved'))throw new RuntimeException('QA_PERMISSION_INSTALL_APPROVAL_REQUIRED');
        DB::transaction(function(){foreach(config('manual_journal.permissions',[]) as $permission)DB::table('permissions')->insertOrIgnore([...$permission,'created_at'=>now()]);});
    }
    public function down():void {throw new RuntimeException('Permission removal requires explicit role review');}
};
