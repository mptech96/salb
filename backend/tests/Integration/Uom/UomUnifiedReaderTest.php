<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\UomUnifiedInventoryReader as R;

require_once __DIR__.'/UomStageASchemaTest.php';
/** Reuses owned schema fixtures and retains Stage A regression tests. */
class UomUnifiedReaderTest extends UomStageASchemaTest
{
    protected function setUp():void{parent::setUp();require_once __DIR__.'/../../Support/UomUnifiedInventoryReader.php';}
    private function baseLot(int $item,int $unit,string $qty,string $value,int $branch=1,?int $source=null):int
    {
        $map=DB::table('item_uom_mappings')->where('item_id',$item)->where('unit_id',$unit)->value('id');
        if(!$map)$map=DB::table('item_uom_mappings')->insertGetId(['company_id'=>1,'item_id'=>$item,'unit_id'=>$unit,'base_unit_id'=>$unit,'revision'=>1,'factor_to_base'=>'1']);
        $lot=DB::table('inventory_lots')->insertGetId(['company_id'=>1,'branch_id'=>$branch,'item_id'=>$item,'lot_number'=>'READ-'.bin2hex(random_bytes(4)),'received_at'=>'2026-03-01','source_type'=>'UOM_TEST','source_id'=>$source,'uom_version'=>'BASE_V1','uom_company_id'=>1,'uom_mapping_id'=>$map,'base_uom_id_snapshot'=>$unit,'received_base_qty'=>$qty,'remaining_base_qty'=>$qty,'sold_base_qty'=>'0','unit_cost_per_base'=>bcdiv($value,$qty,6),'base_cost'=>$value,'allocated_cost'=>'0','total_cost'=>$value]);
        $this->baseMovement($lot,'IN',$qty,$value,$source??$lot);
        return $lot;
    }
    private function baseMovement(int $id,string $direction,string $qty,string $value,int $source):void
    {
        $l=DB::table('inventory_lots')->where('id',$id)->first();
        DB::table('inventory_lot_movements')->insert(['company_id'=>$l->company_id,'branch_id'=>$l->branch_id,'item_id'=>$l->item_id,'inventory_lot_id'=>$id,'movement_type'=>$direction,'source_type'=>'UOM_TEST','source_id'=>$source,'movement_at'=>'2026-03-02','qty_kg'=>'0','total_cost'=>$value,'uom_version'=>'BASE_V1','uom_company_id'=>$l->company_id,'base_quantity'=>$qty,'base_uom_id_snapshot'=>$l->base_uom_id_snapshot,'uom_mapping_id'=>$l->uom_mapping_id,'unit_cost_per_base'=>$l->unit_cost_per_base]);
    }
    private function denied(callable $f,string $message):void
    {try{$f();self::fail('Must reject');}catch(\RuntimeException $e){self::assertStringContainsString($message,$e->getMessage());}}
    public function test_mixed_weight_lots_and_movements_are_counted_once():void
    {
        $lot=$this->baseLot(10,3,'20','40');$row=(new R)->read(1,[1],10)[0];
        self::assertSame('120.000000',$row['quantity']);self::assertSame('240.000',$row['value']);self::assertSame('KG',$row['unit']);self::assertSame('2.000000',$row['average_unit_cost']);self::assertSame('MATCHED',$row['valuation_status']);self::assertCount(2,$row['lots']);self::assertNull($row['reserved_quantity']);
        // Mirror stock movement is not a second receipt in the lot read model.
        DB::table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'inventory_lot_id'=>$lot,'movement_type'=>'IN','source_type'=>'UOM_TEST','source_id'=>$lot,'movement_date'=>'2026-03-01','qty_kg'=>'20','total_cost'=>'40']);
        self::assertSame('120.000000',(new R)->read(1,[1],10)[0]['quantity']);
        $this->denied(fn()=>(new R)->guardLegacy(1,[1]),'UOM_READER_REQUIRED');
    }
    public function test_piece_litre_metre_and_multiple_lot_costs_stay_separate():void
    {
        $this->baseLot(1,1,'72','720');$this->baseLot(1,1,'12','180');$this->baseLot(3,5,'12.5','100');$this->baseLot(4,6,'2','20');
        $rows=(new R)->read(1,[1]);$items=array_column($rows,null,'item_id');
        self::assertSame('84.000000',$items[1]['quantity']);self::assertSame('900.000',$items[1]['value']);self::assertSame('10.714285',$items[1]['average_unit_cost']);self::assertSame('PCS',$items[1]['unit']);
        self::assertSame('12.500000',$items[3]['quantity']);self::assertSame('M',$items[3]['unit']);self::assertSame('100.000',$items[3]['value']);self::assertSame('2.000000',$items[4]['quantity']);self::assertSame('L',$items[4]['unit']);self::assertSame('20.000',$items[4]['value']);self::assertCount(4,$rows);
    }
    public function test_reading_synthetic_sale_return_movements_reconciles_remaining():void
    {
        $id=$this->baseLot(1,1,'72','720');$this->baseMovement($id,'OUT','5','50',500);$this->baseMovement($id,'IN','2','20',501);
        DB::table('inventory_lots')->where('id',$id)->update(['remaining_base_qty'=>'69','sold_base_qty'=>'3']);
        $row=(new R)->read(1,[1],1)[0];self::assertSame('69.000000',$row['quantity']);self::assertSame('690.000',$row['value']);self::assertSame('690.000000',$row['movement_value']);self::assertSame('MATCHED',$row['valuation_status']);
        // Not a generic operational sale/return test: only read reconciliation of committed synthetic evidence.
        DB::table('inventory_lots')->where('id',$id)->update(['remaining_base_qty'=>'68']);$this->denied(fn()=>(new R)->read(1,[1],1),'Unreconciled');
    }
    public function test_branch_company_scope_and_legacy_off_path():void
    {
        (new R)->guardLegacy(1,[1]);self::assertSame('100.000000',(new R)->read(1,[1],10)[0]['quantity']);
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'Other permitted']);$this->baseLot(1,1,'72','720',3);
        DB::table('items')->insert(['id'=>20,'company_id'=>2,'item_name'=>'Foreign weight','item_code'=>'FOREIGN','base_unit_code'=>'KG']);
        app(\App\Services\InventoryLotService::class)->createInboundLot(['company_id'=>2,'branch_id'=>2,'item_id'=>20,'qty_kg'=>'1000','base_cost'=>'9000','source_type'=>'OPENING','received_at'=>'2026-01-01','lot_number'=>'FOREIGN']);
        self::assertCount(1,(new R)->read(1,[1]));self::assertCount(2,(new R)->read(1,[1,3]));self::assertSame('1000.000000',(new R)->read(2,[2])[0]['quantity']);self::assertSame('9000.000',(new R)->read(2,[2])[0]['value']);
        $this->denied(fn()=>(new R)->read(1,[2]),'Foreign branch');$this->denied(fn()=>(new R)->read(1,[]),'Explicit');
        self::assertSame([],(new R)->read(2,[2],1));
    }
    public function test_same_cross_version_source_is_blocked_not_silently_deduplicated():void
    {
        DB::table('inventory_lots')->where('item_id',10)->update(['source_type'=>'UOM_TEST','source_id'=>999]);$this->baseLot(10,3,'100','200',1,999);
        $this->denied(fn()=>(new R)->read(1,[1],10),'AMBIGUOUS_DUAL_EVENT');
    }
    public function test_legacy_cost_rounding_and_no_float_reader():void
    {
        DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,10,10,'SALE',123));$r=(new R)->read(1,[1],10)[0];self::assertSame('90.000000',$r['quantity']);self::assertSame('180.000',$r['value']);self::assertSame('MATCHED',$r['valuation_status']);
        $this->baseLot(3,5,'3','1');$r=(new R)->read(1,[1],3)[0];self::assertSame('1.000',$r['value']);self::assertSame('0.333333',$r['average_unit_cost']);
        $source=file_get_contents(__DIR__.'/../../Support/UomUnifiedInventoryReader.php');self::assertDoesNotMatchRegularExpression('/\(float\)|floatval\(|array_sum\(/',$source);
    }
    public function test_stock_only_evidence_and_wrong_base_receipt_cost_are_blocked():void
    {
        DB::table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'movement_type'=>'IN','source_type'=>'OLD_PATH','source_id'=>9,'movement_date'=>'2026-03-01','qty'=>'1','total_cost'=>'10']);
        $this->denied(fn()=>(new R)->read(1,[1],10),'UNSUPPORTED_STOCK_ONLY');
        $id=$this->baseLot(1,1,'72','720');DB::table('inventory_lots')->where('id',$id)->update(['base_cost'=>'721','total_cost'=>'721']);
        $this->denied(fn()=>(new R)->read(1,[1],1),'receipt cost');
    }
    public function test_legacy_valuation_exception_and_reader_has_no_writes():void
    {
        DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,10,10,'SALE',123));
        // Deliberately inconsistent synthetic capitalisation evidence, never repair it in reader.
        DB::table('inventory_lots')->where('item_id',10)->update(['allocated_cost'=>'2','total_cost'=>'202']);
        DB::enableQueryLog();DB::flushQueryLog();$row=(new R)->read(1,[1],10)[0];$queries=DB::getQueryLog();DB::disableQueryLog();
        self::assertSame('181.800',$row['value']);self::assertSame('180.000000',$row['movement_value']);self::assertSame('REQUIRES_REVIEW_LEGACY_ROUNDING_OR_CAPITALISATION',$row['valuation_status']);
        foreach($queries as $q)self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE)\b/i',$q['query']);
    }
    public function test_cross_item_linked_stock_evidence_is_not_silently_ignored():void
    {
        $id=$this->baseLot(1,1,'72','720');
        DB::table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'inventory_lot_id'=>$id,'movement_type'=>'IN','source_type'=>'BAD_LINK','source_id'=>1,'movement_date'=>'2026-03-01','qty_kg'=>'1','total_cost'=>'10']);
        $this->denied(fn()=>(new R)->read(1,[1],10),'cross-scope');
    }
}
