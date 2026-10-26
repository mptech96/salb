<?php
namespace Tests\Integration\Uom;

use App\Models\User;
use App\Services\ShipmentTransactionOwner;
use App\Services\ShipmentPurchasePostingScope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

class TrustedSupportShipmentOwnershipTest extends ShipmentPurchaseLockGateTest
{
    protected function setUp():void
    {
        parent::setUp();
        // Candidate index ONLY in the guarded disposable fixture, not operational schema.
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');
        $migration->up();
    }
    protected function token(string $access='WRITE',array $capabilities=['POST:api/purchase-invoices/{id}/post'],int $company=1,int $branch=1):string
    {
        if(!DB::table('users')->where('id',99)->exists()) {
            DB::table('users')->insert(['id'=>99,'name'=>'Isolated support','username'=>'ownership-support','password'=>'synthetic','company_id'=>null,'branch_id'=>null,'is_active'=>1]);
            DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'Isolated platform']);
            DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'company_id'=>null,'is_active'=>1]);
            DB::table('plans')->insert(['id'=>99,'plan_name'=>'Isolated','plan_code'=>'OWNERSHIP']);
            foreach([1,2] as $cid)DB::table('subscriptions')->insert(['company_id'=>$cid,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
            DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'purchases','is_enabled'=>1]);
        }
        $session=(string)\Illuminate\Support\Str::uuid();
        $t=User::findOrFail(99)->createToken('isolated-owner',['session','support-mode','support-session:'.$session,'support-company:'.$company,'support-branch:'.$branch],now()->addHour());
        DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>$company,'branch_id'=>$branch,
            'personal_access_token_id'=>$t->accessToken->id,'access_level'=>$access,'capabilities_json'=>json_encode($capabilities),
            'reason'=>'Isolated ownership proof','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);
        return $t->plainTextToken;
    }
    protected function requestPost(string $token,int $id,array $data=[])
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($token)->postJson('/api/purchase-invoices/'.$id.'/post',$data);
    }
    protected function supportWorker(string $token,int $id,string $phase=''):Process
    {
        $p=new Process([PHP_BINARY,base_path('scripts/shipment_purchase_lock_worker.php'),'support',(string)$id,'current',$phase,$token],base_path());
        $p->setTimeout(25);$p->start();return $p;
    }
    public function test_real_sanctum_support_http_success_plain_repeat_and_owner_cleanup():void
    {
        $s=F::purchase();$token=$this->token();
        $this->requestPost($token,$s['invoice'])->assertOk();$this->financial($s);
        self::assertNull(app(ShipmentTransactionOwner::class)->scope(1,$s['invoice'],99));
        $this->requestPost($token,$s['invoice'])->assertOk();self::assertSame(2,DB::table('journal_entries')->count());
        self::assertSame(2,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        $plain=\Tests\Support\UomOperationalLockFixture::purchase(1,1);
        $this->requestPost($token,$plain)->assertOk();$this->balanced();
    }
    public function test_real_http_denials_cannot_forge_owner_or_tenant():void
    {
        $s=F::purchase();
        $this->requestPost($this->token('READ_ONLY',[]),$s['invoice'])->assertForbidden();
        $this->requestPost($this->token('WRITE',[]),$s['invoice'])->assertForbidden();
        // TenantResourceScopeService conceals foreign source existence with404.
        $this->requestPost($this->token('WRITE',['POST:api/purchase-invoices/{id}/post'],2,2),$s['invoice'],['company_id'=>1])->assertNotFound();
        DB::table('branches')->insert(['id'=>77,'company_id'=>1,'branch_name'=>'Other scoped branch']);
        $this->requestPost($this->token('WRITE',['POST:api/purchase-invoices/{id}/post'],1,77),$s['invoice'])->assertNotFound();
        $token=$this->token();
        $this->requestPost($token,$s['invoice'],['transaction_owner'=>'forged'])->assertUnprocessable()->assertSee('TRANSACTION_OWNER_NOT_ACCEPTED');
        DB::table('subscriptions')->where('company_id',1)->update(['status'=>'EXPIRED','end_date'=>now()->subMonth()->toDateString()]);
        $this->requestPost($token,$s['invoice'])->assertForbidden();
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('vouchers')->count());
        self::assertNull(app(ShipmentTransactionOwner::class)->scope(1,$s['invoice'],99));
    }
    public function test_real_http_financial_faults_and_audit_fault_roll_back_whole_owner():void
    {
        $token=$this->token();
        foreach(['fault-after-lot','fault-after-journal','fault-after-voucher'] as $phase) {
            $s=F::purchase();$p=$this->supportWorker($token,$s['invoice'],$phase);$p->wait();
            self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertSame(0,DB::table('journal_entries')->count());
            self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('vouchers')->count());
        }
        $s=F::purchase();$armed=true;
        DB::connection()->beforeExecuting(function($sql)use(&$armed){if($armed&&str_contains($sql,'insert into `audit_logs`'))throw new \RuntimeException('FAULT_SUPPORT_AUDIT');});
        try{$this->requestPost($token,$s['invoice'])->assertStatus(500);}finally{$armed=false;}
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('vouchers')->count());
        self::assertNull(app(ShipmentTransactionOwner::class)->scope(1,$s['invoice'],99));
        $this->requestPost($token,$s['invoice'])->assertOk();$this->financial($s);
    }
    public function test_support_concurrency_against_cost_opening_and_same_source():void
    {
        $token=$this->token();
        foreach(['cost','opening','support'] as $kind) {
            $s=F::purchase();$other=$kind==='cost'?F::shipment(false,true,2):null;
            $id=$kind==='cost'?$other['shipment']:($kind==='opening'?\Tests\Support\UomOperationalLockFixture::opening(1,1):$s['invoice']);
            $key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);
            $a=$this->supportWorker($token,$s['invoice'],'before-year');$b=null;
            try{$this->await($a,'BEFORE_YEAR');$b=$kind==='support'?$this->supportWorker($token,$id):$this->worker($kind,$id);$this->await($b,'LOCK_SQL');}
            finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
            self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());$this->financial($s);
        }
    }
    public function test_owner_mismatch_and_failed_callback_cannot_be_reused():void
    {
        $s=F::purchase();$owner=app(ShipmentTransactionOwner::class);$scope=ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);
        try{$owner->run(1,$s['invoice'],1,$scope,fn()=>$owner->scope(2,$s['invoice'],1));self::fail('Mismatch accepted');}
        catch(\RuntimeException $e){self::assertSame('SHIPMENT_POST_OWNER_SCOPE_MISMATCH',$e->getMessage());}
        self::assertNull($owner->scope(1,$s['invoice'],1));
        $owner->run(1,$s['invoice'],1,$scope,fn()=>$this->postInvoice($s['invoice']));$this->financial($s);
        self::assertNull($owner->scope(1,$s['invoice'],1));
    }
    public function test_support_scope_mutation_and_company_timeout_have_no_partial_effect():void
    {
        $token=$this->token();$s=F::purchase();$key=DB::connection()->getDatabaseName().'-ship-gate';
        DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$p=$this->supportWorker($token,$s['invoice'],'after-classification');
        try{$this->await($p,'SCOPE_CLASSIFIED');DB::transaction(function()use($s){DB::table('purchase_invoices')->where('id',$s['invoice'])->lockForUpdate()->first();DB::table('invoice_shipment_links')->where('invoice_id',$s['invoice'])->update(['allocated_amount'=>101]);});}
        finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$p->wait();}
        self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('SHIPMENT_POST_SCOPE_CHANGED',$p->getOutput());
        self::assertSame(0,DB::table('journal_entries')->count());
        $s=F::purchase();DB::beginTransaction();DB::table('companies')->where('id',1)->lockForUpdate()->first();
        try{$p=$this->supportWorker($token,$s['invoice']);$this->await($p,'from `companies`');$p->wait();self::assertStringContainsString('1205',$p->getOutput());}
        finally{DB::rollBack();}
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('vouchers')->count());
        $this->requestPost($token,$s['invoice'])->assertOk();$this->financial($s);
    }
    public function test_support_real_deadlock_victim_rolls_back_and_full_retry():void
    {
        $token=$this->token();$s=F::purchase();DB::beginTransaction();
        DB::table('items')->where('id',1)->lockForUpdate()->first();
        for($i=0;$i<50;$i++)DB::table('items')->insert(['company_id'=>1,'item_code'=>'SUPPORT-DEADLOCK-'.$i,'item_name'=>'Disposable']);
        $p=$this->supportWorker($token,$s['invoice']);
        try{$this->await($p,'from `items`');app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany(1);}
        finally{if(DB::transactionLevel()>0)DB::rollBack();}
        $p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1213',$p->getOutput());
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('vouchers')->count());
        $this->requestPost($token,$s['invoice'])->assertOk();$this->requestPost($token,$s['invoice'])->assertOk();$this->financial($s);
        self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());
    }
    public function test_two_company_support_posts_do_not_share_company_lock():void
    {
        $s=F::purchase();$token1=$this->token();$token2=$this->token('WRITE',['POST:api/purchase-invoices/{id}/post'],2,2);
        foreach([24=>'ASSET',25=>'EXPENSE']as$id=>$type)DB::table('accounts')->insert(['id'=>$id,'company_id'=>2,'account_code'=>'S-'.$id,'account_name'=>'Synthetic','account_type'=>$type,'normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0]);
        DB::table('accounting_settings')->insert([['company_id'=>2,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>22],['company_id'=>2,'setting_key'=>'GENERAL_EXPENSE_ACCOUNT','account_id'=>25]]);
        DB::table('financial_accounts')->insert(['id'=>2,'company_id'=>2,'branch_id'=>2,'account_code'=>'BANK2','account_name'=>'Synthetic','account_type'=>'BANK','gl_account_id'=>24,'currency_code'=>'SAR']);
        DB::table('expense_types')->insert(['id'=>2,'company_id'=>2,'type_name'=>'Synthetic freight','type_code'=>'FREIGHT2','account_id'=>25,'usage_type'=>'SHIPMENT','affects_cost'=>1]);
        $sid=DB::table('shipments')->insertGetId(['company_id'=>2,'branch_id'=>2,'supplier_id'=>2,'shipment_number'=>'SECOND-COMPANY','shipment_date'=>'2026-10-14','commercial_status'=>'READY','cost_allocation_method'=>'RELATIVE_VALUE','accepted_weight_kg'=>10,'currency_code'=>'SAR']);
        $line=DB::table('shipment_items')->insertGetId(['company_id'=>2,'shipment_id'=>$sid,'item_id'=>3,'qty_kg'=>10,'accepted_qty_kg'=>10,'base_cost'=>100,'remaining_qty_kg'=>10]);
        app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>2,'branch_id'=>2,'shipment_id'=>$sid,'expense_type_id'=>2,'expense_date'=>'2026-10-14','amount'=>20,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>2,'capitalizable'=>true,'created_by'=>99]);
        $invoice=\Tests\Support\UomOperationalLockFixture::purchase(2,3);
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$invoice)->update(['shipment_id'=>$sid,'shipment_item_id'=>$line]);
        DB::table('invoice_shipment_links')->insert(['company_id'=>2,'branch_id'=>2,'invoice_type'=>'PURCHASE','invoice_id'=>$invoice,'shipment_id'=>$sid,'allocated_qty_kg'=>10,'allocated_amount'=>100]);
        $key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);
        $a=$this->supportWorker($token1,$s['invoice'],'before-year');$b=null;
        try{$this->await($a,'BEFORE_YEAR');$b=$this->supportWorker($token2,$invoice);$this->await($b,'COMMITTED');self::assertStringNotContainsString('COMMITTED',$a->getOutput());}
        finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
        self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());$this->financial($s);$this->balanced();
        self::assertEquals(120,DB::table('inventory_lots')->where('company_id',2)->value('total_cost'));
        self::assertSame(2,DB::table('journal_entries')->where('company_id',2)->count());self::assertSame(1,DB::table('vouchers')->where('company_id',2)->count());
    }
    public function test_owner_cannot_survive_commit_and_begin_on_same_connection():void
    {
        $s=F::purchase();$owner=app(ShipmentTransactionOwner::class);$scope=ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);
        try {
            $owner->run(1,$s['invoice'],1,$scope,function()use($owner,$s){
                DB::commit();DB::beginTransaction(); // adversarial internal lifetime test only
                $owner->scope(1,$s['invoice'],1);
            });
            self::fail('Owner reused after original transaction ended');
        } catch(\RuntimeException $e) {
            self::assertSame('SHIPMENT_POST_OWNER_SCOPE_MISMATCH',$e->getMessage());
        } finally {while(DB::transactionLevel()>0)DB::rollBack();}
        self::assertNull($owner->scope(1,$s['invoice'],1));self::assertSame(0,DB::table('journal_entries')->count());
    }
}
