<?php
/** Isolated/review ONLY: deliberately outside Laravel's automatic migration path. */
return new class extends \Illuminate\Database\Migrations\Migration {
    private function guard():void{
        \Tests\Support\UomStageAProof::guard();
        \Tests\Support\QaEngineSchema::assertSupported();
    }
    public function up():void{
        $this->guard();
        \Illuminate\Support\Facades\DB::unprepared("CREATE TABLE shipment_cost_allocation_receipts (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT NOT NULL,
          branch_id BIGINT UNSIGNED NOT NULL, shipment_id BIGINT UNSIGNED NOT NULL, shipment_cost_id BIGINT UNSIGNED NOT NULL,
          journal_entry_id BIGINT UNSIGNED NOT NULL, policy_version VARCHAR(32) NOT NULL,
          currency_code VARCHAR(10) NOT NULL, base_amount DECIMAL(18,3) NOT NULL, foreign_amount DECIMAL(18,3) NOT NULL,
          payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          posting_status VARCHAR(16) NOT NULL DEFAULT 'POSTED', created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
          UNIQUE KEY uq_cost_allocation(company_id,shipment_cost_id),
          CONSTRAINT scar_company FOREIGN KEY(company_id) REFERENCES companies(id),
          CONSTRAINT scar_branch FOREIGN KEY(branch_id) REFERENCES branches(id),
          CONSTRAINT scar_shipment FOREIGN KEY(shipment_id) REFERENCES shipments(id),
          CONSTRAINT scar_cost FOREIGN KEY(shipment_cost_id) REFERENCES shipment_costs(id),
          CONSTRAINT scar_journal FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id),
          CHECK(JSON_VALID(payload)), CHECK(base_amount>0), CHECK(foreign_amount>=0), CHECK(posting_status='POSTED')
        ) ENGINE=InnoDB");
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER scar_insert BEFORE INSERT ON shipment_cost_allocation_receipts FOR EACH ROW BEGIN
          IF NOT EXISTS(SELECT 1 FROM shipment_costs c JOIN shipments s ON s.id=c.shipment_id JOIN journal_entries j ON j.id=NEW.journal_entry_id
          WHERE c.id=NEW.shipment_cost_id AND c.company_id=NEW.company_id AND c.shipment_id=NEW.shipment_id AND c.branch_id=NEW.branch_id
          AND s.company_id=NEW.company_id AND s.branch_id=NEW.branch_id AND c.cost_status='POSTED' AND c.amount=NEW.base_amount
          AND c.journal_entry_id=j.id AND j.company_id=NEW.company_id AND j.branch_id=NEW.branch_id AND j.source_type='SHIPMENT_COST' AND j.source_id=c.id)
          OR SHA2(NEW.payload,256)<>NEW.payload_hash THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ALLOCATION_RECEIPT_SOURCE'; END IF;
        END");
        foreach(['UPDATE','DELETE'] as $event)\Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER scar_".strtolower($event)." BEFORE $event ON shipment_cost_allocation_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ALLOCATION_RECEIPT_IMMUTABLE'");
    }
    public function down():void{
        $this->guard();
        if(\Illuminate\Support\Facades\DB::table('shipment_cost_allocation_receipts')->exists())throw new \RuntimeException('ALLOCATION_USED_ROLLBACK_FORBIDDEN');
        \Illuminate\Support\Facades\Schema::drop('shipment_cost_allocation_receipts');
    }
};
