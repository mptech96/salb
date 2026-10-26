<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\UomItemSeparationProof as S;
require_once __DIR__.'/UomStageASchemaTest.php';
class UomSeparationClosureTest extends UomStageASchemaTest
{
    protected function setUp():void{parent::setUp();require_once __DIR__.'/../../Support/UomUnifiedInventoryReader.php';require_once __DIR__.'/../../Support/UomItemSeparationProof.php';}
    private function deny(callable $f,string $msg):void{try{$f();self::fail('Expected rejection');}catch(\RuntimeException $e){self::assertStringContainsString($msg,$e->getMessage());}}
    private function mode(int $item,string $mode,int $company=1):void{DB::table('uom_item_modes')->insert(['company_id'=>$company,'item_id'=>$item,'mode'=>$mode]);}
    private function receipt(int $item,int $base,string $qty,string $cost,?int $line=null):int
    {
        $mapping=DB::table('item_uom_mappings')->where('item_id',$item)->where('unit_id',$base)->value('id')??DB::table('item_uom_mappings')->insertGetId(['company_id'=>1,'item_id'=>$item,'unit_id'=>$base,'base_unit_id'=>$base,'revision'=>1,'factor_to_base'=>'1']);
        $lot=DB::table('inventory_lots')->insertGetId(['company_id'=>1,'branch_id'=>1,'item_id'=>$item,'purchase_invoice_line_id'=>$line,'lot_number'=>'CLOSE-'.bin2hex(random_bytes(5)),'received_at'=>'2026-03-01','source_type'=>'PURCHASE','source_id'=>1,'uom_version'=>'BASE_V1','uom_company_id'=>1,'uom_mapping_id'=>$mapping,'base_uom_id_snapshot'=>$base,'received_base_qty'=>$qty,'remaining_base_qty'=>$qty,'sold_base_qty'=>'0','unit_cost_per_base'=>bcdiv($cost,$qty,6),'base_cost'=>$cost,'allocated_cost'=>'0','total_cost'=>$cost]);
        DB::table('inventory_lot_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>$item,'inventory_lot_id'=>$lot,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>1,'movement_at'=>'2026-03-01','qty_kg'=>'0','total_cost'=>$cost,'uom_version'=>'BASE_V1','uom_company_id'=>1,'base_quantity'=>$qty,'base_uom_id_snapshot'=>$base,'uom_mapping_id'=>$mapping,'unit_cost_per_base'=>bcdiv($cost,$qty,6)]);return $lot;
    }
    public function test_new_items_are_independent_of_unlinked_legacy_item():void
    {
        S::install();$this->mode(10,'LEGACY_ONLY');foreach([1,2,3,4] as $i)$this->mode($i,'BASE_ONLY');
        DB::table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'movement_type'=>'IN','source_type'=>'OLD_PATH','source_id'=>9,'movement_date'=>'2026-01-01','qty'=>'1','total_cost'=>'10']);
        $this->receipt(1,1,'72','720');$this->receipt(3,5,'12.5','100');$this->receipt(4,6,'2','20');
        $carton=DB::table('item_uom_mappings')->insertGetId(['company_id'=>1,'item_id'=>2,'unit_id'=>2,'base_unit_id'=>1,'revision'=>1,'factor_to_base'=>'24']);
        $price=DB::table('item_uom_prices')->insertGetId(['company_id'=>1,'mapping_id'=>$carton,'currency_code'=>'SAR','revision'=>1,'purchase_price'=>'120']);
        DB::table('purchase_invoice_lines')->insert(['company_id'=>1,'purchase_invoice_id'=>1,'item_id'=>2,'quantity'=>'3','unit_code'=>'CARTON','unit_factor_to_base'=>'24','qty_kg'=>'0','uom_version'=>'BASE_V1','uom_mapping_id'=>$carton,'base_uom_id_snapshot'=>1,'base_quantity'=>'72','uom_unit_price_snapshot'=>'120','uom_price_revision_id'=>$price]);
        $this->receipt(2,1,'72','360');
        $rows=array_column(S::readItems(1,[1],[10,1,2,3,4]),null,'item_id');
        self::assertSame('REVIEW_REQUIRED',$rows[10]['status']);self::assertNull($rows[10]['quantity']);
        foreach([1,2,3,4] as $id)self::assertSame('VERIFIED_LOT_READ',$rows[$id]['status']);
        self::assertSame('72.000000',$rows[1]['rows'][0]['quantity']);self::assertSame('720.000',$rows[1]['rows'][0]['value']);self::assertSame('PCS',$rows[2]['rows'][0]['unit']);self::assertSame('72.000000',$rows[2]['rows'][0]['quantity']);self::assertSame('12.500000',$rows[3]['rows'][0]['quantity']);self::assertSame('2.000000',$rows[4]['rows'][0]['quantity']);
        self::assertContains('UNLINKED_STOCK_MOVEMENTS',S::transitionReasons(1,10));
        $this->deny(fn()=>DB::table('uom_item_modes')->where('item_id',10)->update(['mode'=>'MIXED_VERIFIED']),'APPROVED_PROTOCOL');
        $this->deny(fn()=>$this->receipt(10,3,'20','40'),'MODE_VERSION');
        $this->deny(fn()=>DB::table('inventory_lots')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'lot_number'=>'WRONG','received_at'=>'2026-03-01','source_type'=>'TEST','qty_received_kg'=>'1','qty_remaining_kg'=>'1']),'MODE_VERSION');
        self::assertSame('100.000',DB::table('inventory_lots')->where('item_id',10)->value('qty_remaining_kg'));
        self::assertSame('2.000000',DB::table('inventory_lots')->where('item_id',10)->value('unit_cost_per_kg'));self::assertSame('200.000',DB::table('inventory_lots')->where('item_id',10)->value('total_cost'));
    }
    public function test_modes_cannot_be_forged_replaced_or_assigned_cross_company():void
    {
        DB::table('purchase_invoice_lines')->insert(['company_id'=>1,'purchase_invoice_id'=>1,'item_id'=>2,'quantity'=>'1','qty_kg'=>'1','unit_code'=>'KG']);
        S::install();$this->deny(fn()=>$this->mode(10,'BASE_ONLY'),'NEW_ITEM_ONLY');$this->deny(fn()=>$this->mode(1,'MIXED_VERIFIED'),'CERTIFICATE_REQUIRED');$this->deny(fn()=>$this->mode(1,'BASE_ONLY',2),'foreign key');
        $this->deny(fn()=>$this->mode(2,'BASE_ONLY'),'NEW_ITEM_ONLY');
        $this->mode(1,'BASE_ONLY');$this->deny(fn()=>DB::table('uom_item_modes')->where('item_id',1)->delete(),'IMMUTABLE');
        $this->deny(fn()=>DB::table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'movement_type'=>'IN','source_type'=>'IMPORT','source_id'=>1,'movement_date'=>'2026-03-01','qty'=>'72','total_cost'=>'720']),'MIRROR_PROTOCOL');
        $old=DB::table('stock_movements')->insertGetId(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'movement_type'=>'IN','source_type'=>'OLD','source_id'=>1,'movement_date'=>'2026-03-01','qty'=>'1','total_cost'=>'10']);
        $this->deny(fn()=>DB::table('stock_movements')->where('id',$old)->update(['item_id'=>1]),'MIRROR_PROTOCOL');
        self::assertSame('SCOPE_OR_MODE_NOT_FOUND',S::readItems(2,[2],[1])[0]['status']);
    }
    private function event(int $lot,int $line):array
    {return ['company_id'=>1,'branch_id'=>1,'item_id'=>10,'source_type'=>'PURCHASE','source_id'=>1,'source_line_id'=>$line,'direction'=>'IN','allocation_id'=>$lot,'movement_id'=>DB::table('inventory_lot_movements')->where('inventory_lot_id',$lot)->value('id')];}
    public function test_explicit_mirror_event_counted_once_distinct_lines_preserved():void
    {
        // Identity sub-model only, not a certificate authorizing a legacy-mode transition.
        S::install(false);
        $a=DB::table('purchase_invoice_lines')->insertGetId(['company_id'=>1,'purchase_invoice_id'=>1,'item_id'=>10,'quantity'=>'20','qty_kg'=>'20','unit_code'=>'KG','unit_factor_to_base'=>'1']);
        $b=DB::table('purchase_invoice_lines')->insertGetId(['company_id'=>1,'purchase_invoice_id'=>1,'item_id'=>10,'quantity'=>'30','qty_kg'=>'30','unit_code'=>'KG','unit_factor_to_base'=>'1']);
        $l1=$this->receipt(10,3,'20','40',$a);$l2=$this->receipt(10,3,'30','90',$b);
        $e1=DB::table('uom_proof_events')->insertGetId($this->event($l1,$a));$e2=DB::table('uom_proof_events')->insertGetId($this->event($l2,$b));
        $stock=DB::table('stock_movements')->insertGetId(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'inventory_lot_id'=>$l1,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>1,'movement_date'=>'2026-03-01','qty_kg'=>'20','total_cost'=>'40']);
        DB::table('uom_proof_mirrors')->insert(['stock_id'=>$stock,'event_id'=>$e1]);
        self::assertSame(['quantity'=>'50.000000','value'=>'130.000000','events'=>2],S::eventTotals(1,1,10));
        $this->deny(fn()=>DB::table('uom_proof_mirrors')->insert(['stock_id'=>$stock,'event_id'=>$e2]),'MIRROR_EVIDENCE');
        $bad=$this->event($l1,$b);$this->deny(fn()=>DB::table('uom_proof_events')->insert($bad),'EVENT_EVIDENCE');
        $bad=$this->event($l2,$b);$bad['company_id']=2;$this->deny(fn()=>DB::table('uom_proof_events')->insert($bad),'EVENT_EVIDENCE');
        $this->deny(fn()=>DB::table('uom_proof_events')->where('id',$e1)->update(['source_line_id'=>$b]),'IMMUTABLE');
        $this->deny(fn()=>DB::table('uom_proof_mirrors')->where('stock_id',$stock)->delete(),'IMMUTABLE');
        $this->deny(fn()=>DB::table('stock_movements')->where('id',$stock)->update(['qty_kg'=>'21']),'IMMUTABLE');
        $this->deny(fn()=>DB::table('inventory_lots')->where('id',$l1)->update(['purchase_invoice_line_id'=>$b]),'IMMUTABLE');
        $this->deny(fn()=>DB::table('purchase_invoice_lines')->where('id',$a)->update(['quantity'=>'99']),'IMMUTABLE');
        self::assertSame(['quantity'=>'0','value'=>'0','events'=>0],S::eventTotals(2,2,10));
    }
}
