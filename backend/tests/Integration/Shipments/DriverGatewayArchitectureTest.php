<?php
namespace Tests\Integration\Shipments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DriverGatewayPrototype;

/** DB privilege and gateway proof. Not an operational endpoint or production authorization implementation. */
class DriverGatewayArchitectureTest extends \Tests\Feature\Shipments\ShipmentStageOneSafetyTest
{
    private array $roles=[];
    private array $users=[];
    private function cleanTables():void
    {
        foreach(['gw_payments','gw_exceptions','gw_origins','gw_requests','gw_scopes'] as $t)Schema::dropIfExists($t);
    }
    protected function setUp():void
    {
        if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',(string)getenv('DB_DATABASE')))throw new \RuntimeException('Explicit disposable MariaDB runner required');
        $this->refreshApplication();$this->cleanTables();parent::setUp();
        require_once __DIR__.'/../../Support/DriverGatewayPrototype.php';
        // Match the existing operational migration, not a new schema/index proposal.
        Schema::table('financial_years',fn($t)=>$t->index(['company_id','start_date','end_date'],'idx_financial_year_company_dates'));
        foreach(explode('-- statement',file_get_contents(__DIR__.'/../../Support/driver_gateway_schema.sql')) as $sql)if(str_starts_with(trim($sql),'CREATE'))DB::unprepared(trim($sql));
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'SUPPLIER_ACCOUNT','account_id'=>2]);
        DB::table('accounts')->insert(['id'=>9,'company_id'=>1,'account_code'=>'ADV','account_name'=>'Driver advance','account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>0,'allow_posting'=>1,'is_active'=>1]);
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'DRIVER_ADVANCE_ACCOUNT','account_id'=>9]);
        Schema::table('suppliers',fn($t)=>$t->unsignedBigInteger('ledger_account_id')->nullable());
        DB::table('suppliers')->insert(['id'=>99,'company_id'=>1,'branch_id'=>1,'is_active'=>1,'scope_all_branches'=>1,'ledger_account_id'=>2]);
        Schema::dropIfExists('expenses');Schema::create('expenses',function($t){$t->id();foreach(['company_id','branch_id','journal_entry_id','voucher_id','financial_account_id'] as $c)$t->unsignedBigInteger($c)->nullable();$t->decimal('amount',18,3);$t->string('currency_code')->nullable();$t->decimal('exchange_rate',24,10)->nullable();$t->decimal('foreign_amount',18,3)->nullable();$t->timestamps();});
        $db=DB::connection()->getDatabaseName();
        $cleanup=[];$admin=DB::connection()->getPdo();
        register_shutdown_function(static function()use(&$cleanup,$admin){foreach($cleanup as $name)try{$admin->exec("DROP USER IF EXISTS '$name'@'127.0.0.1'");}catch(\Throwable $e){fwrite(STDERR,"TEST_USER_CLEANUP_FAILED\n");}});
        foreach(['app','writer','report','schema'] as $role){
            $name='g4_'.$role.'_'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(18));
            DB::unprepared("CREATE USER '$name'@'127.0.0.1' IDENTIFIED BY '$password'");$cleanup[]=$name;$this->users[]=$name;$this->roles[$role]=['username'=>$name,'password'=>$password];
            DB::unprepared("GRANT SELECT ON `$db`.* TO '$name'@'127.0.0.1'");
            if($role==='schema')DB::unprepared("GRANT CREATE,DROP,ALTER,INDEX,TRIGGER,REFERENCES ON `$db`.* TO '$name'@'127.0.0.1'");
            if($role==='app'){
                // Ordinary operational writes remain possible, protected financial writes do not.
                DB::unprepared("GRANT UPDATE(driver_name) ON `$db`.`drivers` TO '$name'@'127.0.0.1'");
                DB::unprepared("GRANT UPDATE(notes) ON `$db`.`shipments` TO '$name'@'127.0.0.1'");
            }
            if($role==='writer'){
                foreach(['journal_entries','journal_entry_lines','shipment_costs','vouchers','sulb_document_sequences','gw_requests','gw_scopes','expenses'] as $t)DB::unprepared("GRANT INSERT,UPDATE ON `$db`.`$t` TO '$name'@'127.0.0.1'");
                foreach(['gw_origins','gw_payments','gw_exceptions'] as $t)DB::unprepared("GRANT INSERT ON `$db`.`$t` TO '$name'@'127.0.0.1'");
            }
        }
    }
    protected function tearDown():void
    {
        foreach($this->users as $name)DB::unprepared("DROP USER IF EXISTS '$name'@'127.0.0.1'");
        $this->cleanTables();parent::tearDown();
    }
    private function gateway():DriverGatewayPrototype{return new DriverGatewayPrototype($this->roles['writer']);}
    private function input(int $branch=1):array{return ['company_id'=>1,'branch_id'=>$branch,'shipment_id'=>$branch,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>'1000.000','currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'UNPAID','capitalizable'=>false,'payee_type'=>'DRIVER','payee_id'=>3];}
    private function pdo(string $role):\PDO{return new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName().';charset=utf8mb4',$this->roles[$role]['username'],$this->roles[$role]['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);}
    private function reject(callable $action,string $part):void{try{$action();self::fail('Expected rejection');}catch(\Throwable $e){self::assertStringContainsString($part,$e->getMessage());}}

    public function test_actual_database_credentials_enforce_write_boundary():void
    {
        $origin=$this->gateway()->createOrigin($this->input(),'origin-one');$result=$this->gateway()->pay(1,1,$origin['origin_id'],'400','pay-one');
        self::assertSame(1,$this->pdo('app')->exec("UPDATE drivers SET driver_name='Ordinary permitted update' WHERE id=1"));
        self::assertSame(1,$this->pdo('app')->exec("UPDATE shipments SET notes='Ordinary permitted note' WHERE id=1"));
        foreach(['app','report'] as $role){$pdo=$this->pdo($role);
            self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM gw_origins')->fetchColumn());
            foreach(['INSERT INTO gw_origins(id) VALUES(999)','UPDATE gw_payments SET amount=1','DELETE FROM gw_payments','UPDATE shipment_costs SET cost_status=\'POSTED\'','UPDATE shipment_costs SET journal_entry_id=999','UPDATE journal_entries SET reversed_at=NOW()','UPDATE journal_entry_lines SET debit=999','INSERT INTO journal_entry_lines(journal_entry_id) VALUES(1)','UPDATE gw_requests SET result=\'{}\'','CREATE TABLE ordinary_bypass(id INT)'] as $sql)$this->reject(fn()=>$pdo->exec($sql),'denied');
        }
        $writer=$this->pdo('writer');$this->reject(fn()=>$writer->exec('UPDATE gw_origins SET amount=1'),'denied');$this->reject(fn()=>$writer->exec('DELETE FROM gw_payments'),'denied');$this->reject(fn()=>$writer->exec('CREATE TABLE writer_bypass(id INT)'),'denied');
        $schema=$this->pdo('schema');$schema->exec('CREATE TABLE schema_probe(id INT)');$schema->exec('DROP TABLE schema_probe');$this->reject(fn()=>$schema->exec('UPDATE gw_origins SET amount=1'),'denied');
        self::assertSame('600.000',$result['remaining']);
    }
    public function test_origin_is_fresh_atomic_replayable_and_cannot_enroll_history():void
    {
        $g=$this->gateway();$before=DB::table('journal_entries')->count();
        $this->reject(fn()=>$g->createOrigin($this->input(),'fault',true),'FAULT_AFTER_JOURNAL');
        self::assertSame($before,DB::table('journal_entries')->count());self::assertSame(0,DB::table('shipment_costs')->count());self::assertSame(0,DB::table('gw_origins')->count());
        $result=$g->createOrigin($this->input(),'fault');self::assertSame($result,$g->createOrigin($this->input(),'fault'));
        self::assertSame(1,DB::table('shipment_costs')->count());self::assertSame(1,DB::table('journal_entries')->count());
        $this->reject(fn()=>$g->pay(1,1,$result['origin_id'],'400','payment-fault',true),'FAULT_PAYMENT');
        self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(0,DB::table('vouchers')->count());self::assertSame(0,DB::table('gw_payments')->count());
        self::assertSame('600.000',$g->pay(1,1,$result['origin_id'],'400','payment-fault')['remaining']);
        foreach(['shipment_cost_id','journal_entry_id','cost_status','created_by'] as $field)$this->reject(fn()=>$g->createOrigin([...$this->input(),$field=>$result['cost_id']],'old'),'enrollment forbidden');
        // Real pre-gateway historical posting has no origin and cannot be enrolled by the gateway API.
        $cost=app(\App\Services\ShipmentCostService::class)->storeDraft($this->input());app(\App\Services\ShipmentCostService::class)->postPendingForShipment(1,1,1);
        $this->reject(fn()=>$g->createOrigin([...$this->input(),'shipment_cost_id'=>$cost],'historical'),'enrollment forbidden');
        self::assertSame(1,DB::table('gw_origins')->count());
    }
    public function test_real_cost_voucher_advance_expense_and_shared_account_are_compatible():void
    {
        $g=$this->gateway();$o=$g->createOrigin($this->input(),'cost');
        $g->supplierVoucher(1,1);$g->driverAdvance();$g->accruedExpense();
        $first=$g->pay(1,1,$o['origin_id'],'400','p1');self::assertSame('600.000',$first['remaining']);self::assertSame($first,$g->pay(1,1,$o['origin_id'],'400','p1'));
        $this->reject(fn()=>$g->pay(1,1,$o['origin_id'],'401','p1'),'IDEMPOTENCY_CONFLICT');
        self::assertSame('0.000',$g->pay(1,1,$o['origin_id'],'600','p2')['remaining']);$this->reject(fn()=>$g->pay(1,1,$o['origin_id'],'1','p3'),'OVERPAYMENT');
        self::assertEquals(200,DB::table('journal_entry_lines')->where('account_id',9)->sum('debit'));
        self::assertEquals(0,DB::table('journal_entry_lines')->where('account_id',2)->where('party_type','DRIVER')->sum(DB::raw('credit-debit')));
        self::assertSame(0,DB::table('gw_exceptions')->count());
        foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')));
        foreach(DB::table('vouchers')->pluck('voucher_number') as $number)self::assertStringStartsWith('PAY-2026-',$number);
    }
    public function test_unclassified_manual_is_posted_but_cannot_hide_driver_reduction():void
    {
        $g=$this->gateway();$o=$g->createOrigin($this->input(),'origin');$journal=$g->manual(1,1,2);
        self::assertGreaterThan(0,$journal);self::assertSame(1,DB::table('gw_exceptions')->count());
        $this->reject(fn()=>$g->pay(1,1,$o['origin_id'],'400','p'),'UNCLASSIFIED_LEDGER_REVIEW');self::assertSame(0,DB::table('gw_payments')->count());
    }

    public function test_concurrent_payments_replay_and_independent_branch_scopes():void
    {
        $g=$this->gateway();$o=$g->createOrigin($this->input(),'origin');
        foreach(['overpay','replay'] as $mode){
            $children=[];DB::beginTransaction();DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');
            try{
                foreach([1,2] as $n){$key=$mode==='replay'?'same':'pay-'.$n;$amount=$mode==='replay'?'100':'600';
                    $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'pay',(string)$o['origin_id'],$amount,$key],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(30);$p->start();$children[]=$p;}
                $deadline=microtime(true)+10;$ready=false;while(microtime(true)<$deadline){if(count(array_filter($children,fn($p)=>str_contains($p->getOutput(),'READY')))===2){$ready=true;break;}usleep(100000);}self::assertTrue($ready);usleep(200000);
            }finally{DB::rollBack();}
            foreach($children as $p)$p->wait();$codes=array_map(fn($p)=>$p->getExitCode(),$children);sort($codes);
            self::assertSame($mode==='replay'?[0,0]:[0,2],$codes,implode(' | ',array_map(fn($p)=>$p->getOutput().$p->getErrorOutput(),$children)));
            fwrite(STDOUT,'GATEWAY_CONCURRENCY '.json_encode(['mode'=>$mode,'outputs'=>array_map(fn($p)=>$p->getOutput(),$children)])."\n");
        }
        self::assertSame(2,DB::table('gw_payments')->count());self::assertEquals(700,DB::table('gw_payments')->sum('amount'));
        DB::table('branches')->insert(['id'=>2,'company_id'=>1,'branch_name'=>'Independent','is_active'=>1]);DB::table('shipments')->insert(['id'=>2,'company_id'=>1,'branch_id'=>2,'shipment_number'=>'B2','commercial_status'=>'DRAFT','shipment_type'=>'PURCHASE']);
        $b2=$g->createOrigin($this->input(2),'branch2');self::assertGreaterThan(0,$b2['origin_id']);
        // Held branch 1 scope cannot block unrelated branch 2 JournalService posting.
        $scopeIds=DB::table('gw_scopes')->where('company_id',1)->where('branch_id',1)->pluck('id')->all();
        DB::beginTransaction();DB::table('gw_scopes')->whereIn('id',$scopeIds)->lockForUpdate()->get();
        try{$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'manual','2'],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(10);$p->run();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());}finally{DB::rollBack();}
    }

    public function test_origin_payment_supplier_manual_races_and_company_independence():void
    {
        $g=$this->gateway();$o=$g->createOrigin($this->input(),'initial');
        foreach([['origin','additional'],['supplier']] as $other){
            DB::beginTransaction();DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');$children=[];
            try{
                foreach([['pay',(string)$o['origin_id'],'100','pair-'.$other[0]],$other] as $args){$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),...$args],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(30);$p->start();$children[]=$p;}
                $deadline=microtime(true)+10;while(microtime(true)<$deadline&&count(array_filter($children,fn($p)=>str_contains($p->getOutput(),'READY')))<2)usleep(100000);
                self::assertCount(2,array_filter($children,fn($p)=>str_contains($p->getOutput(),'READY')));usleep(200000);
            }finally{DB::rollBack();}
            foreach($children as $p){$p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());}
        }
        self::assertSame(2,DB::table('gw_origins')->count());self::assertEquals(200,DB::table('gw_payments')->sum('amount'));
        // Unclassified GL races with a payment: either the payment wins before the exception,
        // or it is rejected. Once the exception commits, no later payment may pass.
        $children=[];foreach([['pay',(string)$o['origin_id'],'100','manual-race'],['manual','1','2']] as $args){$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),...$args],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(30);$p->start();$children[]=$p;}
        foreach($children as $p)$p->wait();self::assertSame(0,$children[1]->getExitCode(),$children[1]->getOutput());self::assertContains($children[0]->getExitCode(),[0,2]);
        if($children[0]->getExitCode()===2)self::assertStringContainsString('UNCLASSIFIED_LEDGER_REVIEW',$children[0]->getOutput());
        $this->reject(fn()=>$g->pay(1,1,$o['origin_id'],'1','after-manual'),'UNCLASSIFIED_LEDGER_REVIEW');
        DB::table('companies')->insert(['id'=>2,'company_name'=>'Independent test company']);DB::table('branches')->insert(['id'=>3,'company_id'=>2,'branch_name'=>'Independent company','is_active'=>1]);
        DB::table('financial_years')->insert(['id'=>2,'company_id'=>2,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_closed'=>0]);
        foreach([[10,'ASSET','DEBIT'],[11,'EXPENSE','DEBIT']] as [$id,$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>2,'account_code'=>(string)$id,'account_name'=>'Independent','account_type'=>$type,'normal_side'=>$side,'is_group'=>0,'allow_posting'=>1,'is_active'=>1]);
        DB::beginTransaction();DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');DB::select('SELECT id FROM financial_years WHERE id=1 FOR UPDATE');
        try{$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'manual','3','11','2'],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(15);$p->run();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());}finally{DB::rollBack();}
    }

    public function test_unclassified_gl_before_concurrent_origin_is_not_forgotten():void
    {
        DB::beginTransaction();DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');
        $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'origin','concurrent-origin'],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(30);
        try{
            $p->start();$deadline=microtime(true)+10;while(!str_contains($p->getOutput(),'READY')&&microtime(true)<$deadline)usleep(100000);
            self::assertStringContainsString('READY',$p->getOutput());
            // Scope is durable even before ORIGIN exists. Manual GL is allowed and reviewed.
            $this->gateway()->manual(1,1,2);self::assertTrue($p->isRunning());
        }finally{DB::rollBack();}
        $p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());
        self::assertSame(1,DB::table('gw_origins')->count());self::assertSame(1,DB::table('gw_exceptions')->count());
        $origin=(int)DB::table('gw_origins')->value('id');
        $this->reject(fn()=>$this->gateway()->pay(1,1,$origin,'1','not-eligible'),'UNCLASSIFIED_LEDGER_REVIEW');self::assertSame(0,DB::table('gw_payments')->count());
    }

    public function test_latency_throughput_and_no_global_trigger_lock():void
    {
        $g=$this->gateway();$metrics=[];
        foreach(['baseline','gateway'] as $mode){$times=[];$startAll=microtime(true);
            for($i=0;$i<50;$i++){$start=microtime(true);if($mode==='gateway')$g->manual(1,1,8);else app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','description'=>'Baseline expense','lines'=>[['account_id'=>8,'debit'=>10,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>10]]]);$times[]=(microtime(true)-$start)*1000;}
            $elapsed=microtime(true)-$startAll;sort($times);$metrics[$mode]=['attempts'=>50,'retries'=>0,'p50_ms'=>$times[24],'p95_ms'=>$times[47],'p99_ms'=>$times[49],'throughput_per_second'=>50/$elapsed,'1205'=>0,'1213'=>0];
        }
        DB::beginTransaction();DB::select('SELECT id FROM companies WHERE id=1 FOR UPDATE');
        try{$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'manual','1'],base_path(),['SULB_GW_WRITER'=>json_encode($this->roles['writer'])]);$p->setTimeout(10);$p->run();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());}finally{DB::rollBack();}
        fwrite(STDOUT,'GATEWAY_BENCHMARK '.json_encode($metrics)."\n");
    }
}
