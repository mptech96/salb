<?php
/** REVIEW/ISOLATED ONLY. Deliberately outside automatic Laravel migrations. */
return new class extends \Illuminate\Database\Migrations\Migration {
    private function guard(): void
    {
        if(!class_exists(\Tests\Support\UomStageAProof::class))throw new \RuntimeException('Isolated identity migration only');
        \Tests\Support\UomStageAProof::guard();
        if(!str_starts_with(\Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() v')->v,'10.4.32-MariaDB'))throw new \RuntimeException('Target engine proof required');
    }
    public function up(): void
    {
        $this->guard();
        if(\Illuminate\Support\Facades\Schema::hasColumn('shipment_items','allocation_line_ref'))throw new \RuntimeException('Identity column already exists; inspect schema, do not overwrite');
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE shipment_items ADD allocation_line_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY uq_shipment_allocation_ref(company_id,shipment_id,allocation_line_ref)');
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER sli_insert BEFORE INSERT ON shipment_items FOR EACH ROW BEGIN
          IF NEW.allocation_line_ref IS NULL OR NOT (BINARY NEW.allocation_line_ref REGEXP '^BL:[A-Z0-9][A-Z0-9._/-]{0,119}$') OR BINARY NEW.allocation_line_ref REGEXP '[^A-Z0-9:._/-]' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SLI_NEW_REFERENCE_REQUIRED'; END IF;
          IF NOT EXISTS(SELECT 1 FROM shipments WHERE id=NEW.shipment_id AND company_id=NEW.company_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SLI_COMPANY_MISMATCH'; END IF;
        END");
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER sli_update BEFORE UPDATE ON shipment_items FOR EACH ROW BEGIN
          IF NOT (BINARY OLD.allocation_line_ref <=> BINARY NEW.allocation_line_ref) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SLI_IMMUTABLE_REFERENCE'; END IF;
          IF OLD.allocation_line_ref IS NOT NULL AND (OLD.company_id<>NEW.company_id OR OLD.shipment_id<>NEW.shipment_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SLI_IMMUTABLE_SCOPE'; END IF;
        END");
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER sli_delete BEFORE DELETE ON shipment_items FOR EACH ROW BEGIN
          IF OLD.allocation_line_ref IS NOT NULL AND (OLD.inventory_created=1 OR OLD.inventory_lot_id IS NOT NULL OR OLD.purchase_line_id IS NOT NULL OR EXISTS(SELECT 1 FROM invoice_shipment_links WHERE company_id=OLD.company_id AND shipment_id=OLD.shipment_id) OR EXISTS(SELECT 1 FROM shipment_costs WHERE company_id=OLD.company_id AND shipment_id=OLD.shipment_id AND cost_status='POSTED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SLI_FINANCIAL_DELETE_FORBIDDEN'; END IF;
        END");
    }
    public function down(): void
    {
        $this->guard();
        if(\Illuminate\Support\Facades\DB::table('shipment_items')->whereNotNull('allocation_line_ref')->exists())throw new \RuntimeException('SLI_ROLLBACK_BLOCKED_IDENTITIES_EXIST');
        foreach(['sli_insert','sli_update','sli_delete'] as $trigger)\Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE shipment_items DROP INDEX uq_shipment_allocation_ref, DROP COLUMN allocation_line_ref');
    }
};
