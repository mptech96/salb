<?php
/** Additive local experiment only; no historical initialization or automatic migration. */
return new class extends \Illuminate\Database\Migrations\Migration {
    private function guard():void {
        if(!class_exists(\Tests\Support\UomStageAProof::class))throw new \RuntimeException('Isolated LOT value migration only');
        \Tests\Support\UomStageAProof::guard();
        \Tests\Support\QaEngineSchema::assertSupported();
    }
    public function up():void {
        $this->guard();
        \Illuminate\Support\Facades\DB::unprepared("CREATE TABLE inventory_lot_value_balances (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT NOT NULL, inventory_lot_id BIGINT UNSIGNED NOT NULL,
            original_quantity DECIMAL(18,3) NOT NULL, remaining_quantity DECIMAL(18,3) NOT NULL,
            original_value DECIMAL(18,3) NOT NULL, remaining_value DECIMAL(18,3) NOT NULL,
            policy_version VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_lot_value(company_id,inventory_lot_id),
            FOREIGN KEY (company_id) REFERENCES companies(id), FOREIGN KEY (inventory_lot_id) REFERENCES inventory_lots(id),
            CHECK(original_quantity>0), CHECK(remaining_quantity>=0 AND remaining_quantity<=original_quantity),
            CHECK(original_value>=0), CHECK(remaining_value>=0 AND remaining_value<=original_value),
            CHECK(remaining_quantity>0 OR remaining_value=0)
        ) ENGINE=InnoDB");
    }
    public function down():void {
        $this->guard();
        if(config('inventory_value.enabled')||\Illuminate\Support\Facades\DB::table('inventory_lot_value_balances')->exists())throw new \RuntimeException('LOT_VALUE_USED_ROLLBACK_FORBIDDEN');
        \Illuminate\Support\Facades\Schema::drop('inventory_lot_value_balances');
    }
};
