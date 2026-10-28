<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive local QA installation. Not part of automatic/production migrations. */
return new class extends Migration {
    public function up(): void
    {
        \Sulb\Release\ProductionUpgradeContext::guard();
        if (!config('uom.schema_install_approved') || !class_exists(\Sulb\Release\ProductionUpgradeContext::class)) throw new RuntimeException('Isolated UOM schema approval required');
        \Sulb\Release\ProductionUpgradeContext::guard();
        \Sulb\Release\ProductionUpgradeDialect::assertSupported();
        if (!Schema::hasTable('uom_item_modes') || Schema::hasTable('uom_operational_schema')) throw new RuntimeException('UOM schema order/rerun refused');
        $tables=['purchase_invoice_lines','sales_invoice_lines','inventory_lots','inventory_lot_movements','stock_movements','commercial_return_lines','sales_line_lot_sources','commercial_return_lot_sources','inventory_operation_lines','inventory_operation_lot_links'];
        foreach($tables as $table) {
            $weight=$table==='inventory_lots'?'qty_received_kg=0 AND qty_remaining_kg=0':'qty_kg=0';
            DB::unprepared("ALTER TABLE `$table` ADD uom_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG', ADD uom_mapping_id BIGINT UNSIGNED NULL, ADD base_uom_id_snapshot BIGINT UNSIGNED NULL, ADD base_quantity DECIMAL(24,6) NULL, ADD CHECK(uom_version IN ('LEGACY_KG','BASE_V1')), ADD CHECK(uom_version='LEGACY_KG' OR (base_quantity IS NOT NULL AND base_quantity>0 AND base_uom_id_snapshot IS NOT NULL AND uom_mapping_id IS NOT NULL AND $weight))");
        }
        DB::unprepared('ALTER TABLE inventory_lots ADD received_base_qty DECIMAL(24,6) NULL, ADD remaining_base_qty DECIMAL(24,6) NULL, ADD sold_base_qty DECIMAL(24,6) NULL, ADD unit_cost_per_base DECIMAL(24,6) NULL, ADD uom_remaining_value DECIMAL(18,3) NULL, ADD CHECK(uom_version=\'LEGACY_KG\' OR (received_base_qty IS NOT NULL AND remaining_base_qty IS NOT NULL AND sold_base_qty IS NOT NULL AND uom_remaining_value IS NOT NULL AND received_base_qty=base_quantity AND remaining_base_qty>=0 AND remaining_base_qty<=received_base_qty AND sold_base_qty>=0 AND uom_remaining_value>=0 AND uom_remaining_value<=total_cost AND (remaining_base_qty>0 OR uom_remaining_value=0) AND qty_received_kg=0 AND qty_remaining_kg=0 AND qty_sold_kg=0))');
        foreach(['purchase_invoice_lines','sales_invoice_lines','commercial_return_lines'] as $table) {
            DB::unprepared("ALTER TABLE `$table` ADD uom_factor_snapshot DECIMAL(18,6) NULL, ADD uom_price_snapshot DECIMAL(24,6) NULL, ADD CHECK(uom_version='LEGACY_KG' OR (quantity IS NOT NULL AND uom_factor_snapshot IS NOT NULL AND uom_factor_snapshot>0 AND base_quantity=quantity*uom_factor_snapshot))");
        }
        foreach(['sales_line_lot_sources','commercial_return_lot_sources'] as $table)DB::unprepared("ALTER TABLE `$table` ADD uom_unit_cost DECIMAL(24,6) NULL");
        DB::unprepared('ALTER TABLE inventory_operation_lines ADD quantity DECIMAL(18,6) NULL, ADD unit_code VARCHAR(20) NULL, ADD uom_factor_snapshot DECIMAL(18,6) NULL');
        // The frozen gates on non-adapted shipment/opening/processing writers stay closed.
        foreach(['purchase_invoice_lines','sales_invoice_lines','inventory_lots','inventory_lot_movements','stock_movements','commercial_return_lines','inventory_operation_lines'] as $table) {
            foreach(['INSERT','UPDATE'] as $action) {
                DB::unprepared("DROP TRIGGER uom_closed_{$table}_{$action}");
                DB::unprepared("CREATE TRIGGER uom_version_{$table}_{$action} BEFORE {$action} ON `$table` FOR EACH ROW BEGIN DECLARE mode_guard VARCHAR(20); DECLARE item_guard BIGINT UNSIGNED; SELECT id INTO item_guard FROM items WHERE id=NEW.item_id AND company_id=NEW.company_id FOR UPDATE; SELECT mode INTO mode_guard FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id FOR UPDATE; IF (mode_guard='BASE_ONLY' AND NEW.uom_version<>'BASE_V1') OR (NEW.uom_version='BASE_V1' AND (mode_guard IS NULL OR mode_guard<>'BASE_ONLY' OR NOT EXISTS(SELECT 1 FROM item_uom_mappings m JOIN items i ON i.id=m.item_id AND i.company_id=m.company_id WHERE m.id=NEW.uom_mapping_id AND m.company_id=NEW.company_id AND m.item_id=NEW.item_id AND m.base_unit_id=NEW.base_uom_id_snapshot AND i.base_uom_id=m.base_unit_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_OPERATIONAL_VERSION_SCOPE'; END IF; END");
            }
        }
        DB::unprepared("CREATE TABLE uom_operational_schema(version VARCHAR(32) PRIMARY KEY) ENGINE=InnoDB");
        DB::table('uom_operational_schema')->insert(['version'=>'BASE_DOCUMENT_V1']);
    }

    public function down(): void {
        throw new \RuntimeException('PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN'); throw new RuntimeException('No destructive rollback of UOM operational evidence'); }
};
