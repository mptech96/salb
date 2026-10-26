<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\UomStageAProof as P;

class UomStageASchemaTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/UomStageAProof.php';require_once __DIR__.'/../../Support/UomDesignDecimalProof.php';P::guard();
        // Exact invocation's disposable fixture only; no main DB and no migrate:fresh.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row){$table=array_values((array)$row)[0];DB::unprepared('DROP TABLE `'.str_replace('`','``',$table).'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $sql=file_get_contents(base_path('database/schema/mysql-schema.sql'));if(preg_match('/^\s*(USE |CREATE DATABASE|DROP DATABASE)/im',$sql))throw new \RuntimeException('Cross DB prohibited');preg_match_all('/^INSERT INTO\s+`([^`]+)`/m',$sql,$m);if(array_diff(array_unique($m[1]),['migrations']))throw new \RuntimeException('Business dump prohibited');DB::unprepared($sql);
        DB::table('companies')->insert([['id'=>1,'company_name'=>'UOM synthetic only'],['id'=>2,'company_name'=>'Foreign synthetic only']]);DB::table('branches')->insert([['id'=>1,'company_id'=>1,'branch_name'=>'Test'],['id'=>2,'company_id'=>2,'branch_name'=>'Foreign']]);
        DB::table('currencies')->insert(['currency_code'=>'SAR','currency_name'=>'Test SAR','is_active'=>1]);DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'SAR','is_active'=>1,'is_base'=>1]);
        DB::table('items')->insert(['id'=>10,'company_id'=>1,'item_name'=>'Legacy weight','item_code'=>'LEGACY','base_unit_code'=>'KG','commercial_unit_code'=>'TON','commercial_to_base_factor'=>1000]);
        app(\App\Services\InventoryLotService::class)->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'qty_kg'=>100,'base_cost'=>200,'source_type'=>'OPENING','received_at'=>'2026-01-01','lot_number'=>'LEGACY-100']);
        $this->legacyBefore=DB::table('inventory_lots')->first();P::install();
        foreach([[1,'PCS','COUNT','1',0,'STANDARD'],[2,'CARTON','COUNT',null,1,'ITEM_PACKAGING'],[3,'KG','MASS','1',1,'STANDARD'],[4,'TON','MASS','1000',1,'STANDARD'],[5,'M','LENGTH','1',1,'STANDARD'],[6,'L','VOLUME','1',1,'STANDARD'],[7,'ML','VOLUME','0.001',1,'STANDARD']] as [$id,$code,$dimension,$factor,$fractional,$kind])DB::table('uom_units')->insert(['id'=>$id,'code'=>$code,'name_ar'=>$code,'dimension'=>$dimension,'standard_factor'=>$factor,'allow_fractional'=>$fractional,'kind'=>$kind]);
        foreach([[1,'A',1],[2,'B',1],[3,'Metre',5],[4,'Litre',6]] as [$id,$name,$base])DB::table('items')->insert(['id'=>$id,'company_id'=>1,'item_name'=>$name,'item_code'=>'UOM-'.$id,'base_uom_id'=>$base,'base_unit_code'=>$name==='Metre'?'M':($name==='Litre'?'L':'PCS'),'inventory_quantity_version'=>'BASE_V1']);
        DB::table('suppliers')->insert(['id'=>1,'company_id'=>1,'supplier_name'=>'Test supplier']);DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'Test customer']);
        DB::table('purchase_invoices')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'supplier_id'=>1,'invoice_number'=>'UOM-P','invoice_date'=>'2026-03-01']);DB::table('sales_invoices')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'customer_id'=>1,'invoice_number'=>'UOM-S','invoice_date'=>'2026-03-01']);
    }
    private object $legacyBefore;
    private function reject(callable $f,string $expected=''):void{try{$f();self::fail('Expected schema rejection');}catch(\Illuminate\Database\QueryException|\RuntimeException|\InvalidArgumentException $e){if($expected)self::assertStringContainsString($expected,$e->getMessage());else self::assertNotEmpty($e->getMessage());}}
    private function mapping(int $item,int $unit,int $base,string $factor,int $revision=1):int{return DB::table('item_uom_mappings')->insertGetId(['company_id'=>1,'item_id'=>$item,'unit_id'=>$unit,'base_unit_id'=>$base,'factor_to_base'=>$factor,'revision'=>$revision]);}
    private function price(int $map,string $value='240',int $rev=1):int{return DB::table('item_uom_prices')->insertGetId(['company_id'=>1,'mapping_id'=>$map,'currency_code'=>'SAR','revision'=>$rev,'purchase_price'=>$value,'sale_price'=>$value]);}
    private function line(int $map,int $price,string $qty,string $factor,string $base,int $item=1,string $unit='CARTON',int $baseUnit=1):array{return ['company_id'=>1,'purchase_invoice_id'=>1,'item_id'=>$item,'quantity'=>$qty,'unit_code'=>$unit,'unit_factor_to_base'=>$factor,'qty_kg'=>0,'unit_price'=>0,'uom_version'=>'BASE_V1','uom_mapping_id'=>$map,'base_uom_id_snapshot'=>$baseUnit,'base_quantity'=>$base,'uom_price_revision_id'=>$price,'uom_unit_price_snapshot'=>'240'];}
    private function lot(int $item,int $map,int $base,string $qty,string $value='720',int $branch=1):int{return DB::table('inventory_lots')->insertGetId(['company_id'=>1,'branch_id'=>$branch,'item_id'=>$item,'lot_number'=>'BASE-'.bin2hex(random_bytes(5)),'received_at'=>'2026-03-01','source_type'=>'UOM_TEST','uom_version'=>'BASE_V1','uom_company_id'=>1,'uom_mapping_id'=>$map,'base_uom_id_snapshot'=>$base,'received_base_qty'=>$qty,'remaining_base_qty'=>$qty,'sold_base_qty'=>0,'unit_cost_per_base'=>bcdiv($value,$qty,6),'base_cost'=>$value,'allocated_cost'=>0,'total_cost'=>$value,'qty_received_kg'=>0,'qty_remaining_kg'=>0,'qty_sold_kg'=>0]);}
    public function test_carton_revisions_snapshots_and_prices_are_immutable():void
    {
        $a=$this->mapping(1,2,1,'24');$b=$this->mapping(2,2,1,'12');$pa=$this->price($a);$pb=$this->price($b,'120');
        $id=DB::table('purchase_invoice_lines')->insertGetId($this->line($a,$pa,'3','24','72'));$row=$this->line($b,$pb,'3','12','36',2);$row['uom_unit_price_snapshot']='120';DB::table('purchase_invoice_lines')->insert($row);
        self::assertSame('72.000000',DB::table('purchase_invoice_lines')->where('id',$id)->value('base_quantity'));
        $this->reject(fn()=>DB::table('item_uom_mappings')->where('id',$a)->update(['factor_to_base'=>12]),'APPEND_REVISION');$this->reject(fn()=>DB::table('item_uom_prices')->where('id',$pa)->update(['purchase_price'=>264]),'APPEND_REVISION');
        $new=$this->mapping(1,2,1,'12',2);$this->price($new,'264');self::assertSame('24.000000',DB::table('purchase_invoice_lines')->where('id',$id)->value('unit_factor_to_base'));
        $this->reject(fn()=>DB::table('purchase_invoice_lines')->where('id',$id)->update(['unit_factor_to_base'=>12]),'SNAPSHOT_IMMUTABLE');$this->reject(fn()=>DB::table('purchase_invoices')->where('id',1)->delete(),'EVIDENCE_RESTRICT');
    }
    public function test_old_stock_unchanged_and_no_double_quantity():void
    {
        foreach((array)$this->legacyBefore as $key=>$value)self::assertEquals($value,DB::table('inventory_lots')->first()->$key,'Legacy '.$key);
        $map=$this->mapping(10,3,3,'1');$this->lot(10,$map,3,'20','40');self::assertSame('120.000000',P::quantity(1,1,10));
        self::assertEquals(100,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertEquals(240,DB::table('inventory_lots')->sum('total_cost'));
        // Actual old reader ignores the new quantity/value: characterize, never call this PASS compatibility.
        $old=app(\App\Services\InventoryLotService::class)->summary(1,1)->first();self::assertEquals(100,$old->balance_kg);self::assertEquals(200,$old->stock_value);
        $this->reject(fn()=>P::legacyReaderGuard(1,1),'unsupported');$this->reject(fn()=>P::rollbackPreflight(),'evidence exists');
    }
    public function test_real_legacy_fifo_still_consumes_kg_and_original_cost():void
    {
        $r=DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,10,10,'SALE',123));self::assertEquals(20,$r['total_cost']);self::assertEquals(90,DB::table('inventory_lots')->value('qty_remaining_kg'));self::assertEquals(2,DB::table('inventory_lots')->value('unit_cost_per_kg'));self::assertSame('LEGACY_KG',DB::table('inventory_lots')->value('uom_version'));
    }
    public function test_units_decimal_precision_and_scope_rejections():void
    {
        $m=$this->mapping(3,5,5,'1');$l=$this->mapping(4,6,6,'1');$ml=$this->mapping(4,7,6,'0.001');$this->lot(3,$m,5,'12.5','100');$this->lot(4,$l,6,'2','20');self::assertSame('12.500000',P::quantity(1,1,3));self::assertSame('2.000000',P::quantity(1,1,4));
        self::assertSame('0.250000',\Tests\Support\UomDesignDecimalProof::quantity('250','0.001'));
        $this->reject(fn()=>$this->mapping(1,3,1,'1'),'DIMENSION');$this->reject(fn()=>$this->mapping(10,4,3,'24'),'STANDARD_FACTOR');$this->reject(fn()=>$this->mapping(1,2,1,'0'));
        $count=$this->mapping(1,1,1,'1');$this->reject(fn()=>$this->lot(1,$count,1,'0.5'),'FRACTION');$this->reject(fn()=>$this->lot(1,$count,1,'1','10',2));
        $this->reject(fn()=>\Tests\Support\UomDesignDecimalProof::quantity('0.000001','0.001'),'precision');$this->reject(fn()=>$this->lot(3,$m,5,'1000000000000000000','10'));
        $this->reject(fn()=>DB::table('items')->where('id',3)->update(['base_uom_id'=>6,'base_unit_code'=>'L']),'BASE_IMMUTABLE');
    }
    public function test_schema_keys_nulls_rerun_and_rollback_preflight():void
    {
        $map=$this->mapping(1,2,1,'24');$p=$this->price($map);$this->reject(fn()=>$this->mapping(1,2,1,'24'));$this->reject(fn()=>$this->price($map));
        $this->reject(fn()=>P::install(),'already exists');P::rollbackPreflight();self::assertTrue(true);
        $row=$this->line($map,$p,'3','24','72');$row['quantity']=null;$this->reject(fn()=>DB::table('purchase_invoice_lines')->insert($row));
        $row=$this->line($map,$p,'3','24','71');$this->reject(fn()=>DB::table('purchase_invoice_lines')->insert($row));
        $row=$this->line($map,$p,'3','24','72');$row['qty_kg']=72;$this->reject(fn()=>DB::table('purchase_invoice_lines')->insert($row));
        $row=$this->line($map,$p,'3','24','72');$row['company_id']=2;$this->reject(fn()=>DB::table('purchase_invoice_lines')->insert($row));
        $cols=DB::select("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='item_uom_mappings' AND COLUMN_NAME='company_id') OR (TABLE_NAME='inventory_lots' AND COLUMN_NAME IN ('uom_company_id','remaining_base_qty')))");self::assertCount(3,$cols);
        $types=array_column($cols,'COLUMN_TYPE','COLUMN_NAME');self::assertSame('decimal(24,6)',$types['remaining_base_qty']);self::assertStringNotContainsString('unsigned',$types['uom_company_id']);
    }
    public function test_sales_heads_ton_currency_and_direct_precision_guards():void
    {
        $map=$this->mapping(1,2,1,'24');$price=$this->price($map);
        DB::table('item_uom_heads')->insert(['company_id'=>1,'item_id'=>1,'unit_id'=>2,'revision'=>1]);
        $this->reject(fn()=>DB::table('item_uom_heads')->insert(['company_id'=>1,'item_id'=>1,'unit_id'=>2,'revision'=>1]));
        $row=$this->line($map,$price,'3','24','72');unset($row['purchase_invoice_id']);$row['sales_invoice_id']=1;
        $id=DB::table('sales_invoice_lines')->insertGetId($row);self::assertSame('72.000000',DB::table('sales_invoice_lines')->where('id',$id)->value('base_quantity'));
        $this->reject(fn()=>DB::table('sales_invoice_lines')->where('id',$id)->delete());
        $this->reject(fn()=>DB::table('uom_units')->where('id',2)->update(['name_ar'=>'changed']),'IMMUTABLE');
        $ton=$this->mapping(10,4,3,'1000');self::assertSame('1000.000000',DB::table('item_uom_mappings')->where('id',$ton)->value('factor_to_base'));
        $this->reject(fn()=>DB::table('item_uom_prices')->insert(['company_id'=>1,'mapping_id'=>$map,'currency_code'=>'sar','revision'=>2,'sale_price'=>'1']),'CURRENCY_SCOPE');
        $this->reject(fn()=>DB::table('inventory_lots')->where('item_id',10)->update(['uom_version'=>'BASE_V1']),'NO_HISTORICAL_RETAG');
        $row=$this->line($map,$price,'0.000001','24','0.000024');$this->reject(fn()=>DB::table('purchase_invoice_lines')->insert($row),'SNAPSHOT_MISMATCH');
    }
}
