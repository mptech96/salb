<?php
/** Approved local experiment only. Not in automatic production migrations. */
return new class extends \Illuminate\Database\Migrations\Migration {
    private function guard(): void
    {
        if (!class_exists(\Sulb\Release\ProductionUpgradeContext::class)) throw new \RuntimeException('Isolated FK migration only');
        \Sulb\Release\ProductionUpgradeContext::guard();
        \Sulb\Release\ProductionUpgradeDialect::assertSupported();
    }
    public function up(): void
    {
        \Sulb\Release\ProductionUpgradeContext::guard();
        $this->guard();
        $db=\Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (\Illuminate\Support\Facades\DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',$db)->where('TABLE_NAME','shipment_costs')->where('CONSTRAINT_NAME','fk_shipment_cost_parent')->exists()) throw new \RuntimeException('FK already exists; inspect instead of replacing');
        if (\Illuminate\Support\Facades\DB::table('shipment_costs as c')->leftJoin('shipments as s','s.id','=','c.shipment_id')->whereNotNull('c.shipment_id')->where(function($q){$q->whereNull('s.id')->orWhereColumn('c.company_id','<>','s.company_id');})->exists()) throw new \RuntimeException('Orphan or cross-company cost; no automatic history repair');
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE shipment_costs ADD CONSTRAINT fk_shipment_cost_parent FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
    }
    public function down(): void
    {
        throw new \RuntimeException('PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN');
        $this->guard();
        if(config('shipment_cost_read.enabled')) throw new \RuntimeException('Disable the fresh-read protocol before FK rollback');
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE shipment_costs DROP FOREIGN KEY fk_shipment_cost_parent');
    }
};
