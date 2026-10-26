<?php
namespace Tests\Integration\Uom;

use App\Services\InventoryLotService;
use Illuminate\Support\Facades\DB;
use Tests\Support\UomOperationalLockFixture as F;

final class InventoryLotValueAcceptanceTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
    }
    private function install():void
    {
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/inventory_lot_value_balances.php');$migration->up();config(['inventory_value.enabled'=>true]);
    }
    public function test_three_fractional_issues_restore_and_rollback_preserve_exact_value():void
    {
        $this->install();$lots=app(InventoryLotService::class);
        $id=DB::transaction(fn()=>$lots->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>3,'base_cost'=>'10.001','received_at'=>'2026-10-14']));
        $values=[];foreach([1,2,3] as $source){$r=DB::transaction(fn()=>$lots->consumeFifo(1,1,1,1,'SALE',$source,null,1));$values[]=number_format($r['total_cost'],3,'.','');}
        self::assertSame(['3.334','3.334','3.333'],$values);self::assertSame('0.000',DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$id)->value('remaining_value'));
        DB::transaction(function()use($id){$lot=DB::table('inventory_lots')->where('id',$id)->lockForUpdate()->first();\App\Services\InventoryLotValue::restore($lot,'1.000','3.334');DB::table('inventory_lots')->where('id',$id)->update(['qty_remaining_kg'=>1,'qty_sold_kg'=>2,'lot_status'=>'OPEN']);});
        $before=DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$id)->first();
        try{DB::transaction(function()use($lots){$lots->consumeFifo(1,1,1,1,'SALE',4,null,1);throw new \RuntimeException('VALUE_ROLLBACK_PROBE');});}catch(\RuntimeException $error){self::assertSame('VALUE_ROLLBACK_PROBE',$error->getMessage());}
        self::assertEquals($before,DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$id)->first());
        $last=DB::transaction(fn()=>$lots->consumeFifo(1,1,1,1,'SALE',5,null,1));self::assertSame('3.334',number_format($last['total_cost'],3,'.',''));
        self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
    public function test_additive_schema_does_not_initialize_or_revalue_unreviewed_history():void
    {
        $purchase=F::purchase(1,1);app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);
        DB::transaction(fn()=>app(InventoryLotService::class)->consumeFifo(1,1,1,1,'SALE',99,null,1));
        $snapshot=fn()=>serialize([DB::table('inventory_lots')->orderBy('id')->get(),DB::table('inventory_lot_movements')->orderBy('id')->get(),DB::table('journal_entry_lines')->orderBy('id')->get()]);
        $before=$snapshot();$this->install();self::assertSame($before,$snapshot());self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
        try{DB::transaction(fn()=>app(InventoryLotService::class)->consumeFifo(1,1,1,1,'SALE',100,null,1));self::fail('Unknown historic value inferred');}
        catch(\RuntimeException $error){self::assertSame('LOT_VALUE_HISTORY_REVIEW_REQUIRED',$error->getMessage());}
        self::assertSame($before,$snapshot());self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
        self::assertEquals(90,app(InventoryLotService::class)->summary(1,1)->first()->stock_value);
    }
    public function test_schema_and_root_guards_precede_lot_insert():void
    {
        config(['inventory_value.enabled'=>true]);$data=['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>1,'base_cost'=>1,'received_at'=>'2026-10-14'];
        try{app(InventoryLotService::class)->createInboundLot($data);self::fail('Unowned insert');}catch(\RuntimeException $error){self::assertSame('LOT_VALUE_ROOT_REQUIRED',$error->getMessage());}
        try{DB::transaction(fn()=>app(InventoryLotService::class)->createInboundLot($data));self::fail('Missing schema accepted');}catch(\RuntimeException $error){self::assertSame('LOT_VALUE_SCHEMA_REQUIRED',$error->getMessage());}
        self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('inventory_lot_movements')->count());
    }
}
