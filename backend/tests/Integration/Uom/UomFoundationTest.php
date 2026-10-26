<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\Uom\ItemUnitService as U;
use App\Services\Uom\DecimalQuantity as D;
require_once __DIR__.'/UomStageASchemaTest.php';
class UomFoundationTest extends UomStageASchemaTest
{
    private function rejected(callable $f,string $msg):void{try{$f();self::fail('Expected rejection');}catch(\RuntimeException|\InvalidArgumentException $e){self::assertStringContainsString($msg,$e->getMessage());}}
    public function test_real_migration_and_services_without_stock_activation():void
    {
        // Parent creates the prior proof schema. Remove only that fixture in the guarded test database,
        // then reload the checked-in legacy schema to exercise the actual migration from scratch.
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row){$t=array_values((array)$row)[0];DB::unprepared('DROP TABLE `'.$t.'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');DB::unprepared(file_get_contents(base_path('database/schema/mysql-schema.sql')));
        $m=require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php');config(['uom.schema_install_approved'=>false]);$this->rejected(fn()=>$m->up(),'explicit approval');config(['uom.schema_install_approved'=>true]);$m->up();
        DB::table('companies')->insert(['id'=>1,'company_name'=>'Isolated']);DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'Isolated']);DB::table('currencies')->insert(['currency_code'=>'SAR','currency_name'=>'SAR']);DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'SAR','is_active'=>1]);
        foreach([[1,'PCS','COUNT','1',0,'STANDARD'],[2,'CARTON','COUNT',null,1,'ITEM_PACKAGING'],[3,'KG','MASS','1',1,'STANDARD'],[4,'TON','MASS','1000',1,'STANDARD'],[5,'M','LENGTH','1',1,'STANDARD'],[6,'L','VOLUME','1',1,'STANDARD'],[7,'ML','VOLUME','0.001',1,'STANDARD']] as [$id,$code,$dimension,$factor,$frac,$kind])DB::table('uom_units')->insert(['id'=>$id,'code'=>$code,'name_ar'=>$code,'dimension'=>$dimension,'standard_factor'=>$factor,'allow_fractional'=>$frac,'kind'=>$kind]);
        foreach([1,2,3,4,5,10] as $id)DB::table('items')->insert(['id'=>$id,'company_id'=>1,'item_code'=>'F-'.$id,'item_name'=>'Fixture']);
        require_once __DIR__.'/../../Support/UomActorFixture.php';\Tests\Support\UomActorFixture::login();
        $service=new U;$service->registerNew(1,1,1);$service->registerNew(1,2,1);$a=$service->reviseMapping(1,1,2,'24');$b=$service->reviseMapping(1,2,2,'12');$pa=$service->revisePrice(1,1,$a,'SAR','240');$pb=$service->revisePrice(1,2,$b,'SAR','120');
        $saved=$service->snapshot(1,1,$a,$pa,'3');self::assertSame('72.000000',$saved['base_quantity']);self::assertSame('10.000000',$saved['price_per_base']);self::assertSame('36.000000',$service->snapshot(1,2,$b,$pb,'3')['base_quantity']);
        $a2=$service->reviseMapping(1,1,2,'12');$p2=$service->revisePrice(1,1,$a2,'SAR','264');self::assertSame('24.000000',$service->snapshot(1,1,$a,$pa,'3')['factor']);self::assertSame('264.000000',$service->snapshot(1,1,$a2,$p2,'3')['unit_price']);
        $this->rejected(fn()=>$service->reviseMapping(2,1,2,'24'),'AUTH_COMPANY');$this->rejected(fn()=>$service->reviseMapping(1,1,3,'1'),'DIMENSION');
        $this->rejected(fn()=>DB::table('inventory_lots')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'lot_number'=>'FORBIDDEN','received_at'=>'2026-01-01','source_type'=>'TEST','qty_received_kg'=>'72','qty_remaining_kg'=>'72']),'NOT_APPROVED');
        $this->rejected(fn()=>DB::table('items')->where('id',1)->update(['base_uom_id'=>3]),'IMMUTABLE');
        // Two real connections: item-definition lock blocks the first legacy stock write.
        $cfg=DB::connection()->getConfig();$pdo=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.$cfg['database'],'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
        DB::beginTransaction();DB::table('items')->where('id',10)->lockForUpdate()->first();
        try{$pdo->exec("INSERT INTO stock_movements(company_id,branch_id,item_id,movement_type,source_type,movement_date,qty) VALUES(1,1,10,'IN','RACE','2026-01-01',1)");self::fail('Must wait on item lock');}catch(\PDOException $e){self::assertSame(1205,$e->errorInfo[1]);}finally{DB::rollBack();}
        self::assertSame(0,DB::table('stock_movements')->where('source_type','RACE')->count());
        config(['uom.operational_writes_enabled'=>true]);$this->rejected(fn()=>(new \App\Services\Uom\OperationalWriteGate)->assertWritable(),'NOT_APPROVED');
        $legacy=app(\App\Services\InventoryLotService::class);$legacy->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'qty_kg'=>'100','base_cost'=>'200','lot_number'=>'LEGACY','source_type'=>'OPENING','received_at'=>'2026-01-01']);
        self::assertSame('100.000000',(new \App\Services\Uom\UnifiedInventoryReader)->read(1,[1],10)[0]['quantity']);$this->rejected(fn()=>$service->registerNew(1,10,3),'NEW_ITEM');
        $this->rejected(fn()=>$m->down(),'No automatic');
    }
    public function test_actual_decimal_service_precision():void
    {
        self::assertSame('1000.000000',D::convert('1','1000',true));self::assertSame('0.250000',D::convert('250','0.001',true));self::assertSame('12.500000',D::convert('12.5','1',true));
        $this->rejected(fn()=>D::convert('0.000001','0.001',true),'INEXACT');$this->rejected(fn()=>D::convert('0.5','1',false),'INDIVISIBLE');$this->rejected(fn()=>D::convert('1000000000000','1',true),'OVERFLOW');
    }
    public function test_production_reader_on_existing_base_fixture():void
    {
        require_once __DIR__.'/../../Support/UomActorFixture.php';\Tests\Support\UomActorFixture::login();
        $map=DB::table('item_uom_mappings')->insertGetId(['company_id'=>1,'item_id'=>1,'unit_id'=>1,'base_unit_id'=>1,'revision'=>1,'factor_to_base'=>'1']);
        $lot=DB::table('inventory_lots')->insertGetId(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'lot_number'=>'REAL-READER','source_type'=>'TEST','received_at'=>'2026-01-01','uom_version'=>'BASE_V1','uom_company_id'=>1,'uom_mapping_id'=>$map,'base_uom_id_snapshot'=>1,'received_base_qty'=>'72','remaining_base_qty'=>'72','sold_base_qty'=>'0','unit_cost_per_base'=>'10','base_cost'=>'720','total_cost'=>'720']);
        DB::table('inventory_lot_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'inventory_lot_id'=>$lot,'movement_type'=>'IN','source_type'=>'TEST','movement_at'=>'2026-01-01','qty_kg'=>'0','uom_version'=>'BASE_V1','uom_company_id'=>1,'uom_mapping_id'=>$map,'base_uom_id_snapshot'=>1,'base_quantity'=>'72','unit_cost_per_base'=>'10','total_cost'=>'720']);
        $r=(new \App\Services\Uom\UnifiedInventoryReader)->read(1,[1],1)[0];self::assertSame('72.000000',$r['quantity']);self::assertSame('720.000',$r['value']);
    }
}
