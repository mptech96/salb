<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up():void {
        \Tests\Support\UomStageAProof::guard();if(!config('uom.schema_install_approved')||!Schema::hasTable('uom_operational_schema'))throw new RuntimeException('UOM_SCHEMA_ORDER');
        if(!Schema::hasTable('uom_invoice_header_cost_receipts'))DB::unprepared("CREATE TABLE uom_invoice_header_cost_receipts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,invoice_id BIGINT UNSIGNED NOT NULL,amount DECIMAL(18,3) NOT NULL,payload LONGTEXT NOT NULL,payload_hash CHAR(64) NOT NULL,journal_entry_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_uom_header_cost(company_id,invoice_id),CHECK(amount>0)) ENGINE=InnoDB");
        foreach(['company_id','invoice_id','amount','payload','payload_hash','journal_entry_id'] as $column)if(!Schema::hasColumn('uom_invoice_header_cost_receipts',$column))throw new RuntimeException('UOM_PARTIAL_HEADER_COST_SCHEMA');
        \Tests\Support\QaEngineSchema::trigger("CREATE OR REPLACE TRIGGER uom_header_cost_update BEFORE UPDATE ON uom_invoice_header_cost_receipts FOR EACH ROW BEGIN IF OLD.journal_entry_id IS NOT NULL OR NEW.company_id<>OLD.company_id OR NEW.invoice_id<>OLD.invoice_id OR NEW.amount<>OLD.amount OR NEW.payload<>OLD.payload OR NEW.payload_hash<>OLD.payload_hash THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_HEADER_COST_EVIDENCE_IMMUTABLE'; END IF; END");
        \Tests\Support\QaEngineSchema::trigger("CREATE OR REPLACE TRIGGER uom_header_cost_delete BEFORE DELETE ON uom_invoice_header_cost_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_HEADER_COST_HISTORY_PRESERVED'");
    }
    public function down():void {throw new RuntimeException('Preserve header cost receipts');}
};
