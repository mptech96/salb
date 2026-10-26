<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up():void
    {
        \Tests\Support\UomStageAProof::guard();
        if(!config('uom.schema_install_approved')||!Schema::hasTable('uom_operational_schema'))throw new RuntimeException('UOM_SCHEMA_ORDER');
        if(!Schema::hasTable('uom_processing_recipes'))DB::unprepared("CREATE TABLE uom_processing_recipes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,recipe_code VARCHAR(80) NOT NULL,revision INT NOT NULL,payload LONGTEXT NOT NULL,payload_hash CHAR(64) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'DRAFT',created_by BIGINT NOT NULL,approved_by BIGINT NULL,approved_at DATETIME NULL,created_at DATETIME NOT NULL, UNIQUE KEY uq_recipe_revision(company_id,branch_id,recipe_code,revision), CHECK(status IN ('DRAFT','APPROVED')),CHECK(revision>0),FOREIGN KEY(company_id) REFERENCES companies(id),FOREIGN KEY(branch_id) REFERENCES branches(id)) ENGINE=InnoDB");
        foreach(['company_id','branch_id','recipe_code','revision','payload','payload_hash','status','approved_by','approved_at'] as $column)if(!Schema::hasColumn('uom_processing_recipes',$column))throw new RuntimeException('UOM_PARTIAL_RECIPE_SCHEMA');
        DB::unprepared("CREATE OR REPLACE TRIGGER uom_recipe_immutable BEFORE UPDATE ON uom_processing_recipes FOR EACH ROW BEGIN IF OLD.status='APPROVED' OR NEW.company_id<>OLD.company_id OR NEW.branch_id<>OLD.branch_id OR NEW.payload<>OLD.payload OR NEW.payload_hash<>OLD.payload_hash OR NEW.recipe_code<>OLD.recipe_code OR NEW.revision<>OLD.revision THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_APPROVED_RECIPE_IMMUTABLE'; END IF; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER uom_recipe_preserve BEFORE DELETE ON uom_processing_recipes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_RECIPE_HISTORY_PRESERVED'");
    }
    public function down():void {throw new RuntimeException('Preserve recipe approval history');}
};
