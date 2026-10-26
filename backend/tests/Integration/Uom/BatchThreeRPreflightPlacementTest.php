<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Scope/blocker proof, NOT a rounding implementation acceptance. */
final class BatchThreeRPreflightPlacementTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
    }
    private function snapshot(): string
    {
        $out=[];
        foreach(['purchase_invoices','purchase_invoice_lines','shipments','shipment_items','shipment_costs','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','vouchers','sulb_document_sequences'] as $table)$out[$table]=DB::table($table)->orderBy('id')->get();
        return serialize($out);
    }
    public function test_cost_entry_preflight_is_after_inventory_and_purchase_gl_but_rollback_is_complete(): void
    {
        $s=F::purchase();$before=$this->snapshot();$active=true;$writes=[];$reached=false;
        DB::listen(function($event)use(&$active,&$writes,&$reached){
            if(!$active)return;
            $sql=strtolower(str_replace('`','',$event->sql));
            foreach(['inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines'] as $table){
                if(str_starts_with($sql,'insert into '.$table.' '))$writes[]=$table;
            }
            // Query reached at actual postPendingForShipment entry, before its first cost journal.
            // Inject a test failure there; this is instrumentation, NOT a real LR/tie detector.
            if(str_starts_with($sql,'select * from shipment_costs ') && str_contains($sql,'cost_status') && str_contains($sql,'order by id asc') && str_ends_with($sql,'for update')){
                $reached=true;throw new \RuntimeException('R_PREFLIGHT_PLACEMENT_PROBE');
            }
        });
        try{
            app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
            self::fail('Expected placement probe');
        }catch(\RuntimeException $e){self::assertSame('R_PREFLIGHT_PLACEMENT_PROBE',$e->getMessage());}
        finally{$active=false;}
        self::assertTrue($reached);self::assertContains('inventory_lots',$writes);self::assertContains('stock_movements',$writes);self::assertContains('journal_entries',$writes);self::assertContains('journal_entry_lines',$writes);
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        fwrite(STDOUT,'R_PREFLIGHT_POSITION '.json_encode(['writes_before_cost_entry'=>$writes,'root_rollback_exact'=>true,'rounding_fixed'=>false]).PHP_EOL);
    }
}
