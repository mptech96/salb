<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up():void
    {
        \Tests\Support\UomStageAProof::guard();
        if(!config('uom.schema_install_approved')||!Schema::hasTable('uom_operational_schema'))throw new RuntimeException('UOM_SCHEMA_ORDER');
        if(!Schema::hasColumn('opening_inventory_lines','uom_version')){
        DB::unprepared("ALTER TABLE opening_inventory_lines ADD uom_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG', ADD uom_mapping_id BIGINT UNSIGNED NULL, ADD base_uom_id_snapshot BIGINT UNSIGNED NULL, ADD base_quantity DECIMAL(24,6) NULL, ADD quantity DECIMAL(18,6) NULL, ADD unit_code VARCHAR(20) NULL, ADD uom_factor_snapshot DECIMAL(18,6) NULL, ADD CHECK(uom_version IN ('LEGACY_KG','BASE_V1')), ADD CHECK(uom_version='LEGACY_KG' OR (qty_kg=0 AND quantity>0 AND base_quantity>0 AND uom_factor_snapshot>0 AND base_quantity=quantity*uom_factor_snapshot AND uom_mapping_id IS NOT NULL AND base_uom_id_snapshot IS NOT NULL))");
        }
        foreach(['uom_mapping_id','base_uom_id_snapshot','base_quantity','quantity','unit_code','uom_factor_snapshot'] as $column)if(!Schema::hasColumn('opening_inventory_lines',$column))throw new RuntimeException('UOM_PARTIAL_OPENING_SCHEMA');
        foreach(['INSERT','UPDATE'] as $action){
            DB::unprepared("DROP TRIGGER IF EXISTS uom_closed_opening_inventory_lines_{$action}");
            DB::unprepared("CREATE OR REPLACE TRIGGER uom_version_opening_inventory_lines_{$action} BEFORE {$action} ON opening_inventory_lines FOR EACH ROW BEGIN DECLARE mode_guard VARCHAR(20); DECLARE item_guard BIGINT UNSIGNED; SELECT id INTO item_guard FROM items WHERE id=NEW.item_id AND company_id=NEW.company_id FOR UPDATE; SELECT mode INTO mode_guard FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id FOR UPDATE; IF (mode_guard='BASE_ONLY' AND NEW.uom_version<>'BASE_V1') OR (NEW.uom_version='BASE_V1' AND (mode_guard IS NULL OR mode_guard<>'BASE_ONLY' OR NOT EXISTS(SELECT 1 FROM item_uom_mappings m WHERE m.id=NEW.uom_mapping_id AND m.company_id=NEW.company_id AND m.item_id=NEW.item_id AND m.base_unit_id=NEW.base_uom_id_snapshot AND m.factor_to_base=NEW.uom_factor_snapshot))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_OPENING_VERSION_SCOPE'; END IF; END");
        }
    }
    public function down():void {throw new RuntimeException('Preserve opening unit evidence');}
};
