<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\OpeningBalanceService;
use App\Services\EnterpriseInvoiceService;

final class BaseOpeningAdapterTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        (require base_path('database/uom-migrations/2026_10_25_000005_opening_snapshots.php'))->up();
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function draft(int $item=1,string $unit='CARTON',string $qty='1',string $cost='120'):int
    {return app(OpeningBalanceService::class)->saveDraft(1,['financial_year_id'=>1,'opening_date'=>'2026-10-25','inventory_lines'=>[['branch_id'=>1,'item_id'=>$item,'unit_code'=>$unit,'quantity'=>$qty,'total_cost'=>$cost]]],1,1);}
    private function snapshot():string
    {$out=[];foreach(['opening_balance_batches','opening_inventory_lines','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','sulb_document_sequences'] as $table)$out[$table]=DB::table($table)->orderBy('id')->get()->all();return serialize($out);}
    public function test_opening_rejects_money_that_existing_journal_cannot_preserve_without_partial_draft():void
    {
        $before=$this->snapshot();try{$this->draft(1,'CARTON','1','123456789012.123');self::fail('Unrepresentable money accepted');}
        catch(\RuntimeException $e){self::assertSame('UOM_OPENING_GL_PRECISION',$e->getMessage());}
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());
    }
    private function books(string $q,string $v):void
    {
        self::assertSame(0,bccomp($q,DB::table('inventory_lots')->sum('remaining_base_qty'),6));self::assertSame(0,bccomp($v,DB::table('inventory_lots')->sum('uom_remaining_value'),3));
        self::assertSame(0,bccomp($v,DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v,3));self::assertSame(0,DB::transactionLevel());
    }
    public function test_opening_snapshot_sale_return_and_reversal_match_fifo_and_gl():void
    {
        $id=$this->draft();app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $s=app(OpeningBalanceService::class);$s->post(1,$id,1,true,1);$this->books('12','120');$before=$this->snapshot();$s->post(1,$id,1,true,1);self::assertSame($before,$this->snapshot());
        $invoices=app(EnterpriseInvoiceService::class);$sale=$invoices->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>'5','unit_code'=>'PCS','unit_price'=>'15','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);$invoices->post('SALE',1,$sale,1,1);$this->books('7','70');
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');$returns=app(\App\Services\CommercialReturnService::class);
        $return=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-25','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'2']]]);$returns->post(1,$return,1,1);$this->books('9','90');
        $returns->void(1,$return,1,'QA opening source return reversal',1);$invoices->void('SALE',1,$sale,1,'QA opening source sale reversal',1);$this->books('12','120');
        self::assertSame($id,(int)DB::table('inventory_lots')->value('opening_balance_batch_id'));
    }
    public function test_late_lot_failure_rolls_back_journal_sequence_and_opening_status():void
    {
        $id=$this->draft();$before=$this->snapshot();DB::unprepared("CREATE TRIGGER qa_opening_late_failure BEFORE INSERT ON inventory_lot_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA opening LOT failure'");
        try{app(OpeningBalanceService::class)->post(1,$id,1,true,1);self::fail('Partial opening committed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('QA opening LOT failure',$e->getMessage());}
        self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());DB::unprepared('DROP TRIGGER qa_opening_late_failure');
        app(OpeningBalanceService::class)->post(1,$id,1,true,1);$this->books('12','120');
    }
    public function test_unknown_owner_and_foreign_scopes_cannot_post_opening():void
    {
        $id=$this->draft();$before=$this->snapshot();$s=app(OpeningBalanceService::class);DB::beginTransaction();
        try{try{$s->post(1,$id,1,true,1);self::fail('Unknown root trusted');}catch(\RuntimeException $e){self::assertSame('UOM_TRUSTED_OPENING_ROOT_REQUIRED',$e->getMessage());}self::assertSame(1,DB::transactionLevel());}finally{DB::rollBack();}
        foreach([[2,2],[1,2]] as [$company,$branch]){try{$s->post($company,$id,1,true,$branch);self::fail('Foreign opening posted');}catch(\RuntimeException $e){self::assertNotEmpty($e->getMessage());}}
        self::assertSame($before,$this->snapshot());
    }
    public function test_tiny_length_opening_preserves_quantity_and_money():void
    {
        $units=app(\App\Services\Uom\ItemUnitService::class);$units->registerNew(1,2,3);$units->reviseMapping(1,2,3,'1');$id=$this->draft(2,'M','0.000001','1.001');
        app(OpeningBalanceService::class)->post(1,$id,1,true,1);$this->books('0.000001','1.001');self::assertSame('M',DB::table('opening_inventory_lines')->value('unit_code'));
    }
    public function test_two_real_opening_posts_wait_then_create_one_lot_and_one_journal():void
    {
        $id=$this->draft();require_once __DIR__.'/../../Support/BaseAdapterRace.php';$race=\Tests\Support\BaseAdapterRace::run('OPENING_POST','opening_balance_batches',$id);
        self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);self::assertSame(1,DB::table('inventory_lots')->count());self::assertSame(1,DB::table('journal_entries')->count());$this->books('12','120');
    }
    public function test_inventory_view_permission_cannot_post_a_financial_adjustment():void
    {
        DB::table('roles')->insert(['id'=>3,'role_code'=>'INVENTORY_VIEWER','role_name'=>'QA viewer']);DB::table('users')->insert(['id'=>3,'company_id'=>1,'branch_id'=>1,'name'=>'QA view only','username'=>'qa-inventory-view','password'=>'synthetic']);DB::table('user_roles')->insert(['user_id'=>3,'role_id'=>3,'company_id'=>1]);
        $permission=DB::table('permissions')->insertGetId(['permission_code'=>'inventory.view','permission_name'=>'View only','permission_scope'=>'COMPANY']);DB::table('role_permissions')->insert(['role_id'=>3,'permission_id'=>$permission,'company_id'=>1]);
        DB::table('plans')->insert(['id'=>99,'plan_code'=>'OPEN-QA','plan_name'=>'QA']);DB::table('subscriptions')->insert(['company_id'=>1,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach(['inventory','accounting'] as $feature)DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
        $before=$this->snapshot();$this->actingAs(\App\Models\User::findOrFail(3),'sanctum');
        $this->postJson('/api/inventory/adjustment',['item_id'=>2,'branch_id'=>1,'movement_type'=>'IN','qty_kg'=>1,'unit_cost_per_ton'=>1000,'notes'=>'QA forbidden financial adjustment'])->assertForbidden()->assertJsonPath('required_permission','inventory.process.post');
        self::assertSame($before,$this->snapshot());
    }
    public function test_support_opening_uses_trusted_owner_and_rolls_back_audit_failure():void
    {
        config(['app.debug'=>false]);
        $id=$this->draft();require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();
        $caps=['POST:api/opening-balances/{id}/post'];$read=\Tests\Support\UomSupportActor::token($caps,'READ_ONLY');$foreign=\Tests\Support\UomSupportActor::token($caps,'WRITE',2);$write=\Tests\Support\UomSupportActor::token($caps);
        $send=function($auth)use($id){\Tests\Support\UomSupportActor::forgetAuth();return $this->withHeaders(['Authorization'=>'Bearer '.$auth])->postJson('/api/opening-balances/'.$id.'/post',['confirm_balancing'=>true]);};
        $before=$this->snapshot();$send($read)->assertForbidden();$send($foreign)->assertNotFound();self::assertSame($before,$this->snapshot());
        DB::unprepared("CREATE TRIGGER qa_opening_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA opening audit failure'; END IF; END");
        $failed=$send($write)->assertStatus(500);self::assertStringNotContainsString('SQLSTATE',$failed->getContent());self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());DB::unprepared('DROP TRIGGER qa_opening_audit_failure');
        $send($write)->assertOk();$this->books('12','120');self::assertSame(1,DB::table('journal_entries')->count());
    }
}
