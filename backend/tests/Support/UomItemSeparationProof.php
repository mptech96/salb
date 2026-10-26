<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;

/** Disposable proof. No operational binding or production security claims. */
final class UomItemSeparationProof
{
    public static function install(bool $modes=true):void
    {
        UomStageAProof::guard();
        if($modes){
        DB::unprepared("CREATE TABLE uom_item_modes(company_id BIGINT NOT NULL,item_id BIGINT UNSIGNED NOT NULL,mode VARCHAR(20) NOT NULL,PRIMARY KEY(company_id,item_id),FOREIGN KEY(company_id,item_id) REFERENCES items(company_id,id),CHECK(mode IN ('LEGACY_ONLY','BASE_ONLY','MIXED_VERIFIED')))");
        $history=implode(' OR ',array_map(fn($t)=>"EXISTS(SELECT 1 FROM {$t} WHERE company_id=NEW.company_id AND item_id=NEW.item_id)",['inventory_lots','stock_movements','inventory_lot_movements','purchase_invoice_lines','sales_invoice_lines','shipment_items','opening_inventory_lines','inventory_operation_lines','commercial_return_lines']));
        DB::unprepared("CREATE TRIGGER uom_mode_insert BEFORE INSERT ON uom_item_modes FOR EACH ROW BEGIN IF NEW.mode='MIXED_VERIFIED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='REVIEW_CERTIFICATE_REQUIRED'; END IF; IF NEW.mode='BASE_ONLY' AND ({$history}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='NEW_ITEM_ONLY'; END IF; END");
        DB::unprepared("CREATE TRIGGER uom_mode_update BEFORE UPDATE ON uom_item_modes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MODE_TRANSITION_REQUIRES_APPROVED_PROTOCOL'");
        DB::unprepared("CREATE TRIGGER uom_mode_delete BEFORE DELETE ON uom_item_modes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MODE_IMMUTABLE'");
        foreach(['inventory_lots','inventory_lot_movements','purchase_invoice_lines','sales_invoice_lines'] as $t)DB::unprepared("CREATE TRIGGER separation_update_{$t} BEFORE UPDATE ON {$t} FOR EACH ROW BEGIN DECLARE m VARCHAR(20); SELECT mode INTO m FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id; IF m IS NULL OR (m='BASE_ONLY' AND NEW.uom_version<>'BASE_V1') OR (m='LEGACY_ONLY' AND NEW.uom_version<>'LEGACY_KG') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITEM_MODE_VERSION_MISMATCH'; END IF; END");
        foreach(['purchase_invoice_lines','sales_invoice_lines'] as $t)DB::unprepared("CREATE TRIGGER separation_insert_{$t} BEFORE INSERT ON {$t} FOR EACH ROW BEGIN DECLARE m VARCHAR(20); SELECT mode INTO m FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id; IF m IS NULL OR (m='BASE_ONLY' AND NEW.uom_version<>'BASE_V1') OR (m='LEGACY_ONLY' AND NEW.uom_version<>'LEGACY_KG') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITEM_MODE_VERSION_MISMATCH'; END IF; END");
        foreach(['inventory_lots','inventory_lot_movements'] as $table)DB::unprepared("CREATE TRIGGER separation_{$table} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN DECLARE m VARCHAR(20); SELECT mode INTO m FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id; IF m IS NULL OR (m='BASE_ONLY' AND NEW.uom_version<>'BASE_V1') OR (m='LEGACY_ONLY' AND NEW.uom_version<>'LEGACY_KG') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITEM_MODE_VERSION_MISMATCH'; END IF; END");
        DB::unprepared("CREATE TRIGGER separation_stock BEFORE INSERT ON stock_movements FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id AND mode='BASE_ONLY') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='BASE_STOCK_MIRROR_PROTOCOL_REQUIRED'; END IF; END");
        DB::unprepared("CREATE TRIGGER separation_stock_update BEFORE UPDATE ON stock_movements FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM uom_item_modes WHERE company_id=NEW.company_id AND item_id=NEW.item_id AND mode='BASE_ONLY') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='BASE_STOCK_MIRROR_PROTOCOL_REQUIRED'; END IF; END");
        }
        // Explicit event identity, never source_id-only; canonical lot movement owns quantity/value.
        DB::unprepared("CREATE TABLE uom_proof_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,source_type VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,source_id BIGINT UNSIGNED NOT NULL,source_line_id BIGINT UNSIGNED NOT NULL,direction VARCHAR(3) NOT NULL,allocation_id BIGINT UNSIGNED NOT NULL,movement_id BIGINT UNSIGNED NOT NULL UNIQUE,UNIQUE(company_id,branch_id,item_id,source_type,source_id,source_line_id,direction,allocation_id),FOREIGN KEY(movement_id) REFERENCES inventory_lot_movements(id),CHECK(direction IN ('IN','OUT'))) ENGINE=InnoDB");
        DB::unprepared("CREATE TRIGGER proof_event_insert BEFORE INSERT ON uom_proof_events FOR EACH ROW BEGIN IF NOT EXISTS(SELECT 1 FROM inventory_lot_movements m JOIN inventory_lots l ON l.id=m.inventory_lot_id JOIN purchase_invoice_lines p ON p.id=l.purchase_invoice_line_id JOIN purchase_invoices i ON i.id=p.purchase_invoice_id WHERE m.id=NEW.movement_id AND m.company_id=NEW.company_id AND m.branch_id=NEW.branch_id AND m.item_id=NEW.item_id AND m.movement_type=NEW.direction AND m.source_type=NEW.source_type AND m.source_id=NEW.source_id AND l.id=NEW.allocation_id AND p.id=NEW.source_line_id AND p.company_id=NEW.company_id AND p.item_id=NEW.item_id AND i.id=NEW.source_id AND i.company_id=NEW.company_id AND i.branch_id=NEW.branch_id AND NEW.source_type='PURCHASE') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='EVENT_EVIDENCE_MISMATCH'; END IF; END");
        DB::unprepared("CREATE TABLE uom_proof_mirrors(stock_id BIGINT UNSIGNED PRIMARY KEY,event_id BIGINT UNSIGNED NOT NULL UNIQUE,FOREIGN KEY(stock_id) REFERENCES stock_movements(id),FOREIGN KEY(event_id) REFERENCES uom_proof_events(id)) ENGINE=InnoDB");
        DB::unprepared("CREATE TRIGGER proof_mirror_insert BEFORE INSERT ON uom_proof_mirrors FOR EACH ROW BEGIN IF NOT EXISTS(SELECT 1 FROM uom_proof_events e JOIN inventory_lot_movements m ON m.id=e.movement_id JOIN inventory_lots l ON l.id=m.inventory_lot_id JOIN stock_movements s ON s.id=NEW.stock_id JOIN uom_units u ON u.id=l.base_uom_id_snapshot WHERE e.id=NEW.event_id AND s.company_id=e.company_id AND s.branch_id=e.branch_id AND s.item_id=e.item_id AND s.inventory_lot_id=l.id AND BINARY s.source_type=BINARY e.source_type AND s.source_id=e.source_id AND BINARY s.movement_type=BINARY e.direction AND u.code='KG' AND u.dimension='MASS' AND s.qty_kg=m.base_quantity AND s.total_cost=m.total_cost) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIRROR_EVIDENCE_MISMATCH'; END IF; END");
        foreach(['uom_proof_events','uom_proof_mirrors'] as $t)foreach(['UPDATE','DELETE'] as $action)DB::unprepared("CREATE TRIGGER {$t}_{$action} BEFORE {$action} ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='EVENT_LINK_IMMUTABLE'");
        DB::unprepared("CREATE TRIGGER proof_stock_sealed BEFORE UPDATE ON stock_movements FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM uom_proof_mirrors WHERE stock_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='EVENT_EVIDENCE_IMMUTABLE'; END IF; END");
        DB::unprepared("CREATE TRIGGER proof_line_sealed BEFORE UPDATE ON purchase_invoice_lines FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM uom_proof_events WHERE source_line_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='EVENT_EVIDENCE_IMMUTABLE'; END IF; END");
        DB::unprepared("CREATE TRIGGER proof_lot_sealed BEFORE UPDATE ON inventory_lots FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM uom_proof_events WHERE allocation_id=OLD.id) AND (NOT(NEW.company_id<=>OLD.company_id) OR NOT(NEW.branch_id<=>OLD.branch_id) OR NOT(NEW.item_id<=>OLD.item_id) OR NOT(NEW.purchase_invoice_line_id<=>OLD.purchase_invoice_line_id) OR NOT(NEW.source_type<=>OLD.source_type) OR NOT(NEW.source_id<=>OLD.source_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='EVENT_EVIDENCE_IMMUTABLE'; END IF; END");
    }
    public static function readItems(int $company,array $branches,array $items):array
    {
        UomStageAProof::guard();$out=[];
        foreach(array_unique($items) as $item){
            $mode=DB::table('uom_item_modes')->where('company_id',$company)->where('item_id',$item)->value('mode');
            if(!$mode){$out[]=['item_id'=>$item,'status'=>'SCOPE_OR_MODE_NOT_FOUND'];continue;}
            try{$out[]=['item_id'=>$item,'mode'=>$mode,'status'=>'VERIFIED_LOT_READ','rows'=>(new UomUnifiedInventoryReader)->read($company,$branches,$item)];}
            catch(\RuntimeException $e){$out[]=['item_id'=>$item,'mode'=>$mode,'status'=>'REVIEW_REQUIRED','reason'=>$e->getMessage(),'quantity'=>null,'value'=>null];}
        }
        return $out; // No partial numeric grand total masquerading as complete stock value.
    }
    public static function transitionReasons(int $company,int $item):array
    {
        UomStageAProof::guard();$reasons=[];
        if(DB::table('stock_movements')->where('company_id',$company)->where('item_id',$item)->whereNull('inventory_lot_id')->exists())$reasons[]='UNLINKED_STOCK_MOVEMENTS';
        $reasons[]='APPROVED_FULL_HISTORY_CERTIFICATE_NOT_IMPLEMENTED';return $reasons;
    }
    public static function eventTotals(int $company,int $branch,int $item):array
    {
        UomStageAProof::guard();$rows=DB::table('uom_proof_events as e')->join('inventory_lot_movements as m','m.id','=','e.movement_id')->where('e.company_id',$company)->where('e.branch_id',$branch)->where('e.item_id',$item)->get(['e.direction','m.base_quantity','m.total_cost']);
        $q='0';$v='0';foreach($rows as $r){$q=$r->direction==='IN'?bcadd($q,$r->base_quantity,6):bcsub($q,$r->base_quantity,6);$v=$r->direction==='IN'?bcadd($v,$r->total_cost,6):bcsub($v,$r->total_cost,6);}return ['quantity'=>$q,'value'=>$v,'events'=>count($rows)];
    }
}
