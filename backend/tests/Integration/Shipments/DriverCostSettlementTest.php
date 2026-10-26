<?php
namespace Tests\Integration\Shipments;

use App\Services\DriverCostSettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MariaDB-only, explicitly selected by owned disposable-server runner; never silently skipped. */
class DriverCostSettlementTest extends \Tests\Feature\Shipments\ShipmentStageOneSafetyTest
{
    protected function setUp(): void
    {
        // Safe cleanup only after proving the owned database, before rebuilding fixture tables.
        if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',(string)getenv('DB_DATABASE')))throw new \RuntimeException('Owned MariaDB test required');
        $this->refreshApplication();
        require_once __DIR__.'/../../../scripts/driver_settlement_test_cleanup.php';
        \driverSettlementTestCleanup();
        parent::setUp();
        self::assertSame('mysql',DB::connection()->getDriverName());
        // Align fixture PK signedness with checked-in production schema; test DDL only.
        DB::statement('ALTER TABLE companies MODIFY id BIGINT NOT NULL AUTO_INCREMENT');
        DB::statement('ALTER TABLE vouchers MODIFY id BIGINT NOT NULL AUTO_INCREMENT');
        foreach(['shipment_costs','drivers'] as $table) DB::statement('ALTER TABLE '.$table.' MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        Schema::table('users',function(Blueprint $t){$t->bigInteger('company_id')->nullable();$t->unsignedBigInteger('branch_id')->nullable();$t->boolean('is_active')->default(true);});
        DB::table('users')->insert(['id'=>1,'name'=>'Isolated actor','company_id'=>1,'branch_id'=>1,'is_active'=>1]);
        foreach(['roles','user_roles','role_permissions','permissions','plans','subscriptions','plan_features','user_permission_overrides'] as $t)Schema::dropIfExists($t);
        Schema::create('roles',function($t){$t->id();$t->string('role_code');$t->string('role_name');$t->boolean('is_active');});
        Schema::create('user_roles',function($t){$t->id();$t->integer('user_id');$t->integer('role_id');$t->integer('company_id')->nullable();$t->boolean('is_active');});
        Schema::create('permissions',function($t){$t->id();$t->string('permission_code');$t->string('permission_scope');});
        Schema::create('role_permissions',function($t){$t->id();$t->integer('role_id');$t->integer('permission_id');$t->integer('company_id')->nullable();$t->boolean('is_active');});
        DB::table('roles')->insert(['id'=>1,'role_code'=>'ACCOUNTANT','role_name'=>'Test accountant','is_active'=>1]);
        DB::table('user_roles')->insert(['user_id'=>1,'role_id'=>1,'company_id'=>1,'is_active'=>1]);
        foreach(['shipments.cost','vouchers.create'] as $i=>$code){DB::table('permissions')->insert(['id'=>$i+1,'permission_code'=>$code,'permission_scope'=>'COMPANY']);DB::table('role_permissions')->insert(['role_id'=>1,'permission_id'=>$i+1,'is_active'=>1]);}
        Schema::create('plans',function($t){$t->id();$t->string('plan_code');$t->string('plan_name');foreach(['max_branches','max_users','max_cars','max_invoices'] as $c)$t->integer($c)->nullable();});
        Schema::create('subscriptions',function($t){$t->id();$t->integer('company_id');$t->integer('plan_id');$t->string('status');$t->date('start_date');$t->date('end_date');});
        Schema::create('plan_features',function($t){$t->id();$t->integer('plan_id');$t->string('feature_code');$t->boolean('is_enabled');$t->integer('limit_value')->nullable();});
        DB::table('plans')->insert(['id'=>1,'plan_code'=>'TEST','plan_name'=>'Isolated']);
        DB::table('subscriptions')->insert(['company_id'=>1,'plan_id'=>1,'status'=>'ACTIVE','start_date'=>'2020-01-01','end_date'=>'2099-12-31']);
        foreach(['accounting','shipments'] as $code)DB::table('plan_features')->insert(['plan_id'=>1,'feature_code'=>$code,'is_enabled'=>1]);
        $sql=file_get_contents(base_path('../docs/erp-baseline/proposed-migrations/shipment_cost_settlements.sql'));
        foreach(explode('-- statement',$sql) as $statement)if(str_starts_with(trim($statement),'CREATE'))DB::unprepared(trim($statement));
        \App\Services\DriverSettlementIsolation::assert();
        Auth::loginUsingId(1);
    }
    protected function tearDown(): void
    {
        Auth::logout();\driverSettlementTestCleanup();parent::tearDown();
    }
    public function input(array $changes=[]): array
    {
        return array_replace(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>'1000.000','currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'UNPAID','capitalizable'=>false,'payee_type'=>'DRIVER','payee_id'=>1],$changes);
    }
    private function pay(int $cost,string $amount='400',string $key='one'): array
    {
        return app(DriverCostSettlement::class)->settle(1,1,1,$cost,1,'2026-03-03',$amount,$key);
    }
    private function rejected(callable $call,string $message=''): void
    {
        try{$call();self::fail('Expected safe rejection');}catch(\RuntimeException|\Illuminate\Database\QueryException $e){if($message)self::assertStringContainsString($message,$e->getMessage());else self::assertNotEmpty($e->getMessage());}
    }
    public function test_1000_400_600_replay_and_original_account(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        self::assertSame(['eligibility'=>'NEW_VERIFIED','remaining'=>'1000.000'],app(DriverCostSettlement::class)->eligibility(1,1,1,$cost));
        DB::table('accounting_settings')->where('setting_key','ACCRUED_EXPENSE_ACCOUNT')->update(['account_id'=>8]);
        $first=$this->pay($cost);self::assertSame('600.000',$first['remaining']);
        self::assertSame($first,$this->pay($cost));$this->rejected(fn()=>$this->pay($cost,'401'),'IDEMPOTENCY_CONFLICT');
        $this->rejected(fn()=>$this->pay($cost,'601','excess'),'OVERPAYMENT');
        $second=$this->pay($cost,'600','two');self::assertSame('0.000',$second['remaining']);
        self::assertSame($first,$this->pay($cost));$this->rejected(fn()=>$this->pay($cost,'1','extra'),'OVERPAYMENT');
        self::assertSame(2,DB::table('vouchers')->count());self::assertSame(3,DB::table('journal_entries')->count());self::assertSame(2,DB::table('shipment_cost_settlement_allocations')->count());
        self::assertEquals(1000,DB::table('journal_entry_lines')->where('account_id',2)->sum('debit'));self::assertEquals(1000,DB::table('journal_entry_lines')->where('account_id',2)->sum('credit'));
        foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertEquals(DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum('debit'),DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum('credit'));
        self::assertStringStartsWith('PAY-2026-',DB::table('vouchers')->value('voucher_number'));
        $request=\Illuminate\Http\Request::create('/statements/driver-shipments/1','GET');$request->attributes->add(['tenant_company_id'=>1,'tenant_branch_id'=>1,'effective_role_code'=>'ACCOUNTANT']);
        $statement=app(\App\Services\DriverShipmentStatement::class)->report($request,1);
        self::assertEquals(0,$statement['reconciliation'][0]['closing_payable']);self::assertEquals(1000,$statement['totals']->original_liabilities);
        self::assertNull($statement['costs']->items()[0]->remaining_amount); // 2A contract intentionally unchanged.
        self::assertSame('0.000',app(DriverCostSettlement::class)->eligibility(1,1,1,$cost)['remaining']);
        fwrite(STDOUT,"DRIVER_SETTLEMENT_PROOF original=1000 first=400 remaining=600 second=600 remaining=0 vouchers=2 journals=3\n");
    }
    public function test_faults_roll_back_number_voucher_journal_and_allocation(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        foreach(['after_number','after_journal'] as $stage){
            $fault=new class($stage) extends DriverCostSettlement {public function __construct(private string $fault){}protected function checkpoint(string $stage):void{if($stage===$this->fault)throw new \RuntimeException('Injected failure');}};
            $this->rejected(fn()=>$fault->settle(1,1,1,$cost,1,'2026-03-03','400','failure'),'Injected failure');
            self::assertSame(0,DB::table('vouchers')->count());self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(0,DB::table('shipment_cost_settlement_allocations')->count());self::assertSame(0,DB::table('sulb_document_sequences')->count());
        }
        self::assertSame('600.000',$this->pay($cost,'400','failure')['remaining']);
    }
    public function test_historical_direct_supplier_unallocated_and_policy_fail_closed(): void
    {
        $service=app(\App\Services\ShipmentCostService::class);
        $legacy=$service->storeDraft($this->input());$service->postPendingForShipment(1,1,1,1);
        $this->rejected(fn()=>$this->pay($legacy),'HISTORICAL_REVIEW_REQUIRED');
        $paid=$service->storeDraft($this->input(['payment_status'=>'PAID','financial_account_id'=>1]));$service->postPendingForShipment(1,1,1,1);
        $this->rejected(fn()=>$this->pay($paid),'DIRECT_PAID');
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        DB::table('accounts')->where('id',2)->update(['is_active'=>0]);$this->rejected(fn()=>$this->pay($cost),'BLOCKED_POLICY');DB::table('accounts')->where('id',2)->update(['is_active'=>1]);
        $this->rejected(fn()=>$this->pay($cost,'0.001'),'BLOCKED_POLICY');
        DB::table('financial_accounts')->update(['currency_code'=>'USD']);$this->rejected(fn()=>$this->pay($cost),'BLOCKED_POLICY');
        self::assertSame(0,DB::table('shipment_cost_settlement_allocations')->count());
    }
    public function test_external_debit_audit_changes_and_unauthorized_actor_are_blocked(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        $this->rejected(fn()=>app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','description'=>'External settlement','source_type'=>'MANUAL','source_id'=>999,'created_by'=>1,'lines'=>[['account_id'=>2,'debit'=>400,'credit'=>0,'party_type'=>'DRIVER','party_id'=>1],['account_id'=>1,'debit'=>0,'credit'=>400]]]),'Unallocated');
        self::assertSame(1,DB::table('journal_entries')->count());
        $this->rejected(fn()=>DB::table('shipment_cost_settlement_requests')->update(['actor_id'=>999]),'Immutable');
        $this->rejected(fn()=>DB::table('shipment_cost_settlement_requests')->delete(),'cannot be deleted');
        Auth::logout();$this->rejected(fn()=>$this->pay($cost),'Actor');Auth::loginUsingId(1);
        DB::table('role_permissions')->delete();$this->rejected(fn()=>$this->pay($cost),'Permission');
    }
    public function test_concurrency_overpayment_and_same_key_repeated_three_times(): void
    {
        foreach(range(1,3) as $round)foreach(['overpay','same_key'] as $mode){
            $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
            $children=[];DB::beginTransaction();
            try {
                app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany(1);
                foreach([1,2] as $n){$key=$mode==='same_key'?'same-'.$round:'race-'.$round.'-'.$n;
                    $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_settlement_race_worker.php'),(string)$cost,$mode==='same_key'?'400':'600',$key],base_path());$p->setTimeout(25);$p->start();$children[]=$p;}
                $deadline=microtime(true)+15;$waiting=false;
                while(microtime(true)<$deadline){
                    $ids=[];foreach($children as $p)if(preg_match('/READY (\d+)/',$p->getOutput(),$m))$ids[]=(int)$m[1];
                    if(count($ids)===2){$observer=new \PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','');$q=$observer->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state=\'LOCK WAIT\' AND trx_mysql_thread_id IN ('.implode(',',$ids).')');$count=(int)$q->fetchColumn();$q->closeCursor();unset($q);$observer=null;if($count===2){$waiting=true;break;}}
                    usleep(200000);
                }
                self::assertTrue($waiting,'Both workers must prove InnoDB lock waiting: '.implode(' | ',array_map(fn($p)=>$p->getOutput().$p->getErrorOutput(),$children)));
                DB::commit();
                $observer=null;
            }catch(\Throwable $e){DB::rollBack();foreach($children as $p)$p->stop();throw $e;}
            foreach($children as $p)$p->wait();
            $codes=array_map(fn($p)=>$p->getExitCode(),$children);sort($codes);
            self::assertSame($mode==='same_key'?[0,0]:[0,2],$codes,implode(' | ',array_map(fn($p)=>$p->getOutput().$p->getErrorOutput(),$children)));
            self::assertSame(1,DB::table('shipment_cost_settlement_allocations')->where('shipment_cost_id',$cost)->count());
            self::assertEquals($mode==='same_key'?400:600,DB::table('shipment_cost_settlement_allocations')->where('shipment_cost_id',$cost)->sum('amount'));
            fwrite(STDOUT,"CONCURRENCY round=$round mode=$mode PASS two_actual_lock_waits one_allocation\n");
        }
    }
    public function test_existing_unallocated_debit_blocks_new_origin_and_rolls_back(): void
    {
        app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-01','description'=>'Historical debit without allocation','source_type'=>'MANUAL','created_by'=>1,'lines'=>[['account_id'=>2,'party_type'=>'DRIVER','party_id'=>1,'debit'=>400,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>400]]]);
        $this->rejected(fn()=>app(DriverCostSettlement::class)->createControlledCost($this->input()),'UNALLOCATED_LEDGER_MOVEMENT');
        self::assertSame(0,DB::table('shipment_costs')->count());self::assertSame(1,DB::table('journal_entries')->count());
        $legacy=app(\App\Services\ShipmentCostService::class)->storeDraft($this->input());app(\App\Services\ShipmentCostService::class)->postPendingForShipment(1,1,1,1);
        self::assertSame(['eligibility'=>'UNALLOCATED_LEDGER_MOVEMENT','remaining'=>null],app(DriverCostSettlement::class)->eligibility(1,1,1,$legacy));
    }
    public function test_company_wide_driver_scope_and_blocked_support_subscription(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input(['payee_id'=>3]));
        $r=app(DriverCostSettlement::class)->settle(1,1,3,$cost,1,'2026-03-03','400','global-driver');self::assertSame('600.000',$r['remaining']);
        $this->rejected(fn()=>app(DriverCostSettlement::class)->settle(2,1,3,$cost,1,'2026-03-03','400','foreign'),'Actor');
        $this->rejected(fn()=>app(DriverCostSettlement::class)->settle(1,2,3,$cost,1,'2026-03-03','400','branch'),'Actor');
        request()->attributes->set('support_access_level','READ_ONLY');$this->rejected(fn()=>app(DriverCostSettlement::class)->settle(1,1,3,$cost,1,'2026-03-03','400','readonly'),'Support');request()->attributes->remove('support_access_level');
        DB::table('subscriptions')->update(['status'=>'SUSPENDED']);$this->rejected(fn()=>app(DriverCostSettlement::class)->settle(1,1,3,$cost,1,'2026-03-03','400','suspended'),'Subscription');
    }
    public function test_supplier_and_database_constraints_and_no_reversal(): void
    {
        DB::table('suppliers')->insert(['id'=>99,'company_id'=>1,'is_active'=>1,'scope_all_branches'=>1]);
        $supplier=app(\App\Services\ShipmentCostService::class)->storeDraft($this->input(['payee_type'=>'SUPPLIER','payee_id'=>99]));app(\App\Services\ShipmentCostService::class)->postPendingForShipment(1,1,1,1);
        $this->rejected(fn()=>$this->pay($supplier),'INCOMPLETE_EVIDENCE');
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());$paid=$this->pay($cost);
        $allocation=(array)DB::table('shipment_cost_settlement_allocations')->first();unset($allocation['id']);
        $this->rejected(fn()=>DB::table('shipment_cost_settlement_allocations')->insert($allocation));
        $this->rejected(fn()=>DB::table('shipment_cost_settlement_allocations')->update(['amount'=>401]),'immutable');
        $this->rejected(fn()=>DB::table('vouchers')->where('id',$paid['voucher_id'])->delete());
        $this->rejected(fn()=>DB::table('journal_entries')->where('id',$paid['journal_entry_id'])->update(['reversed_at'=>now()]),'immutable');
        $this->rejected(fn()=>DB::table('shipment_costs')->where('id',$cost)->update(['payee_id'=>4]),'immutable');
        self::assertSame('600.000',app(DriverCostSettlement::class)->eligibility(1,1,1,$cost)['remaining']);
    }
    public function test_old_driver_advance_posting_is_unchanged_with_controlled_origin(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        $advance=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'ADV','account_name'=>'Driver advance','account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>0,'allow_posting'=>1,'is_active'=>1]);
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'DRIVER_ADVANCE_ACCOUNT','account_id'=>$advance]);
        $voucher=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>2,'voucher_number'=>'PAY-OLD-ADVANCE','voucher_date'=>'2026-03-03','reference_type'=>'DRIVER','reference_id'=>1,'amount'=>200,'financial_account_id'=>1,'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>200,'created_by'=>1]);
        $posted=app(\App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>1,'voucher_id'=>$voucher,'created_by'=>1]);
        self::assertTrue($posted->success,$posted->message);
        self::assertEquals(200,DB::table('journal_entry_lines')->where('journal_entry_id',$posted->journalEntryId)->where('account_id',$advance)->sum('debit'));
        self::assertSame('1000.000',app(DriverCostSettlement::class)->eligibility(1,1,1,$cost)['remaining']);
        self::assertSame(0,DB::table('shipment_cost_settlement_allocations')->count());
    }

    public function test_origin_financial_snapshot_currency_and_payment_identity_guards(): void
    {
        $service=app(\App\Services\ShipmentCostService::class);
        $cost=$service->storeDraft($this->input());$service->postPendingForShipment(1,1,1,1);
        $c=DB::table('shipment_costs')->find($cost);$line=DB::table('journal_entry_lines')->where('journal_entry_id',$c->journal_entry_id)->where('credit','>',0)->first();
        $origin=['company_id'=>1,'branch_id'=>1,'driver_id'=>1,'shipment_cost_id'=>$cost,'original_journal_id'=>$c->journal_entry_id,'original_line_id'=>$line->id,'original_account_id'=>2,'operation'=>'ORIGIN','request_key'=>'origin:'.$cost,'request_hash'=>hash('sha256','origin:'.$cost),'amount'=>0,'currency_code'=>'SAR','effective_date'=>'2026-03-02','actor_id'=>1,'actor_name'=>'Isolated actor','status'=>'COMPLETED','journal_entry_id'=>$c->journal_entry_id,'paid_after'=>0,'remaining_after'=>1000,'created_at'=>now(),'completed_at'=>now()];
        foreach([['journal_entry_id'=>null],['paid_after'=>1],['remaining_after'=>999],['currency_code'=>'sar']] as $change)$this->rejected(fn()=>DB::table('shipment_cost_settlement_requests')->insert(array_replace($origin,$change)));
        // Security characterization, NOT a pass for provenance: normal INSERT privilege can forge enrollment.
        DB::table('shipment_cost_settlement_requests')->insert($origin);
        self::assertSame('NEW_VERIFIED',app(DriverCostSettlement::class)->eligibility(1,1,1,$cost)['eligibility']);
        fwrite(STDOUT,"OPEN_RISK historical_ORIGIN_forgery accepted with ordinary INSERT privilege; activation BLOCKED\n");
        $payment=array_replace($origin,['operation'=>'PAYMENT','request_key'=>'spoof','amount'=>100,'financial_account_id'=>1,'status'=>'PENDING','journal_entry_id'=>null,'paid_after'=>null,'remaining_after'=>null,'completed_at'=>null,'branch_id'=>2]);
        $this->rejected(fn()=>DB::table('shipment_cost_settlement_requests')->insert($payment));
        foreach(['company_id','driver_id','shipment_cost_id','original_journal_id','original_line_id','original_account_id'] as $field){$bad=array_replace($payment,['branch_id'=>1,$field=>999]);$this->rejected(fn()=>DB::table('shipment_cost_settlement_requests')->insert($bad));}
        $this->rejected(fn()=>DB::table('shipment_cost_settlement_requests')->where('operation','ORIGIN')->update(['actor_name'=>'Spoofed']),'Immutable');
    }

    public function test_completed_financial_snapshots_reject_false_totals_and_roll_back(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        foreach([['paid_after'=>401,'remaining_after'=>599],['paid_after'=>400,'remaining_after'=>599],['paid_after'=>null,'remaining_after'=>null]] as $wrong){
            $fault=new class($wrong) extends DriverCostSettlement {
                public function __construct(private array $wrong){}
                protected function checkpoint(string $stage):void{
                    if($stage!=='after_allocation')return;
                    $a=DB::table('shipment_cost_settlement_allocations')->first();
                    DB::table('shipment_cost_settlement_requests')->where('id',$a->request_id)->update(['status'=>'COMPLETED','journal_entry_id'=>$a->payment_journal_id,'completed_at'=>now(),...$this->wrong]);
                }
            };
            $this->rejected(fn()=>$fault->settle(1,1,1,$cost,1,'2026-03-03','400','bad-snapshot'),'Invalid completed financial snapshot');
            self::assertSame(0,DB::table('vouchers')->count());self::assertSame(0,DB::table('shipment_cost_settlement_allocations')->count());self::assertSame(1,DB::table('journal_entries')->count());
        }
    }

    public function test_payment_evidence_is_revalidated_after_allocation(): void
    {
        $cost=app(DriverCostSettlement::class)->createControlledCost($this->input());
        $fault=new class extends DriverCostSettlement {
            protected function checkpoint(string $stage):void{
                if($stage!=='after_allocation')return;
                $a=DB::table('shipment_cost_settlement_allocations')->first();
                DB::table('journal_entry_lines')->where('id',$a->payment_line_id)->update(['party_id'=>999]);
            }
        };
        $this->rejected(fn()=>$fault->settle(1,1,1,$cost,1,'2026-03-03','400','swapped-party'),'matching allocation evidence');
        self::assertSame(0,DB::table('vouchers')->count());self::assertSame(0,DB::table('shipment_cost_settlement_allocations')->count());self::assertSame(1,DB::table('journal_entries')->count());
    }

    public function test_company_then_year_vs_year_then_company_deadlock_is_characterized(): void
    {
        $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gl_guard_probe.php'),'deadlock'],base_path());$p->setTimeout(30);
        DB::beginTransaction();$error='';$observer=null;
        try{
            DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');$p->start();
            $waiting=false;$deadline=microtime(true)+12;
            while(microtime(true)<$deadline){
                if(preg_match('/CONNECTION (\d+)/',$p->getErrorOutput(),$m)){$observer=new \PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','');$q=$observer->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id=".(int)$m[1]);$waiting=(int)$q->fetchColumn()===1;$q->closeCursor();unset($q);$observer=null;if($waiting)break;}
                usleep(200000);
            }
            self::assertTrue($waiting,'Unrelated GL must hold financial-year row while waiting on company: '.$p->getOutput().' '.$p->getErrorOutput());
            try{DB::select('SELECT id FROM financial_years WHERE id=1 FOR UPDATE');}catch(\Throwable $e){$error=$e->getMessage();}
        }finally{DB::rollBack();$observer=null;}
        $p->wait();$result=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);
        self::assertStringContainsString('1213',$error.' '.($result['error']??''));
        fwrite(STDOUT,'OPEN_RISK deterministic company/year lock-order DEADLOCK 1213 '.json_encode(['parent_error'=>$error,'worker'=>$result])."\n");
    }

    public function test_shared_account_false_positive_and_wrong_party_ambiguity_are_exposed(): void
    {
        app(DriverCostSettlement::class)->createControlledCost($this->input());
        $data=['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','description'=>'Shared liability operation','source_type'=>'MANUAL','created_by'=>1,'lines'=>[['account_id'=>2,'debit'=>100,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>100]]];
        $this->rejected(fn()=>app(\App\Services\AccountingService::class)->post($data),'Unallocated');
        $data['lines'][0]['party_type']='SUPPLIER';$data['lines'][0]['party_id']=99;
        $journal=app(\App\Services\AccountingService::class)->post($data);
        self::assertGreaterThan(0,$journal);
        fwrite(STDOUT,"OPEN_RISK shared_account untagged legitimate debit rejected; other-party debit accepted but intent cannot be proven by GL trigger\n");
    }

    public function test_trigger_company_lock_impact_and_sequential_benchmark(): void
    {
        $triggers=DB::select("SELECT TRIGGER_NAME name,ACTION_STATEMENT body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('scs_gl_insert','scs_gl_update','scs_entry_update')");
        foreach($triggers as $trigger)self::assertStringContainsString('companies',$trigger->body);
        $run=function(bool $guarded):array{
            $times=[];$failures=0;
            for($i=0;$i<30;$i++){$start=microtime(true);try{app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','source_type'=>'MANUAL','description'=>'Benchmark only','created_by'=>1,'lines'=>[['account_id'=>8,'debit'=>1,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>1]]]);}catch(\Throwable $e){$failures++;}$times[]=(microtime(true)-$start)*1000;}
            sort($times);
            DB::beginTransaction();
            try{DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gl_guard_probe.php')],base_path());$p->setTimeout(10);$p->run();$result=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}finally{DB::rollBack();}
            self::assertSame(0,$failures);self::assertSame($guarded?'BLOCKED':'POSTED',$result['result']);
            return ['n'=>30,'median_ms'=>$times[15],'p95_ms'=>$times[28],'failures'=>$failures,'held_company_lock_probe'=>$result];
        };
        $protected=$run(true);
        foreach($triggers as $trigger)DB::unprepared('DROP TRIGGER '.$trigger->name);
        try{$baseline=$run(false);}finally{foreach($triggers as $trigger){$table=$trigger->name==='scs_entry_update'?'journal_entries':'journal_entry_lines';$event=$trigger->name==='scs_gl_insert'?'INSERT':'UPDATE';DB::unprepared('CREATE TRIGGER '.$trigger->name.' BEFORE '.$event.' ON '.$table.' FOR EACH ROW '.$trigger->body);}}
        fwrite(STDOUT,'GL_GUARD_BENCHMARK '.json_encode(['baseline'=>$baseline,'protected'=>$protected])."\n");
    }
}
