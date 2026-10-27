<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up():void {
        \Tests\Support\UomStageAProof::guard();if(!config('uom.schema_install_approved')||!Schema::hasTable('shipment_cost_allocation_receipts'))throw new RuntimeException('UOM_CAPITALIZATION_SCHEMA_ORDER');
        if(!Schema::hasTable('uom_lot_capitalization_events'))DB::unprepared("CREATE TABLE uom_lot_capitalization_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,inventory_lot_id BIGINT UNSIGNED NOT NULL,shipment_id BIGINT UNSIGNED NOT NULL,shipment_cost_id BIGINT UNSIGNED NOT NULL,receipt_id BIGINT UNSIGNED NOT NULL,receipt_hash CHAR(64) NOT NULL,amount DECIMAL(18,3) NOT NULL,before_value DECIMAL(18,3) NOT NULL,after_value DECIMAL(18,3) NOT NULL,quantity_movement_high_water BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_base_lot_capitalization(company_id,inventory_lot_id,shipment_cost_id),CHECK(amount>0 AND before_value>=0 AND after_value=before_value+amount)) ENGINE=InnoDB");
        foreach(['company_id','branch_id','inventory_lot_id','shipment_id','shipment_cost_id','receipt_id','receipt_hash','amount','before_value','after_value','quantity_movement_high_water'] as $column)if(!Schema::hasColumn('uom_lot_capitalization_events',$column))throw new RuntimeException('UOM_PARTIAL_CAPITALIZATION_SCHEMA');
        foreach(['UPDATE','DELETE'] as $action)\Tests\Support\QaEngineSchema::trigger("CREATE OR REPLACE TRIGGER uom_lot_capitalization_{$action} BEFORE {$action} ON uom_lot_capitalization_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_CAPITALIZATION_EVIDENCE_IMMUTABLE'");
    }
    public function down():void {throw new RuntimeException('Preserve capitalization evidence');}
};
