<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use Tests\Support\HistoricalLotBackfill as Backfill;
use Tests\Support\UomOperationalLockFixture as F;

final class HistoricalLotBackfillTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        gc_collect_cycles();
        parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','HistoricalLotBackfill','LotSequencePreparationProof'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
        foreach([[31,'REVENUE','CREDIT'],[32,'EXPENSE','DEBIT'],[33,'ASSET','DEBIT']] as [$id,$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'QA-'.$id,'account_name'=>'QA historical source','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1,'allow_cost_center'=>0]);
        DB::table('items')->where('id',1)->update(['sales_account_id'=>31,'cogs_account_id'=>32]);
        DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'QA history customer','ledger_account_id'=>33,'scope_all_branches'=>1]);
    }
    protected function tearDown():void
    {
        DB::disconnect();parent::tearDown();gc_collect_cycles();
    }
    private function history():void
    {
        $service=app(EnterpriseInvoiceService::class);$purchase=F::purchase(1,1);$service->post('PURCHASE',1,$purchase,1,1);
        $sale=$service->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'qty_kg'=>4,'unit_price'=>15,'price_unit'=>'KG','vat_percent'=>0]]],1,1,1);
        $service->post('SALE',1,$sale,1,1);Backfill::install();
    }
    private function source():string
    {
        $rows=[];foreach(['inventory_lots','inventory_lot_movements','journal_entries','journal_entry_lines','sales_line_lot_sources'] as $table)$rows[$table]=DB::table($table)->orderBy('id')->get();return serialize($rows);
    }
    public function test_multi_lot_history_includes_depleted_lots_and_keeps_other_company_untouched():void
    {
        $service=app(EnterpriseInvoiceService::class);
        foreach([1,1,2] as $company){$id=F::purchase($company,$company===1?1:3);$service->post('PURCHASE',$company,$id,$company,$company);}
        $sale=$service->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'qty_kg'=>14,'unit_price'=>15,'price_unit'=>'KG','vat_percent'=>0]]],1,1,1);
        $service->post('SALE',1,$sale,1,1);Backfill::install();
        $before=$this->source();$plan=Backfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));
        self::assertSame(['0.000','6.000'],array_column($plan['rows'],'remaining_quantity'));
        self::assertSame(['0.000','60.000'],array_column($plan['rows'],'remaining_value'));
        self::assertSame('60.000',$plan['financial']['1:11']['gl_value']);
        Backfill::apply($plan,true);self::assertSame(2,DB::table('inventory_lot_value_balances')->where('company_id',1)->count());
        self::assertSame(0,DB::table('inventory_lot_value_balances')->where('company_id',2)->count());self::assertSame($before,$this->source());
    }
    public function test_history_exception_matrix_is_explicit_and_never_invents_values():void
    {
        $this->history();$lot=DB::table('inventory_lots')->first();
        foreach([
            ['source_type'=>'OPENING_BALANCE','reason'=>'SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED'],
            ['allocated_cost'=>'1.000','reason'=>'SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED'],
            ['qty_remaining_kg'=>'5.999','reason'=>'REPLAY_BALANCE_MISMATCH'],
        ] as $case){
            $reason=$case['reason'];unset($case['reason']);DB::table('inventory_lots')->where('id',$lot->id)->update($case);
            $plan=Backfill::plan(1);self::assertFalse($plan['ready']);self::assertContains($reason,array_column($plan['exceptions'],'reason'));
            try{Backfill::apply($plan,true);self::fail('Unresolved historical value applied');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_HISTORY',$e->getMessage());}
            DB::table('inventory_lots')->where('id',$lot->id)->update(array_intersect_key((array)$lot,$case));
        }
        DB::table('journal_entry_lines')->where('account_id',11)->where('debit','>',0)->increment('debit',1);
        $plan=Backfill::plan(1);self::assertFalse($plan['ready']);self::assertContains('GL_RECONCILIATION_MISMATCH',array_column($plan['exceptions'],'reason'));
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());
    }
    public function test_inventory_gl_without_lot_scope_is_reported_as_unmatched():void
    {
        $this->history();DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'Historical unmatched branch']);
        $line=(array)DB::table('journal_entry_lines')->where('company_id',1)->where('account_id',11)->first();unset($line['id']);
        DB::table('journal_entry_lines')->insert(array_replace($line,['branch_id'=>3,'debit'=>'1.000','credit'=>'0.000']));
        $plan=Backfill::plan(1);self::assertFalse($plan['ready']);
        self::assertSame(['replayed_value'=>'0.000','gl_value'=>'1.000'],$plan['financial']['3:11']);
        self::assertContains('GL_RECONCILIATION_MISMATCH',array_column($plan['exceptions'],'reason'));
        try{Backfill::apply($plan,true);self::fail('Orphan GL value accepted');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_HISTORY',$e->getMessage());}
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
    }
    public function test_offline_backfill_refuses_another_real_database_client():void
    {
        $this->history();$plan=Backfill::plan(1);
        $other=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        try{
            foreach([fn()=>Backfill::apply($plan,true),fn()=>Backfill::prepareSequence(1,2026,true)] as $call){
                try{$call();self::fail('Concurrent database client ignored');}catch(\RuntimeException $e){self::assertSame('OTHER_DATABASE_CLIENTS_PRESENT',$e->getMessage());}
            }
            self::assertSame(0,DB::table('inventory_lot_value_balances')->count());self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());
        }finally{$other=null;}
        self::assertGreaterThan(0,Backfill::apply($plan,true));
    }
    public function test_backfill_replays_actual_fifo_cost_matches_gl_and_reconnect_is_idempotent():void
    {
        $this->history();$before=$this->source();$plan=Backfill::plan(1);
        self::assertTrue($plan['ready'],json_encode($plan['exceptions']));
        self::assertSame('60.000',$plan['rows'][0]['remaining_value']);
        self::assertSame('6.000',$plan['rows'][0]['remaining_quantity']);
        self::assertSame('60.000',$plan['financial']['1:11']['gl_value']);
        $run=Backfill::apply($plan,true);self::assertGreaterThan(0,$run);
        self::assertSame($before,$this->source());self::assertSame('60.000',DB::table('inventory_lot_value_balances')->value('remaining_value'));
        DB::disconnect();DB::reconnect();self::assertSame($run,Backfill::apply(Backfill::plan(1),true));
        self::assertSame(1,DB::table('qa_lot_backfill_runs')->count());self::assertSame(1,DB::table('inventory_lot_value_balances')->count());
        DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->update(['next_number'=>1]);
        self::assertSame(2,Backfill::prepareSequence(1,2026,true));
        self::assertSame(2,DB::table('qa_lot_backfill_runs')->count());
        self::assertSame(2,Backfill::prepareSequence(1,2026,true));
        self::assertSame(2,DB::table('qa_lot_backfill_runs')->count());
        self::assertSame($before,$this->source());
        self::assertSame(0,DB::transactionLevel());
    }
    public function test_unmatched_history_and_stale_or_altered_plan_are_refused():void
    {
        $this->history();$plan=Backfill::plan(1);$altered=$plan;$altered['rows'][0]['remaining_value']='61.000';
        try{Backfill::apply($altered,true);self::fail('Altered plan accepted');}catch(\RuntimeException $e){self::assertSame('STALE_OR_ALTERED_BACKFILL_PLAN',$e->getMessage());}
        DB::table('inventory_lot_movements')->where('movement_type','OUT')->update(['total_cost'=>'39.999']);
        try{Backfill::apply($plan,true);self::fail('Stale plan accepted');}catch(\RuntimeException $e){self::assertSame('STALE_OR_ALTERED_BACKFILL_PLAN',$e->getMessage());}
        $unresolved=Backfill::plan(1);self::assertFalse($unresolved['ready']);self::assertContains('FIFO_SOURCE_EVIDENCE_MISMATCH',array_column($unresolved['exceptions'],'reason'));
        try{Backfill::apply($unresolved,true);self::fail('Unknown value invented');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_HISTORY',$e->getMessage());}
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());
    }
    public function test_audit_failure_rolls_back_all_new_balances_and_does_not_change_history():void
    {
        $this->history();$plan=Backfill::plan(1);$before=$this->source();
        DB::unprepared("CREATE TRIGGER qa_backfill_audit_failure BEFORE INSERT ON qa_lot_backfill_runs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA audit failure'");
        try{Backfill::apply($plan,true);self::fail('Audit failure ignored');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('QA audit failure',$e->getMessage());}
        self::assertSame($before,$this->source());self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
        self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());self::assertSame(0,DB::transactionLevel());
    }
}
