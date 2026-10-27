<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;

/** Empty schema rehearsal only; never accepts a production connection or row dump. */
final class PrivateSchemaMigrationRehearsalTest extends \Tests\TestCase
{
    public function test_private_empty_schema_accepts_ordered_fourteen_without_production_access():void
    {
        foreach(['UomStageAProof','QaEngineSchema']as$name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();\Tests\Support\QaEngineSchema::assertSupported();
        require_once base_path('../ops/sulb-rc/private-migration-contract.php');
        $file=getenv('SULB_PRIVATE_SCHEMA_FILE');$hash=getenv('SULB_PRIVATE_SCHEMA_SHA256');
        if(!$file||!$hash||!is_file($file)||!hash_equals($hash,hash_file('sha256',$file)))throw new \RuntimeException('PINNED_PRIVATE_SCHEMA_REQUIRED');
        $capture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        $contract=privateMigrationContract($capture,dirname(base_path()));
        self::assertSame('PASS',$contract['static_prerequisites']);
        // The outer owned runner created this EMPTY database. Never drop existing tables.
        self::assertSame([],DB::select('SHOW TABLES'));
        $progress=function(string $step):void{$file=getenv('SULB_SCHEMA_PROGRESS_FILE');if($file)file_put_contents($file,json_encode(['step'=>$step],JSON_THROW_ON_ERROR),LOCK_EX);};
        $progress('schema_import');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try{foreach($capture['definitions']as$o){
            $row=$o['ddl'][0];$sql=array_values(array_filter($row,fn($v,$k)=>str_starts_with(strtolower((string)$k),'create '),ARRAY_FILTER_USE_BOTH))[0];
            if(preg_match('/\bREFERENCES\s+`?\w+`?\s*\.\s*`?\w+/i',$sql)||preg_match('/\b(DATA DIRECTORY|INDEX DIRECTORY|CONNECTION)\s*=/i',$sql))throw new \RuntimeException('EXTERNAL_SCHEMA_REFERENCE_FORBIDDEN');
            DB::unprepared($sql);
        }}finally{DB::statement('SET FOREIGN_KEY_CHECKS=1');}
        foreach($capture['definitions']as$o)self::assertSame(0,DB::table($o['name'])->count());
        config(['uom.schema_install_approved'=>true]);$applied=0;
        require_once base_path('../ops/sulb-rc/private-rehearsal-plan.php');
        foreach(privateRehearsalPlan(dirname(base_path()))as$step){
            $progress($step['step']);
            (require base_path('../'.$step['path']))->up();
            if(str_starts_with($step['step'],'uom:'))$applied++;
        }
        $progress('schema_assertions');
        self::assertSame(14,$applied);self::assertSame(1,(int)DB::selectOne('SELECT @@foreign_key_checks n')->n);
        foreach(['uom_processing_cost_sources','uom_inventory_adjustments','inventory_lot_value_balances','shipment_cost_allocation_receipts']as$t)self::assertTrue(DB::getSchemaBuilder()->hasTable($t));
        foreach($capture['definitions']as$o)self::assertTrue(DB::getSchemaBuilder()->hasTable($o['name']));
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
        $progress('complete');
        // This test intentionally provides no historical/backfill/security/UAT approval.
    }
}
