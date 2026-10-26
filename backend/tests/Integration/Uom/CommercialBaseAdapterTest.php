<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use App\Services\CommercialDocumentService;
use App\Services\Uom\ItemUnitService;

final class CommercialBaseAdapterTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        (require base_path('database/uom-migrations/2026_10_25_000004_commercial_snapshots.php'))->up();
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function document(string $type,string $qty):int
    {
        return app(CommercialDocumentService::class)->save($type,['supplier_id'=>1,'customer_id'=>1,'document_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>$qty,'unit_code'=>'CARTON','unit_price'=>'120','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
    }
    private function approve(string $type,int $id):void
    {
        $s=app(CommercialDocumentService::class);
        if($type==='QUOTATION'){$s->transition($type,1,$id,1,'SENT',1);$s->transition($type,1,$id,1,'ACCEPTED',1);}
        else $s->transition($type,1,$id,1,'APPROVED',1);
    }
    private function books(string $qty,string $value):void
    {
        self::assertSame(0,bccomp($qty,DB::table('inventory_lots')->sum('remaining_base_qty'),6));
        self::assertSame(0,bccomp($value,DB::table('inventory_lots')->sum('uom_remaining_value'),3));
        $gl=DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) value')->first();self::assertSame(0,bccomp($value,$gl->value,3));
        foreach(DB::table('journal_entries')->get() as $entry){$v=DB::table('journal_entry_lines')->where('journal_entry_id',$entry->id)->selectRaw('SUM(debit-credit) value')->first();self::assertSame(0,bccomp('0',$v->value,3));}
        self::assertSame(0,DB::transactionLevel());
    }
    public function test_purchase_order_frozen_conversion_posts_and_returns_with_original_carton_factor():void
    {
        $order=$this->document('PURCHASE_ORDER','1');$this->approve('PURCHASE_ORDER',$order);
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $s=app(CommercialDocumentService::class);$invoice=$s->convert('PURCHASE_ORDER',1,$order,1,1)['invoice_id'];
        self::assertSame($invoice,$s->convert('PURCHASE_ORDER',1,$order,1,1)['invoice_id']);
        $line=DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$invoice)->first();
        self::assertSame('12.000000',$line->base_quantity);self::assertSame('12.000000',$line->uom_factor_snapshot);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$invoice,1,1);$this->books('12','120');
        $returns=app(\App\Services\CommercialReturnService::class);
        $return=$returns->saveDraft(1,1,1,['return_type'=>'PURCHASE_RETURN','source_invoice_id'=>$invoice,'return_date'=>'2026-10-25','lines'=>[['source_invoice_line_id'=>$line->id,'quantity'=>'0.25']]]);
        $returns->post(1,$return,1,1);$this->books('9','90');
        $returns->void(1,$return,1,'QA original captured return reversal',1);$this->books('12','120');
        app(EnterpriseInvoiceService::class)->void('PURCHASE',1,$invoice,1,'QA captured order purchase reversal',1);$this->books('0','0');
    }
    public function test_quotation_conversion_consumes_original_base_quantity_and_reverses_exact_fifo():void
    {
        $order=$this->document('PURCHASE_ORDER','1');$this->approve('PURCHASE_ORDER',$order);
        $p=app(CommercialDocumentService::class)->convert('PURCHASE_ORDER',1,$order,1,1)['invoice_id'];app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $quote=$this->document('QUOTATION','0.5');$this->approve('QUOTATION',$quote);app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $sale=app(CommercialDocumentService::class)->convert('QUOTATION',1,$quote,1,1)['invoice_id'];
        app(EnterpriseInvoiceService::class)->post('SALE',1,$sale,1,1);$this->books('6','60');
        self::assertSame('6.000000',DB::table('sales_line_lot_sources')->value('base_quantity'));
        app(EnterpriseInvoiceService::class)->void('SALE',1,$sale,1,'QA quotation sale reversal',1);$this->books('12','120');
    }
    public function test_conversion_failure_and_foreign_company_leave_source_and_invoice_tables_unchanged():void
    {
        $id=$this->document('PURCHASE_ORDER','1');$this->approve('PURCHASE_ORDER',$id);$s=app(CommercialDocumentService::class);
        try{$s->convert('PURCHASE_ORDER',2,$id,2,2);self::fail('Foreign source converted');}catch(\RuntimeException $e){self::assertNotEmpty($e->getMessage());}
        DB::unprepared("CREATE TRIGGER qa_capture_failure BEFORE INSERT ON purchase_invoice_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA capture failure'");
        try{$s->convert('PURCHASE_ORDER',1,$id,1,1);self::fail('Injected failure committed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('QA capture failure',$e->getMessage());}
        self::assertSame(0,DB::table('purchase_invoices')->count());self::assertNull(DB::table('purchase_orders')->where('id',$id)->value('converted_invoice_id'));
        self::assertSame('APPROVED',DB::table('purchase_orders')->where('id',$id)->value('status'));self::assertSame(0,DB::transactionLevel());
        DB::unprepared('DROP TRIGGER qa_capture_failure');self::assertGreaterThan(0,$s->convert('PURCHASE_ORDER',1,$id,1,1)['invoice_id']);
    }
    public function test_import_preserves_decimal_strings_and_freezes_mapping_before_later_post():void
    {
        DB::table('company_settings')->where('company_id',1)->update(['default_customer_id'=>1,'default_supplier_id'=>1,'default_customer_account_id'=>33,'default_supplier_account_id'=>12]);
        $row=['invoice_number'=>'QA-IMPORT-BASE','invoice_date'=>'2026-10-25','item_code'=>'LOCK-1','quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'120.123456','currency_code'=>'SAR'];
        $mapping=array_combine(array_keys($row),array_keys($row));$parsed=['headers'=>array_keys($row),'rows'=>[['row_number'=>2,'data'=>$row]]];
        $s=app(\App\Services\MigrationCenterService::class);$result=$s->import(1,1,1,'purchase_invoices',$parsed,$mapping,[],['name'=>'synthetic.csv']);
        self::assertSame(1,$result['stats']['imported']);$line=DB::table('purchase_invoice_lines')->sole();self::assertSame('12.000000',$line->base_quantity);self::assertSame('120.123456',$line->uom_price_snapshot);
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$line->purchase_invoice_id,1,1);$this->books('12','120.123');
        $again=$s->import(1,1,1,'purchase_invoices',$parsed,$mapping,[],['name'=>'synthetic.csv']);self::assertSame(1,$again['stats']['skipped']);self::assertSame(1,DB::table('purchase_invoices')->count());
    }
    public function test_support_conversion_enforces_capability_company_and_atomic_audit():void
    {
        $order=$this->document('PURCHASE_ORDER','1');$this->approve('PURCHASE_ORDER',$order);
        DB::table('users')->insert(['id'=>99,'name'=>'QA commercial support','username'=>'qa-commercial-support','password'=>'synthetic','is_active'=>1]);
        DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'QA platform']);DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'is_active'=>1]);
        DB::table('plans')->insert(['id'=>99,'plan_code'=>'COMMERCIAL-QA','plan_name'=>'QA']);
        foreach([1,2] as $company)DB::table('subscriptions')->insert(['company_id'=>$company,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach(['purchases','sales','inventory','accounting'] as $feature)DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
        $token=function(string $access,int $company=1){$session=(string)\Illuminate\Support\Str::uuid();$t=\App\Models\User::findOrFail(99)->createToken('QA commercial',['session','support-mode','support-session:'.$session,'support-company:'.$company,'support-branch:'.$company],now()->addHour());
            DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>$company,'branch_id'=>$company,'personal_access_token_id'=>$t->accessToken->id,'access_level'=>$access,'capabilities_json'=>json_encode(['POST:api/purchase-orders/{id}/convert-to-invoice','POST:api/purchase-invoices/{id}/post']),'reason'=>'QA adapter test','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);return $t->plainTextToken;};
        $read=$token('READ_ONLY');$foreign=$token('WRITE',2);$write=$token('WRITE');
        $send=function(string $path,string $auth){\Illuminate\Support\Facades\Auth::guard('web')->logout();\Illuminate\Support\Facades\Auth::forgetGuards();return $this->withHeaders(['Authorization'=>'Bearer '.$auth])->postJson('/api/'.$path);};
        $path='purchase-orders/'.$order.'/convert-to-invoice';$send($path,$read)->assertForbidden();$send($path,$foreign)->assertNotFound();self::assertSame(0,DB::table('purchase_invoices')->count());
        DB::unprepared("CREATE TRIGGER qa_commercial_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA commercial audit failure'; END IF; END");
        try{$send($path,$write); }catch(\Throwable $e){self::assertNotEmpty($e->getMessage());}
        self::assertSame(0,DB::table('purchase_invoices')->count());self::assertNull(DB::table('purchase_orders')->where('id',$order)->value('converted_invoice_id'));
        self::assertSame(0,DB::transactionLevel());DB::unprepared('DROP TRIGGER qa_commercial_audit_failure');
        $invoice=$send($path,$write)->assertOk()->json('data.invoice_id');
        $send('purchase-invoices/'.$invoice.'/post',$write)->assertOk();$this->books('12','120');
        self::assertSame(2,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
    }
    public function test_two_real_converters_wait_then_return_one_invoice_and_one_frozen_source():void
    {
        $id=$this->document('PURCHASE_ORDER','1');$this->approve('PURCHASE_ORDER',$id);app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        require_once __DIR__.'/../../Support/BaseAdapterRace.php';$race=\Tests\Support\BaseAdapterRace::run('ORDER_CONVERT','purchase_orders',$id);
        self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);self::assertSame(1,DB::table('purchase_invoices')->count());
        self::assertSame('12.000000',DB::table('purchase_invoice_lines')->value('base_quantity'));self::assertSame(0,DB::transactionLevel());
    }
    public function test_existing_legacy_quote_blocks_base_enrollment_in_service_and_database():void
    {
        app(CommercialDocumentService::class)->save('QUOTATION',['customer_id'=>1,'document_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>2,'quantity'=>1,'unit_code'=>'TON','price_unit'=>'TON','unit_price'=>15,'vat_percent'=>0]]],1,1,1);
        try{app(ItemUnitService::class)->registerNew(1,2,1);self::fail('Legacy commercial identity changed');}catch(\RuntimeException $e){self::assertSame('UOM_NEW_ITEM_REQUIRED',$e->getMessage());}
        try{DB::table('uom_item_modes')->insert(['company_id'=>1,'item_id'=>2,'mode'=>'BASE_ONLY']);self::fail('Database commercial history gate bypassed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('UOM_NEW_ITEM_REQUIRED',$e->getMessage());}
        self::assertSame('LEGACY_KG',DB::table('items')->where('id',2)->value('inventory_quantity_version'));
    }
    public function test_commercial_schema_restart_preserves_snapshots_and_rejects_factor_tampering():void
    {
        $id=$this->document('PURCHASE_ORDER','1');$before=serialize(DB::table('purchase_order_lines')->get()->all());
        (require base_path('database/uom-migrations/2026_10_25_000004_commercial_snapshots.php'))->up();self::assertSame($before,serialize(DB::table('purchase_order_lines')->get()->all()));
        try{DB::table('purchase_order_lines')->where('purchase_order_id',$id)->update(['uom_factor_snapshot'=>'24','base_quantity'=>'24']);self::fail('Frozen mapping factor changed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('UOM_COMMERCIAL_VERSION_SCOPE',$e->getMessage());}
        self::assertSame($before,serialize(DB::table('purchase_order_lines')->get()->all()));
    }
    public function test_support_conversion_real_timeout_cannot_commit_a_partial_invoice_header():void
    {
        config(['app.debug'=>false]);$id=$this->document('PURCHASE_ORDER','1');$this->approve('PURCHASE_ORDER',$id);
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$token=\Tests\Support\UomSupportActor::token(['POST:api/purchase-orders/{id}/convert-to-invoice']);
        $snapshot=function(){$out=[];foreach(['purchase_orders','purchase_order_lines','purchase_invoices','purchase_invoice_lines','sulb_document_sequences'] as $table)$out[$table]=DB::table($table)->orderBy('id')->get()->all();return serialize($out);};$before=$snapshot();
        $headersInserted=0;DB::listen(function($query)use(&$headersInserted){if(str_starts_with($query->sql,'insert into `purchase_invoices`'))$headersInserted++;});
        $holder=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$holder->beginTransaction();$holder->query('SELECT id FROM items WHERE id=1 FOR UPDATE')->fetchAll();DB::statement('SET SESSION innodb_lock_wait_timeout=1');
        \Tests\Support\UomSupportActor::forgetAuth();
        try{$response=$this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/purchase-orders/'.$id.'/convert-to-invoice')->assertUnprocessable();self::assertStringContainsString('1205',$response->getContent());self::assertStringNotContainsString('SQLSTATE',$response->getContent());self::assertSame(1,$headersInserted);self::assertSame($before,$snapshot());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());}
        finally{$holder->rollBack();DB::statement('SET SESSION innodb_lock_wait_timeout=50');}
        \Tests\Support\UomSupportActor::forgetAuth();$this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/purchase-orders/'.$id.'/convert-to-invoice')->assertOk();self::assertSame(1,DB::table('purchase_invoices')->count());self::assertSame(1,DB::table('purchase_invoice_lines')->count());
    }
}
