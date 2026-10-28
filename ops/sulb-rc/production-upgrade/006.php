<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up():void
    {
        \Sulb\Release\ProductionUpgradeContext::guard();
        \Sulb\Release\ProductionUpgradeContext::guard();if(!config('uom.schema_install_approved')||!Schema::hasTable('uom_operational_schema'))throw new RuntimeException('UOM_SCHEMA_ORDER');
        if(Schema::hasTable('uom_inventory_adjustments')){$this->assertExistingSchema();return;}
        DB::unprepared("CREATE TABLE uom_inventory_adjustments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT NOT NULL, branch_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NOT NULL, request_key VARCHAR(80) NOT NULL, request_hash CHAR(64) NOT NULL, movement_type VARCHAR(3) NOT NULL, movement_date DATE NOT NULL, uom_version VARCHAR(12) NOT NULL DEFAULT 'BASE_V1', uom_mapping_id BIGINT UNSIGNED NOT NULL, base_uom_id_snapshot BIGINT UNSIGNED NOT NULL, quantity DECIMAL(18,6) NOT NULL, unit_code VARCHAR(20) NOT NULL, uom_factor_snapshot DECIMAL(18,6) NOT NULL, base_quantity DECIMAL(24,6) NOT NULL, total_cost DECIMAL(18,3) NOT NULL DEFAULT 0, document_status VARCHAR(12) NOT NULL DEFAULT 'DRAFT', journal_entry_id BIGINT UNSIGNED NULL, reversal_journal_entry_id BIGINT UNSIGNED NULL, lot_sources_json LONGTEXT NULL, notes TEXT NOT NULL, void_reason TEXT NULL, created_by BIGINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_uom_adjustment_request(company_id,request_key), CHECK(movement_type IN ('IN','OUT')), CHECK(document_status IN ('DRAFT','POSTED','VOIDED')), CHECK(quantity>0 AND base_quantity>0 AND uom_factor_snapshot>0 AND base_quantity=quantity*uom_factor_snapshot), CHECK(total_cost>=0), FOREIGN KEY(company_id) REFERENCES companies(id), FOREIGN KEY(branch_id) REFERENCES branches(id), FOREIGN KEY(item_id) REFERENCES items(id), FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id)) ENGINE=InnoDB");
    }
    private function assertExistingSchema():void
    {
        $columns=collect(DB::select('SHOW COLUMNS FROM uom_inventory_adjustments'))->keyBy('Field');
        foreach(['company_id','branch_id','item_id','request_key','request_hash','movement_type','movement_date','uom_version','uom_mapping_id','base_uom_id_snapshot','quantity','unit_code','uom_factor_snapshot','base_quantity','total_cost','document_status','journal_entry_id','reversal_journal_entry_id','lot_sources_json','notes','void_reason','created_by','created_at','updated_at'] as $name)
            if(!$columns->has($name))throw new RuntimeException('UOM_PARTIAL_ADJUSTMENT_SCHEMA');
        foreach(['quantity'=>'decimal(18,6)','uom_factor_snapshot'=>'decimal(18,6)','base_quantity'=>'decimal(24,6)','total_cost'=>'decimal(18,3)'] as $name=>$type)
            if(strtolower($columns[$name]->Type)!==$type)throw new RuntimeException('UOM_ADJUSTMENT_SCHEMA_PRECISION');
        $indexes=collect(DB::select('SHOW INDEX FROM uom_inventory_adjustments'))->where('Non_unique',0)->groupBy('Key_name');
        if(!$indexes->contains(fn($rows)=>$rows->sortBy('Seq_in_index')->pluck('Column_name')->all()===['company_id','request_key']))throw new RuntimeException('UOM_ADJUSTMENT_SCHEMA_REQUEST_SCOPE');
    }
    public function down():void {
        throw new \RuntimeException('PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN');throw new RuntimeException('Preserve adjustment evidence');}
};
