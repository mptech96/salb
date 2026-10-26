<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\HistoricalLotBackfill as Backfill;
use Tests\Support\LotUpgradeDryRun as DryRun;

final class MixedLotUpgradeTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        foreach(['HistoricalLotBackfill','LotUpgradeDryRun'] as $file)require_once __DIR__.'/../../Support/'.$file.'.php';
        (require base_path('database/uom-migrations/2026_10_25_000005_opening_snapshots.php'))->up();
        $s=app(\App\Services\EnterpriseInvoiceService::class);$legacy=\Tests\Support\UomOperationalLockFixture::purchase(1,2);$s->post('PURCHASE',1,$legacy,1,1);
        $base=$s->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'120','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);$s->post('PURCHASE',1,$base,1,1);
        Backfill::install();
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    public function test_legacy_backfill_reconciles_existing_base_purchase_and_opening_without_rewriting_them():void
    {
        $s=app(\App\Services\OpeningBalanceService::class);$id=$s->saveDraft(1,['financial_year_id'=>1,'opening_date'=>'2026-10-25','inventory_lines'=>[['branch_id'=>1,'item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','total_cost'=>'120']]],1,1);$s->post(1,$id,1,true,1);
        $before=serialize(DB::table('inventory_lots')->orderBy('id')->get()->all());$dry=DryRun::run(1,2026);
        self::assertTrue($dry['ready'],json_encode($dry));self::assertCount(1,$dry['backfill']['rows']);self::assertCount(2,$dry['backfill']['preserved_base_lots']);
        self::assertSame(['replayed_value'=>'340.000','gl_value'=>'340.000'],$dry['backfill']['financial']['1:11']);
        $run=Backfill::apply($dry['backfill'],true);self::assertGreaterThan(0,$run);self::assertSame(1,DB::table('inventory_lot_value_balances')->count());
        self::assertSame($before,serialize(DB::table('inventory_lots')->orderBy('id')->get()->all()));self::assertSame($run,Backfill::apply(Backfill::plan(1),true));
    }
    public function test_base_value_drift_is_explicit_and_prevents_all_legacy_application():void
    {
        $base=DB::table('inventory_lots')->where('uom_version','BASE_V1')->value('id');DB::table('inventory_lot_movements')->where('inventory_lot_id',$base)->increment('total_cost','0.001');
        $dry=DryRun::run(1,2026);self::assertFalse($dry['ready']);self::assertContains('BASE_REPLAY_BALANCE_MISMATCH',array_column($dry['backfill']['exceptions'],'reason'));
        try{Backfill::apply($dry['backfill'],true);self::fail('Mixed history guessed');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_HISTORY',$e->getMessage());}
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());
    }
    public function test_transferred_base_lots_reconcile_each_branch_before_legacy_backfill():void
    {
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'QA destination']);config(['inventory_transfer.root_protocol_enabled'=>true]);
        foreach(['INTERBRANCH_DUE_FROM_ACCOUNT'=>33,'INTERBRANCH_DUE_TO_ACCOUNT'=>12] as $key=>$account)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$account]);
        $s=app(\App\Services\InventoryOperationService::class);$id=$s->create(['company_id'=>1,'created_by'=>1,'operation_type'=>'TRANSFER','from_branch_id'=>1,'to_branch_id'=>3,'operation_date'=>'2026-10-25','from_lines'=>[['item_id'=>1,'unit_code'=>'PCS','quantity'=>'5']]]);$s->approve(1,$id,1,1);
        $dry=DryRun::run(1,2026);self::assertTrue($dry['ready'],json_encode($dry));self::assertSame('170.000',$dry['backfill']['financial']['1:11']['gl_value']);self::assertSame('50.000',$dry['backfill']['financial']['3:11']['gl_value']);
        self::assertCount(2,$dry['backfill']['preserved_base_lots']);self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
    }
    public function test_new_base_adjustments_are_preserved_and_reconciled_during_legacy_upgrade():void
    {
        (require base_path('database/uom-migrations/2026_10_25_000006_base_adjustments.php'))->up();
        DB::table('accounts')->insert(['id'=>34,'company_id'=>1,'account_code'=>'ADJ','account_name'=>'QA adjustment','account_type'=>'EXPENSE','normal_side'=>'DEBIT','allow_posting'=>1]);DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'INVENTORY_ADJUSTMENT_ACCOUNT','account_id'=>34]);
        app(\App\Services\Uom\BaseAdjustmentService::class)->post(1,1,1,['item_id'=>1,'request_key'=>'QA-MIXED-UPGRADE','movement_type'=>'IN','movement_date'=>'2026-10-25','quantity'=>'1','unit_code'=>'PCS','total_cost'=>'20','notes'=>'QA mixed legacy upgrade adjustment']);
        $dry=DryRun::run(1,2026);self::assertTrue($dry['ready'],json_encode($dry));self::assertSame('240.000',$dry['backfill']['financial']['1:11']['gl_value']);self::assertCount(2,$dry['backfill']['preserved_base_lots']);self::assertCount(1,$dry['backfill']['rows']);
        Backfill::apply($dry['backfill'],true);self::assertSame(1,DB::table('inventory_lot_value_balances')->count());
    }
}
