<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\HistoricalLotBackfill as Backfill;
use Tests\Support\LotUpgradeDryRun as DryRun;

final class LotUpgradeDryRunTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','HistoricalLotBackfill','LotUpgradeDryRun'] as $file)require_once __DIR__.'/../../Support/'.$file.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');
        \Tests\Support\UomOperationalLockFixture::install();$id=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1);
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function snapshot():string
    {
        $data=['tables'=>DB::select('SHOW TABLES')];foreach(['inventory_lots','inventory_lot_movements','journal_entries','journal_entry_lines','sulb_document_sequences'] as $table)$data[$table]=DB::table($table)->orderBy('id')->get()->all();return serialize($data);
    }
    public function test_legacy_schema_inventory_export_does_not_require_new_item_unit_columns():void
    {
        DB::unprepared('ALTER TABLE items DROP COLUMN base_unit_code');self::assertFalse(DB::getSchemaBuilder()->hasColumn('items','base_unit_code'));$before=$this->snapshot();$rows=app(\App\Services\MigrationCenterService::class)->exportRows(1,'inventory_balances');self::assertCount(1,$rows);self::assertSame('KG',$rows[0]['unit_code']);self::assertSame(0,bccomp('10',$rows[0]['quantity'],3));self::assertSame(0,bccomp('100',$rows[0]['remaining_value'],3));
        foreach(['inventory','inventory-lots','inventory-movements'] as $key){$report=app(\App\Services\ReportCenterService::class)->run(1,1,$key);self::assertCount(1,$report['rows']);self::assertSame('KG',$report['rows']->first()->base_unit_code);}self::assertSame($before,$this->snapshot());
    }
    public function test_cli_dry_run_reports_missing_schema_and_performs_no_ddl_or_data_writes():void
    {
        $before=$this->snapshot();$process=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/qa_lot_upgrade_dry_run.php'),DB::connection()->getDatabaseName(),'1','2026'],base_path());
        $process->setTimeout(20);$process->run();self::assertSame(2,$process->getExitCode(),$process->getErrorOutput());
        $result=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($result['writes_performed']);self::assertFalse($result['production_qualified']);self::assertFalse($result['ready']);self::assertTrue($result['backfill']['ready']);
        self::assertSame(2,$result['sequence']['proposed_next_number']);self::assertContains('inventory_lot_value_balances',$result['schema_changes_required']);
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());
    }
    public function test_schema_install_is_restartable_and_ready_dry_run_does_not_apply_balances():void
    {
        Backfill::install();$before=$this->snapshot();Backfill::install();self::assertSame($before,$this->snapshot());
        $result=DryRun::run(1,2026);self::assertTrue($result['ready'],json_encode($result));self::assertSame([],$result['schema_changes_required']);
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());
        self::assertSame($before,$this->snapshot());
        DB::unprepared('ALTER TABLE inventory_lot_value_balances MODIFY remaining_value DECIMAL(18,2) NOT NULL');
        $dry=DryRun::run(1,2026);self::assertFalse($dry['ready']);self::assertContains('INCOMPATIBLE_COLUMN',array_column($dry['schema_exceptions'],'reason'));
        try{Backfill::install();self::fail('Incompatible existing schema accepted');}catch(\RuntimeException $e){self::assertSame('QA_LOT_SCHEMA_MISMATCH',$e->getMessage());}
    }
    public function test_unmatched_money_and_custom_number_are_shown_without_a_proposed_guess():void
    {
        Backfill::install();DB::table('inventory_lots')->update(['lot_number'=>'OLD-CUSTOM-REFERENCE','qty_remaining_kg'=>'9.999']);$before=$this->snapshot();
        $result=DryRun::run(1,2026);self::assertFalse($result['ready']);self::assertNull($result['sequence']['proposed_next_number']);
        self::assertContains('UNREVIEWED_CUSTOM_NUMBER',array_column($result['sequence']['exceptions'],'reason'));
        self::assertContains('REPLAY_BALANCE_MISMATCH',array_column($result['backfill']['exceptions'],'reason'));
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
    }
}
