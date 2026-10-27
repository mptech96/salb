<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up():void {
        \Tests\Support\UomStageAProof::guard();if(!config('uom.schema_install_approved')||!Schema::hasTable('uom_processing_recipes'))throw new RuntimeException('UOM_SCHEMA_ORDER');
        if(!Schema::hasTable('uom_processing_commands'))DB::unprepared("CREATE TABLE uom_processing_commands(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,operation_id BIGINT UNSIGNED NOT NULL,recipe_id BIGINT UNSIGNED NOT NULL,request_key VARCHAR(80) NOT NULL,request_hash CHAR(64) NOT NULL,payload LONGTEXT NOT NULL,payload_hash CHAR(64) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'DRAFT',result_json LONGTEXT NULL,reversal_journal_entry_id BIGINT UNSIGNED NULL,void_reason TEXT NULL,created_by BIGINT NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_process_request(company_id,request_key),UNIQUE KEY uq_process_operation(company_id,operation_id),CHECK(status IN ('DRAFT','POSTED','VOIDED')),FOREIGN KEY(recipe_id) REFERENCES uom_processing_recipes(id)) ENGINE=InnoDB");
        if(!Schema::hasColumn('uom_processing_commands','result_hash')){
            if(DB::table('uom_processing_commands')->whereNotNull('result_json')->exists())throw new RuntimeException('UOM_PROCESS_RESULT_EVIDENCE_REVIEW_REQUIRED');
            DB::unprepared('ALTER TABLE uom_processing_commands ADD result_hash CHAR(64) NULL');
        }
        foreach(['company_id','branch_id','operation_id','recipe_id','request_hash','payload','payload_hash','status','result_json','result_hash','reversal_journal_entry_id'] as $column)if(!Schema::hasColumn('uom_processing_commands',$column))throw new RuntimeException('UOM_PARTIAL_PROCESS_SCHEMA');
        \Tests\Support\QaEngineSchema::trigger("CREATE OR REPLACE TRIGGER uom_process_command_preserve BEFORE DELETE ON uom_processing_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_PROCESS_HISTORY_PRESERVED'");
        \Tests\Support\QaEngineSchema::trigger("CREATE OR REPLACE TRIGGER uom_process_command_evidence BEFORE UPDATE ON uom_processing_commands FOR EACH ROW BEGIN IF OLD.status='VOIDED' OR NEW.company_id<>OLD.company_id OR NEW.branch_id<>OLD.branch_id OR NEW.operation_id<>OLD.operation_id OR NEW.recipe_id<>OLD.recipe_id OR NEW.request_key<>OLD.request_key OR NEW.request_hash<>OLD.request_hash OR NEW.payload<>OLD.payload OR NEW.payload_hash<>OLD.payload_hash OR (OLD.status='POSTED' AND (NOT(NEW.result_json<=>OLD.result_json) OR NOT(NEW.result_hash<=>OLD.result_hash))) OR (NEW.status='POSTED' AND (NEW.result_json IS NULL OR NEW.result_hash IS NULL OR NEW.result_hash<>SHA2(NEW.result_json,256))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_PROCESS_EVIDENCE_IMMUTABLE'; END IF; END");
    }
    public function down():void {throw new RuntimeException('Preserve processing command evidence');}
};
