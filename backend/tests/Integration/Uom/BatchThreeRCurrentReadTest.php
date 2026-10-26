<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Actual PURCHASE entry + two PDOs: MVCC scope proof, NOT LR acceptance. */
final class BatchThreeRCurrentReadTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();
        foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $n)require_once __DIR__.'/../../Support/'.$n.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
    }
    public function test_nonlocking_preflight_cost_read_is_stale_after_existing_header_lock():void
    {
        $s=F::purchase();$cfg=DB::connection()->getConfig();
        $writer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.$cfg['database'].';charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $writer->beginTransaction();$writer->query('SELECT id FROM shipments WHERE id='.(int)$s['shipment'].' FOR UPDATE')->fetchAll();
        $writer->exec("UPDATE shipment_costs SET amount=20.001,foreign_amount=20.001 WHERE id=".(int)$s['cost']);
        $active=true;$committed=false;$observed=[];$writes=[];
        DB::listen(function($q)use(&$active,&$committed,&$observed,&$writes,$writer,$s){
            if(!$active)return;$sql=strtolower(str_replace('`','',$q->sql));
            if(preg_match('/^insert into (inventory_lots|stock_movements|journal_entries|journal_entry_lines) /',$sql))$writes[]=$sql;
            // Deterministic barrier: first consistent source-link read exists, H is not yet owned.
            if(!$committed&&DB::transactionLevel()===1&&str_starts_with($sql,'select * from invoice_shipment_links ')&&!str_contains($sql,'for update')){
                $writer->commit();$committed=true;
            }
            // Actual invoice-line read is AFTER Batch2 H/I/R preparation and BEFORE inventory writes.
            if($committed&&str_starts_with($sql,'select l.*, i.item_name, i.allow_negative_stock from purchase_invoice_lines ')){
                $active=false;
                $observed=['snapshot'=>(string)DB::table('shipment_costs')->where('id',$s['cost'])->value('amount'),
                    'current'=>(string)$writer->query('SELECT amount FROM shipment_costs WHERE id='.(int)$s['cost'])->fetchColumn(),
                    'isolation'=>DB::selectOne('SELECT @@tx_isolation v')->v];
                throw new \RuntimeException('ALLOCATION_CURRENT_READ_PROOF');
            }
        });
        try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);self::fail('Expected probe');}
        catch(\RuntimeException $e){self::assertSame('ALLOCATION_CURRENT_READ_PROOF',$e->getMessage());}
        finally{$active=false;if($writer->inTransaction())$writer->rollBack();}
        self::assertTrue($committed);self::assertSame('20.000',$observed['snapshot']);self::assertSame('20.001',$observed['current']);self::assertSame('REPEATABLE-READ',$observed['isolation']);
        self::assertSame([],$writes);self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        fwrite(STDOUT,'ALLOCATION_PREFLIGHT_MVCC '.json_encode($observed+['writes_before_probe'=>count($writes),'root_rollback'=>true]).PHP_EOL);
    }
}
