<?php
use Illuminate\Support\Facades\DB;
return new class {
    public function up():void
    {
        \Sulb\Release\ProductionUpgradeContext::guard();
        \Sulb\Release\ProductionUpgradeContext::guard();
        if(!config('uom.schema_install_approved')||!DB::getSchemaBuilder()->hasTable('uom_processing_commands'))throw new RuntimeException('UOM_PROCESS_COST_SCHEMA_APPROVAL_REQUIRED');
        if(!DB::getSchemaBuilder()->hasTable('uom_processing_cost_sources'))DB::unprepared("CREATE TABLE uom_processing_cost_sources (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT NOT NULL,
            operation_id BIGINT NOT NULL, source_reference VARCHAR(160) NOT NULL,
            base_amount DECIMAL(18,3) NOT NULL, payload_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_process_cost_source(company_id,source_reference), CHECK(base_amount>0)
        ) ENGINE=InnoDB");
        foreach(['company_id'=>'bigint(20)','operation_id'=>'bigint(20)','source_reference'=>'varchar(160)','base_amount'=>'decimal(18,3)','payload_hash'=>'char(64)'] as $column=>$expected){$row=DB::selectOne('SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['uom_processing_cost_sources',$column]);if(!$row||\Sulb\Release\ProductionUpgradeDialect::columnType($row->t)!==\Sulb\Release\ProductionUpgradeDialect::columnType($expected))throw new RuntimeException('UOM_PROCESS_COST_PARTIAL_SCHEMA');}
        $index=DB::select("SHOW INDEX FROM uom_processing_cost_sources WHERE Key_name='uq_process_cost_source'");if(count($index)!==2||array_column($index,'Column_name')!==['company_id','source_reference']||$index[0]->Non_unique)throw new RuntimeException('UOM_PROCESS_COST_SOURCE_INDEX_REQUIRED');
        foreach(['UPDATE','DELETE'] as $action){$name='uom_process_cost_source_'.strtolower($action);\Sulb\Release\ProductionUpgradeDialect::trigger("CREATE OR REPLACE TRIGGER `$name` BEFORE $action ON uom_processing_cost_sources FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_PROCESS_COST_SOURCE_IMMUTABLE'");}
    }
    public function down():void {
        throw new \RuntimeException('PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN');throw new RuntimeException('Preserve processing cost source claims');}
};
