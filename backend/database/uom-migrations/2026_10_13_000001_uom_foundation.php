<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        if(config('uom.schema_install_approved')!==true)throw new RuntimeException('UOM schema installation requires explicit approval');
        if(Schema::hasTable('uom_units'))throw new RuntimeException('UOM foundation already installed; no partial rerun');
        foreach(explode('-- statement',file_get_contents(__DIR__.'/../uom-schema/foundation.sql')) as $sql)if(preg_match('/^(CREATE|ALTER)\s/i',trim($sql)))DB::unprepared(trim($sql));
        DB::unprepared("CREATE TABLE uom_item_modes(company_id BIGINT NOT NULL,item_id BIGINT UNSIGNED NOT NULL,mode VARCHAR(20) NOT NULL,PRIMARY KEY(company_id,item_id),FOREIGN KEY(company_id,item_id) REFERENCES items(company_id,id),CHECK(mode IN ('LEGACY_ONLY','BASE_ONLY'))) ENGINE=InnoDB");
        $history=implode(' OR ',array_map(fn($t)=>"EXISTS(SELECT 1 FROM {$t} WHERE company_id=NEW.company_id AND item_id=NEW.item_id)",['inventory_lots','inventory_lot_movements','stock_movements','purchase_invoice_lines','sales_invoice_lines','shipment_items','opening_inventory_lines','inventory_operation_lines','commercial_return_lines','sales_quotation_lines','purchase_order_lines']));
        DB::unprepared("CREATE TRIGGER uom_mode_new BEFORE INSERT ON uom_item_modes FOR EACH ROW BEGIN DECLARE item_guard BIGINT UNSIGNED; SELECT id INTO item_guard FROM items WHERE company_id=NEW.company_id AND id=NEW.item_id FOR UPDATE; IF NEW.mode='BASE_ONLY' AND ({$history}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_NEW_ITEM_REQUIRED'; END IF; END");
        DB::unprepared("CREATE TRIGGER uom_mode_immutable BEFORE UPDATE ON uom_item_modes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_MODE_IMMUTABLE'");
        DB::unprepared("CREATE TRIGGER uom_mode_delete BEFORE DELETE ON uom_item_modes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_MODE_IMMUTABLE'");
        foreach(['inventory_lots','inventory_lot_movements','stock_movements','purchase_invoice_lines','sales_invoice_lines','shipment_items','opening_inventory_lines','inventory_operation_lines','commercial_return_lines','sales_quotation_lines','purchase_order_lines'] as $table)foreach(['INSERT','UPDATE'] as $action){
            DB::unprepared("CREATE TRIGGER uom_closed_{$table}_{$action} BEFORE {$action} ON {$table} FOR EACH ROW BEGIN DECLARE m VARCHAR(20); DECLARE item_guard BIGINT UNSIGNED; SELECT id INTO item_guard FROM items WHERE company_id=NEW.company_id AND id=NEW.item_id FOR UPDATE; SELECT mode INTO m FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id FOR UPDATE; IF m='BASE_ONLY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_OPERATIONAL_WRITES_NOT_APPROVED'; END IF; END");
        }
        DB::unprepared("CREATE TRIGGER uom_mode_item_base BEFORE UPDATE ON items FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM uom_item_modes WHERE company_id=OLD.company_id AND item_id=OLD.id AND mode='BASE_ONLY') AND (NOT(NEW.base_uom_id<=>OLD.base_uom_id) OR NOT(NEW.base_unit_code<=>OLD.base_unit_code) OR NOT(NEW.company_id<=>OLD.company_id) OR NOT(NEW.inventory_quantity_version<=>OLD.inventory_quantity_version)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_BASE_DEFINITION_IMMUTABLE'; END IF; END");
    }
    public function down():void
    {
        throw new RuntimeException('No automatic destructive UOM rollback: preserve definitions/evidence; reviewed reverse DDL required');
    }
};
