<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;
final class ShipmentAllocationReceiptSchemaTest extends \Tests\TestCase
{
    public function test_isolated_additive_receipt_ddl_and_empty_rollback_preserve_source():void{
        foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $n)require_once __DIR__.'/../../Support/'.$n.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
        $s=F::purchase();$before=serialize(DB::table('shipment_costs')->orderBy('id')->get());$m=require base_path('../docs/erp-baseline/proposed-migrations/shipment_cost_allocation_receipts.php');$m->up();
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('shipment_cost_allocation_receipts'));self::assertSame(0,DB::table('shipment_cost_allocation_receipts')->count());
        $indexes=DB::select("SHOW INDEX FROM shipment_cost_allocation_receipts WHERE Key_name='uq_cost_allocation'");self::assertCount(2,$indexes);self::assertSame(0,(int)$indexes[0]->Non_unique);
        self::assertSame($before,serialize(DB::table('shipment_costs')->orderBy('id')->get()));$m->down();self::assertFalse(\Illuminate\Support\Facades\Schema::hasTable('shipment_cost_allocation_receipts'));self::assertSame($before,serialize(DB::table('shipment_costs')->orderBy('id')->get()));
    }
}
