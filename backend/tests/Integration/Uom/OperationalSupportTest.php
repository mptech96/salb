<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use App\Services\Uom\OperationalTransactionOwner;

final class OperationalSupportTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';
        \Tests\Support\OperationalUomFixture::install();
        DB::table('users')->insert(['id'=>99,'name'=>'QA support','username'=>'uom-support','password'=>'synthetic','is_active'=>1]);
        DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'QA platform']);
        DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'is_active'=>1]);
        DB::table('plans')->insert(['id'=>99,'plan_code'=>'UOM-SUPPORT','plan_name'=>'QA']);
        foreach([1,2] as $company)DB::table('subscriptions')->insert(['company_id'=>$company,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach(['purchases','sales','inventory','accounting'] as $feature)DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
    }
    private function invoice(string $type):int
    {
        return app(EnterpriseInvoiceService::class)->saveDraft($type,[($type==='SALE'?'customer_id':'supplier_id')=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>$type==='SALE'?'5':'1','unit_code'=>$type==='SALE'?'PCS':'CARTON','unit_price'=>$type==='SALE'?'15':'120','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
    }
    private function token(string $access='WRITE',int $company=1):string
    {
        $session=(string)\Illuminate\Support\Str::uuid();$token=\App\Models\User::findOrFail(99)->createToken('QA UOM',['session','support-mode','support-session:'.$session,'support-company:'.$company,'support-branch:'.$company],now()->addHour());
        $capabilities=[];foreach(['purchase-invoices','sales-invoices','commercial-returns'] as $resource)foreach(['post','void'] as $action)$capabilities[]='POST:api/'.$resource.'/{id}/'.$action;
        DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>$company,'branch_id'=>$company,'personal_access_token_id'=>$token->accessToken->id,'access_level'=>$access,'capabilities_json'=>json_encode($capabilities),'reason'=>'Synthetic UOM acceptance','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);
        return $token->plainTextToken;
    }
    private function requestSupport(string $path,string $token,array $body=[])
    {
        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        return $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/'.$path,$body);
    }
    private function financial():string
    {
        $rows=[];foreach(['purchase_invoices','sales_invoices','commercial_returns','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','sales_line_lot_sources','sulb_document_sequences'] as $table)$rows[$table]=DB::table($table)->orderBy('id')->get();return serialize($rows);
    }
    public function test_support_full_base_cycle_and_return_reversal_keep_frozen_quantity_and_audit():void
    {
        $purchase=$this->invoice('PURCHASE');$sale=$this->invoice('SALE');$token=$this->token();
        $this->requestSupport('purchase-invoices/'.$purchase.'/post',$token)->assertOk();
        $this->requestSupport('sales-invoices/'.$sale.'/post',$token)->assertOk();
        self::assertSame('7.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        // Draft preparation remains a normal tenant operation; only posting uses Support root.
        \Illuminate\Support\Facades\Auth::guard('web')->login(\App\Models\User::findOrFail(1));
        $return=app(\App\Services\CommercialReturnService::class)->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>DB::table('sales_invoice_lines')->value('id'),'quantity'=>'2']]]);
        $this->requestSupport('commercial-returns/'.$return.'/post',$token)->assertOk();
        self::assertSame('9.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        $this->requestSupport('commercial-returns/'.$return.'/void',$token,['reason'=>'QA reverse frozen return'])->assertOk();
        $this->requestSupport('sales-invoices/'.$sale.'/void',$token,['reason'=>'QA reverse frozen sale'])->assertOk();
        self::assertSame('12.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('120.000',DB::table('inventory_lots')->value('uom_remaining_value'));
        foreach(DB::table('journal_entries')->get() as $journal){$sum=DB::table('journal_entry_lines')->where('journal_entry_id',$journal->id)->selectRaw('SUM(debit) d,SUM(credit) c')->first();self::assertSame(0,bccomp($sum->d,$sum->c,3));}
        self::assertSame(5,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
    public function test_support_denial_and_late_failure_never_commit_financial_work():void
    {
        $purchase=$this->invoice('PURCHASE');$before=$this->financial();
        $this->requestSupport('purchase-invoices/'.$purchase.'/post',$this->token('READ_ONLY'))->assertForbidden();
        self::assertSame($before,$this->financial());
        $this->requestSupport('purchase-invoices/'.$purchase.'/post',$this->token('WRITE',2))->assertNotFound();
        self::assertSame($before,$this->financial());
        DB::unprepared("CREATE TRIGGER qa_uom_late_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA late posting failure'");
        $this->requestSupport('purchase-invoices/'.$purchase.'/post',$this->token())->assertUnprocessable();
        self::assertSame($before,$this->financial());
        self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
    public function test_unknown_pdo_owner_is_rejected_without_rolling_it_back():void
    {
        $purchase=$this->invoice('PURCHASE');$pdo=DB::connection()->getPdo();$pdo->beginTransaction();
        try{
            try{app(OperationalTransactionOwner::class)->run(1,$purchase,1,'PURCHASE_POST',fn()=>self::fail('Foreign owner adopted'));self::fail('Expected rejection');}
            catch(\RuntimeException $error){self::assertSame('UOM_OUTERMOST_REQUIRED',$error->getMessage());}
            self::assertTrue($pdo->inTransaction());
        }finally{$pdo->rollBack();}
    }
    public function test_real_support_lock_timeout_rolls_back_and_confirmed_retry_posts_once():void
    {
        $purchase=$this->invoice('PURCHASE');$token=$this->token();$before=$this->financial();
        $peer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $peer->beginTransaction();$peer->query('SELECT id FROM items WHERE id=1 FOR UPDATE')->fetchAll();
        DB::statement('SET SESSION innodb_lock_wait_timeout=1');
        try{$this->requestSupport('purchase-invoices/'.$purchase.'/post',$token)->assertUnprocessable();}
        finally{$peer->rollBack();DB::statement('SET SESSION innodb_lock_wait_timeout=50');}
        self::assertSame($before,$this->financial());
        self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        $this->requestSupport('purchase-invoices/'.$purchase.'/post',$token)->assertOk();
        $posted=$this->financial();$this->requestSupport('purchase-invoices/'.$purchase.'/post',$token)->assertOk();
        self::assertSame($posted,$this->financial());self::assertSame(1,DB::table('inventory_lots')->count());
    }
    public function test_support_success_audit_failure_rolls_back_posting_before_commit():void
    {
        $purchase=$this->invoice('PURCHASE');$before=$this->financial();
        DB::unprepared("CREATE TRIGGER qa_uom_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA success audit failure'; END IF; END");
        $this->requestSupport('purchase-invoices/'.$purchase.'/post',$this->token())->assertStatus(500);
        self::assertSame($before,$this->financial());
        self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
    public function test_real_support_base_deadlock_aborts_owner_without_partial_posting():void
    {
        $purchase=$this->invoice('PURCHASE');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);
        $sale=$this->invoice('SALE');$token=$this->token();$before=$this->financial();$worker=null;
        DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();
        $weight=[];for($n=0;$n<500;$n++)$weight[]=['company_id'=>1,'item_code'=>'QA-UOM-DEADLOCK-'.$n,'item_name'=>'Synthetic lock weight'];DB::table('items')->insert($weight);
        try{
            // Reuse the established HTTP probe; unlike its closed tests this invoice is BASE and unlinked.
            $worker=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/batch_two_support_sale_probe.php'),(string)$sale,$token,'real1213'],base_path(),['SULB_UOM_OPERATIONAL_ENABLED'=>'true']);
            $worker->setTimeout(20);$worker->start();$until=microtime(true)+10;
            while(!str_contains($worker->getOutput(),'SALE_REQUEST_ITEM_LOCK')){
                if(!$worker->isRunning()||microtime(true)>$until)throw new \RuntimeException('BASE deadlock barrier missing: '.$worker->getOutput());usleep(10000);
            }
            // Worker owns invoice S then requests I; this weighted peer owns I then requests S.
            DB::table('sales_invoices')->where('id',$sale)->lockForUpdate()->first();
        }finally{DB::rollBack();if($worker)$worker->wait();}
        self::assertSame(1,$worker->getExitCode(),$worker->getOutput());
        self::assertStringContainsString('1213',$worker->getOutput());self::assertStringContainsString('"status":422',$worker->getOutput());
        self::assertStringContainsString('"level":0,"pdo":false,"server":0',$worker->getOutput());
        self::assertSame($before,$this->financial());self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        fwrite(STDOUT,'BASE_SUPPORT_REAL_DEADLOCK '.$worker->getOutput());
    }
}
