<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\Uom\BaseAdjustmentService;

final class BaseAdjustmentAdapterTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        (require base_path('database/uom-migrations/2026_10_25_000006_base_adjustments.php'))->up();
        DB::table('accounts')->insert(['id'=>34,'company_id'=>1,'account_code'=>'ADJUST','account_name'=>'QA adjustment','account_type'=>'EXPENSE','normal_side'=>'DEBIT','allow_posting'=>1]);
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'INVENTORY_ADJUSTMENT_ACCOUNT','account_id'=>34]);
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function input(string $direction='IN'):array
    {return ['item_id'=>1,'request_key'=>'QA-'.$direction.'-000001','movement_type'=>$direction,'movement_date'=>'2026-10-25','unit_code'=>'CARTON','quantity'=>$direction==='IN'?'1':'0.25','notes'=>'Synthetic QA adjustment',...($direction==='IN'?['total_cost'=>'120']:[])];}
    public function test_schema_restart_preserves_evidence_and_rejects_incompatible_precision():void
    {
        app(BaseAdjustmentService::class)->post(1,1,1,$this->input());$before=$this->snapshot();
        (require base_path('database/uom-migrations/2026_10_25_000006_base_adjustments.php'))->up();self::assertSame($before,$this->snapshot());
        DB::unprepared('ALTER TABLE uom_inventory_adjustments MODIFY total_cost DECIMAL(18,2) NOT NULL DEFAULT 0');
        try{(require base_path('database/uom-migrations/2026_10_25_000006_base_adjustments.php'))->up();self::fail('Incompatible precision accepted');}
        catch(\RuntimeException $e){self::assertSame('UOM_ADJUSTMENT_SCHEMA_PRECISION',$e->getMessage());}
    }
    public function test_adjustment_cannot_cancel_its_inventory_gl_with_the_same_offset_account():void
    {
        DB::table('accounting_settings')->where('company_id',1)->where('setting_key','INVENTORY_ADJUSTMENT_ACCOUNT')->update(['account_id'=>11]);$before=$this->snapshot();
        try{app(BaseAdjustmentService::class)->post(1,1,1,$this->input());self::fail('Inventory GL offset against itself');}
        catch(\RuntimeException $e){self::assertSame('UOM_ADJUSTMENT_ACCOUNT_CONFLICT',$e->getMessage());}
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());
    }
    private function snapshot():string
    {$out=[];foreach(['uom_inventory_adjustments','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','sulb_document_sequences'] as $table)$out[$table]=DB::table($table)->orderBy('id')->get()->all();return serialize($out);}
    private function books(string $q,string $v):void
    {
        self::assertSame(0,bccomp($q,(string)DB::table('inventory_lots')->sum('remaining_base_qty'),6));self::assertSame(0,bccomp($v,(string)DB::table('inventory_lots')->sum('uom_remaining_value'),3));
        self::assertSame(0,bccomp($v,DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first()->v,3));self::assertSame(0,DB::transactionLevel());
    }
    public function test_large_money_that_legacy_gl_cannot_preserve_is_rolled_back_without_guessing():void
    {
        $before=$this->snapshot();
        try{app(BaseAdjustmentService::class)->post(1,1,1,array_replace($this->input(),['total_cost'=>'123456789012.123']));self::fail('Imprecise journal committed');}
        catch(\RuntimeException $e){self::assertSame('UOM_ADJUSTMENT_GL_PRECISION',$e->getMessage());}
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());
        app(BaseAdjustmentService::class)->post(1,1,1,array_replace($this->input(),['total_cost'=>'120.001']));$this->books('12','120.001');
    }
    public function test_adjustments_and_void_preserve_original_fifo_cost_and_retry_key_after_factor_revision():void
    {
        $s=app(BaseAdjustmentService::class);$in=$s->post(1,1,1,$this->input());$out=$s->post(1,1,1,$this->input('OUT'));$this->books('9','90');self::assertSame('3.000000',$out['base_quantity']);
        app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,1,2,'24');$before=$this->snapshot();
        self::assertSame($in['adjustment_id'],$s->post(1,1,1,$this->input())['adjustment_id']);self::assertSame($out['adjustment_id'],$s->post(1,1,1,$this->input('OUT'))['adjustment_id']);self::assertSame($before,$this->snapshot());
        try{$s->void(1,1,$in['adjustment_id'],1,'QA reject consumed receipt');self::fail('Consumed receipt reversed');}catch(\RuntimeException $e){self::assertSame('UOM_ADJUSTMENT_ALREADY_ISSUED',$e->getMessage());}self::assertSame($before,$this->snapshot());
        $s->void(1,1,$out['adjustment_id'],1,'QA restore original FIFO issue');$this->books('12','120');$s->void(1,1,$in['adjustment_id'],1,'QA remove original adjustment');$this->books('0','0');
        $before=$this->snapshot();$s->void(1,1,$in['adjustment_id'],1,'QA idempotent adjustment reversal');self::assertSame($before,$this->snapshot());
        foreach(DB::table('uom_inventory_adjustments')->get() as $command)self::assertSame([(int)$command->reversal_journal_entry_id],DB::table('stock_movements')->where('source_type','UOM_ADJUSTMENT_REVERSAL')->where('source_id',$command->id)->distinct()->pluck('journal_entry_id')->map(fn($id)=>(int)$id)->all());
    }
    public function test_late_failure_rolls_back_lot_stock_journal_request_and_sequence():void
    {
        $s=app(BaseAdjustmentService::class);$before=$this->snapshot();DB::unprepared("CREATE TRIGGER qa_adjustment_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA adjustment journal failure'");
        try{$s->post(1,1,1,$this->input());self::fail('Partial adjustment committed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('QA adjustment journal failure',$e->getMessage());}
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());DB::unprepared('DROP TRIGGER qa_adjustment_failure');$s->post(1,1,1,$this->input());$this->books('12','120');
    }
    public function test_request_conflicts_foreign_scope_and_unknown_roots_leave_all_evidence_unchanged():void
    {
        $s=app(BaseAdjustmentService::class);$s->post(1,1,1,$this->input());$before=$this->snapshot();
        try{$s->post(1,1,1,array_replace($this->input(),['quantity'=>'2']));self::fail('Key reused with different quantity');}catch(\RuntimeException $e){self::assertSame('UOM_ADJUSTMENT_REQUEST_CONFLICT',$e->getMessage());}
        foreach([[2,2],[1,2]] as [$company,$branch]){try{$s->post($company,$branch,1,$this->input());self::fail('Foreign adjustment accepted');}catch(\RuntimeException $e){self::assertNotEmpty($e->getMessage());}}
        DB::beginTransaction();try{try{$s->post(1,1,1,$this->input('OUT'));self::fail('Unknown owner accepted');}catch(\RuntimeException $e){self::assertSame('UOM_TRUSTED_ADJUSTMENT_ROOT_REQUIRED',$e->getMessage());}self::assertSame(1,DB::transactionLevel());}finally{DB::rollBack();}
        self::assertSame($before,$this->snapshot());
    }
    public function test_two_fifo_lots_are_restored_with_their_exact_costs_on_void():void
    {
        $s=app(BaseAdjustmentService::class);$s->post(1,1,1,$this->input());$s->post(1,1,1,array_replace($this->input(),['request_key'=>'QA-IN-SECOND','unit_code'=>'PCS','quantity'=>'1','total_cost'=>'20']));
        $out=$s->post(1,1,1,array_replace($this->input('OUT'),['unit_code'=>'PCS','quantity'=>'13']));$this->books('0','0');self::assertSame('140.000',$out['total_cost']);
        $s->void(1,1,$out['adjustment_id'],1,'QA restore two FIFO adjustment lots');$this->books('13','140');
        self::assertSame(['120.000','20.000'],DB::table('inventory_lots')->orderBy('id')->pluck('uom_remaining_value')->all());
    }
    public function test_http_support_adjustment_and_void_are_scoped_and_audit_atomic():void
    {
        config(['app.debug'=>false]);
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();
        $caps=['POST:api/inventory/adjustment','POST:api/inventory/adjustments/{id}/void'];$read=\Tests\Support\UomSupportActor::token($caps,'READ_ONLY');$write=\Tests\Support\UomSupportActor::token($caps);$foreign=\Tests\Support\UomSupportActor::token($caps,'WRITE',2);
        $send=function(string $path,string $token,array $body){\Tests\Support\UomSupportActor::forgetAuth();return $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/'.$path,$body);};
        $before=$this->snapshot();$send('inventory/adjustment',$read,$this->input())->assertForbidden();self::assertSame($before,$this->snapshot());
        DB::unprepared("CREATE TRIGGER qa_adjustment_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA adjustment audit failure'; END IF; END");
        $failed=$send('inventory/adjustment',$write,$this->input())->assertStatus(500);self::assertStringNotContainsString('SQLSTATE',$failed->getContent());self::assertSame($before,$this->snapshot());DB::unprepared('DROP TRIGGER qa_adjustment_audit_failure');
        $id=$send('inventory/adjustment',$write,$this->input())->assertOk()->json('data.adjustment_id');$this->books('12','120');
        $before=$this->snapshot();$send('inventory/adjustments/'.$id.'/void',$foreign,['reason'=>'QA foreign company reversal'])->assertNotFound();self::assertSame($before,$this->snapshot());
        $send('inventory/adjustments/'.$id.'/void',$write,['reason'=>'QA support adjustment reversal'])->assertOk();$this->books('0','0');
    }
    public function test_two_real_adjustment_posts_wait_and_apply_one_request_only():void
    {
        app(BaseAdjustmentService::class)->post(1,1,1,$this->input());require_once __DIR__.'/../../Support/BaseAdapterRace.php';
        $race=\Tests\Support\BaseAdapterRace::run('ADJUSTMENT_OUT','items',1);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);
        self::assertSame(2,DB::table('uom_inventory_adjustments')->count());self::assertSame(2,DB::table('journal_entries')->count());$this->books('9','90');
    }
    public function test_two_real_adjustment_voids_wait_and_reverse_once():void
    {
        $in=app(BaseAdjustmentService::class)->post(1,1,1,$this->input());require_once __DIR__.'/../../Support/BaseAdapterRace.php';
        $race=\Tests\Support\BaseAdapterRace::run('ADJUSTMENT_VOID','uom_inventory_adjustments',$in['adjustment_id']);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);
        self::assertSame(2,DB::table('journal_entries')->count());$this->books('0','0');
    }
    public function test_real_financial_year_timeout_after_fifo_rolls_back_every_adjustment_effect():void
    {
        $s=app(BaseAdjustmentService::class);$s->post(1,1,1,$this->input());$before=$this->snapshot();
        $fifoWrites=0;DB::listen(function($query)use(&$fifoWrites){if(str_starts_with($query->sql,'insert into `inventory_lot_movements`')&&in_array('UOM_ADJUSTMENT',$query->bindings,true))$fifoWrites++;});
        $holder=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$holder->beginTransaction();$holder->query('SELECT id FROM financial_years WHERE id=1 FOR UPDATE')->fetchAll();DB::statement('SET SESSION innodb_lock_wait_timeout=1');
        try{try{$s->post(1,1,1,$this->input('OUT'));self::fail('Held FY ignored');}catch(\Throwable $e){$code=0;for($cause=$e;$cause;$cause=$cause->getPrevious())if($cause instanceof \PDOException)$code=(int)($cause->errorInfo[1]??$code);self::assertSame(1205,$code);}
            self::assertGreaterThan(0,$fifoWrites);self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        }finally{$holder->rollBack();DB::statement('SET SESSION innodb_lock_wait_timeout=50');}
        $s->post(1,1,1,$this->input('OUT'));$this->books('9','90');
    }
}
