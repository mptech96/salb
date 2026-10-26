<?php
namespace Tests\Integration\Uom;

use App\Services\EnterpriseInvoiceService;
use App\Services\ShipmentCostReadIsolation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;

final class ShipmentCostReadIsolationTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $name) require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1'); F::install();
    }

    private function migration(): object
    {
        return require base_path('../docs/erp-baseline/proposed-migrations/shipment_cost_parent_fk.php');
    }

    public function test_missing_fk_blocks_before_financial_write_and_without_leaking_transaction(): void
    {
        $s=F::purchase(); config(['shipment_cost_read.enabled'=>true]);
        $writes=[]; $active=true;
        DB::listen(function ($event) use (&$writes,&$active) { if($active && preg_match('/^(insert|update|delete) /i',$event->sql)) $writes[]=$event->sql; });
        try { app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1); self::fail('Missing FK accepted'); }
        catch (\RuntimeException $error) { self::assertSame('COST_READ_PARENT_FK_REQUIRED',$error->getMessage()); }
        finally { $active=false; }
        self::assertSame([],$writes); self::assertSame(0,DB::transactionLevel()); self::assertFalse(DB::connection()->getPdo()->inTransaction());
        self::assertSame(0,DB::table('journal_entries')->count()); self::assertSame(0,DB::table('inventory_lots')->count());
    }

    public function test_isolated_migration_preserves_rows_and_real_post_uses_it(): void
    {
        $s=F::purchase(); $before=DB::table('shipment_costs')->orderBy('id')->get()->toJson();
        $migration=$this->migration(); $migration->up();
        self::assertSame($before,DB::table('shipment_costs')->orderBy('id')->get()->toJson());
        config(['shipment_cost_read.enabled'=>true]);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        self::assertEquals(120,DB::table('inventory_lots')->sum('total_cost'));
        self::assertSame(2,DB::table('journal_entries')->count()); self::assertSame(1,DB::table('vouchers')->count());
        try { $migration->down(); self::fail('Active protocol lost FK'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Disable',$error->getMessage()); }
        config(['shipment_cost_read.enabled'=>false]); $migration->down();
        self::assertSame(1,DB::table('shipment_costs')->count());
    }

    public function test_orphaned_history_is_not_repaired_by_fk_migration(): void
    {
        $s=F::purchase(); DB::table('shipment_costs')->where('id',$s['cost'])->update(['shipment_id'=>999]);
        $before=DB::table('shipment_costs')->get()->toJson();
        try { $this->migration()->up(); self::fail('Orphan accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Orphan',$error->getMessage()); }
        self::assertSame($before,DB::table('shipment_costs')->get()->toJson());
    }

    public function test_active_foreign_root_is_not_reconfigured(): void
    {
        config(['shipment_cost_read.enabled'=>true]); DB::beginTransaction();
        try { ShipmentCostReadIsolation::prepare([['shipment'=>1]]); self::fail('Foreign root accepted'); }
        catch (\RuntimeException $error) { self::assertSame('COST_READ_ISOLATION_REQUIRES_ROOT_ENTRY',$error->getMessage()); }
        finally { DB::rollBack(); }
    }

    public function test_failed_begin_does_not_leave_rc_for_the_next_transaction(): void
    {
        $s=F::purchase(); $this->migration()->up(); config(['shipment_cost_read.enabled'=>true]);
        $armed=true;
        DB::connection()->beforeStartingTransaction(function () use (&$armed) { if($armed) { $armed=false; throw new \RuntimeException('BEGIN_FAILURE_PROBE'); } });
        try { app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1); self::fail('Begin probe bypassed'); }
        catch (\RuntimeException $error) { self::assertSame('BEGIN_FAILURE_PROBE',$error->getMessage()); }
        $pdo=DB::connection()->getPdo();
        $peer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        DB::beginTransaction();
        try {
            $old=DB::table('shipment_costs')->where('id',$s['cost'])->value('amount');
            $peer->exec('UPDATE shipment_costs SET amount=21 WHERE id='.(int)$s['cost']);
            self::assertSame($old,DB::table('shipment_costs')->where('id',$s['cost'])->value('amount'));
        } finally { DB::rollBack(); }
        self::assertFalse($pdo->inTransaction()); self::assertSame(0,DB::table('journal_entries')->count());
    }
}
