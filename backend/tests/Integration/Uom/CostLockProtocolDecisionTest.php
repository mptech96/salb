<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Comparative controls plus real operational-entry acceptance; disposable DB only. */
final class CostLockProtocolDecisionTest extends \Tests\TestCase
{
    private array $source;
    private int $otherCompany;
    private int $otherShipment;
    protected function setUp():void{
        parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture']as$n)require_once __DIR__.'/../../Support/'.$n.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES')as$r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
        $this->source=F::purchase();DB::table('expense_types')->insert(['id'=>2,'company_id'=>2,'type_name'=>'Other','type_code'=>'OTHER','account_id'=>22,'usage_type'=>'SHIPMENT','affects_cost'=>1]);
        $this->otherShipment=$this->shipment(1,'OTHER-SAME');$this->otherCompany=$this->shipment(2,'OTHER-COMPANY');
    }
    private function shipment(int$c,string$number):int{return DB::table('shipments')->insertGetId(['company_id'=>$c,'branch_id'=>$c,'supplier_id'=>$c,'shipment_number'=>$number,'shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-14','commercial_status'=>'READY','currency_code'=>'SAR']);}
    private function pdo():\PDO{$p=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName().';charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$p->exec('SET SESSION innodb_lock_wait_timeout=1');return$p;}
    private function fk():void{DB::statement('ALTER TABLE shipment_costs ADD CONSTRAINT proof_cost_parent FOREIGN KEY (shipment_id) REFERENCES shipments(id)');}
    private function worker(string$mode,int$c,int$s,int$cost=0,int$waiter=0):Process{$p=new Process([PHP_BINARY,base_path('scripts/cost_protocol_probe.php'),$mode,(string)$c,(string)$s,(string)$cost,(string)$waiter],base_path());$p->setTimeout(12);$p->start();return$p;}
    private function await(Process$p,string$text):void{$end=microtime(true)+4;while($p->isRunning()&&!str_contains($p->getOutput(),$text)&&microtime(true)<$end)usleep(10000);self::assertStringContainsString($text,$p->getOutput(),$p->getErrorOutput());}
    private function probe(Process$p):array{
        $this->await($p,'BEFORE_INSERT');preg_match('/CONNECTION (\d+)/',$p->getOutput(),$id);$wait=[];$end=microtime(true)+0.25;
        do{$wait=DB::select('SELECT l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?',[(int)$id[1]]);if(!$wait&&$p->isRunning())usleep(10000);}while(!$wait&&$p->isRunning()&&microtime(true)<$end);
        $p->wait();preg_match('/RESULT (.+)/',$p->getOutput(),$match);self::assertNotEmpty($match,$p->getOutput());return json_decode($match[1],true,512,JSON_THROW_ON_ERROR)+['wait'=>$wait];
    }
    public static function alternatives():array{
        $a=[];foreach(['A-index','A-composite','B-point','C-plain','D-rc','D-rc-fk']as$v)foreach([false,true]as$empty)$a[$v.($empty?'-empty':'-rows')]=[$v,$empty];return$a;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('alternatives')]
    public function test_comparative_gap_footprint_and_raw_membership(string$variant,bool$empty):void{
        if($empty)DB::table('shipment_costs')->delete();
        if($variant==='A-composite')DB::statement('ALTER TABLE shipment_costs ADD INDEX proof_company_shipment(company_id,shipment_id,id)');
        if($variant==='D-rc-fk')$this->fk();
        $a=$this->pdo();$a->exec('SET TRANSACTION ISOLATION LEVEL '.(str_starts_with($variant,'D-')?'READ COMMITTED':'REPEATABLE READ'));$a->beginTransaction();$sid=$this->source['shipment'];
        try{
            $a->query('SELECT id FROM shipments WHERE id='.$sid.' FOR UPDATE')->fetchAll();
            $index=$variant==='A-composite'?'proof_company_shipment':'idx_shipment';
            $sql='SELECT * FROM shipment_costs FORCE INDEX ('.$index.') WHERE company_id=1 AND shipment_id='.$sid.' ORDER BY id';
            if($variant==='B-point'){
                $ids=$a->query($sql)->fetchAll(\PDO::FETCH_COLUMN);foreach($ids as$id)$a->query('SELECT * FROM shipment_costs WHERE id='.(int)$id.' FOR UPDATE')->fetchAll();
            }else $a->query($sql.($variant==='C-plain'?'':' FOR UPDATE'))->fetchAll();
            $explain=$a->query('EXPLAIN '.$sql.($variant==='C-plain'?'':' FOR UPDATE'))->fetchAll(\PDO::FETCH_ASSOC);
            // The test controller/observer, holder PDO and worker are >=3 actual connections.
            $company=$this->probe($this->worker('service',2,$this->otherCompany));$same=$this->probe($this->worker('service',1,$this->otherShipment));$raw=$this->probe($this->worker('raw',1,$sid));
            $expectedIndependent=!str_starts_with($variant,'A-');self::assertSame($expectedIndependent,$company['committed']);self::assertSame($expectedIndependent,$same['committed']);
            $expectedRaw=in_array($variant,['B-point','C-plain','D-rc'],true);self::assertSame($expectedRaw,$raw['committed']);
            if(!$company['committed'])self::assertSame(1205,$company['code']);if(!$raw['committed'])self::assertSame(1205,$raw['code']);
            self::assertTrue($a->inTransaction());
            fwrite(STDOUT,'PROTOCOL_MATRIX '.json_encode(['variant'=>$variant,'empty'=>$empty,'explain'=>$explain,'independent_company'=>$company,'same_company_other_shipment'=>$same,'raw_related_insert'=>$raw,'holder_still_open'=>true]).PHP_EOL);
        }finally{if($a->inTransaction())$a->rollBack();}
    }
    public function test_point_lookup_with_old_snapshot_cannot_discover_new_cost_membership():void{
        $s=$this->source;$a=$this->pdo();$b=$this->pdo();$a->beginTransaction();
        try{$old=$a->query('SELECT id FROM shipment_costs WHERE shipment_id='.$s['shipment'])->fetchAll(\PDO::FETCH_COLUMN);
            $b->beginTransaction();$b->query('SELECT id FROM shipments WHERE id='.$s['shipment'].' FOR UPDATE')->fetchAll();
            $b->exec("UPDATE shipment_costs SET amount=20.001 WHERE id=".$s['cost']);
            $b->exec("INSERT INTO shipment_costs(company_id,branch_id,shipment_id,expense_type_id,expense_date,amount) VALUES(1,1,".$s['shipment'].",1,'2026-10-14',7)");$b->commit();
            $a->query('SELECT id FROM shipments WHERE id='.$s['shipment'].' FOR UPDATE')->fetchAll();
            $discovered=$a->query('SELECT id FROM shipment_costs WHERE shipment_id='.$s['shipment'])->fetchAll(\PDO::FETCH_COLUMN);$point=[];foreach($discovered as$id)$point[]=$a->query('SELECT amount FROM shipment_costs WHERE id='.(int)$id.' FOR UPDATE')->fetchColumn();
            $plain=$a->query('SELECT amount FROM shipment_costs WHERE id='.$s['cost'])->fetchColumn();
            self::assertCount(1,$old);self::assertCount(1,$point);self::assertSame('20.001',$point[0]);self::assertSame('20.000',$plain);self::assertSame(2,(int)$b->query('SELECT COUNT(*) FROM shipment_costs WHERE shipment_id='.$s['shipment'])->fetchColumn());
            fwrite(STDOUT,'POINT_MEMBERSHIP_STALE '.json_encode(['discovered_count'=>count($discovered),'actual_count'=>2,'point_value'=>$point[0],'plain_stale'=>$plain]).PHP_EOL);
        }finally{if($a->inTransaction())$a->rollBack();if($b->inTransaction())$b->rollBack();}
    }
    private function armOneRootRc():void{
        // The operational entry must now select RC itself. No test hook may
        // supply the isolation requirement on its behalf.
        self::assertSame(0,DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
    public function test_rc_fk_multiple_cost_values_are_current_and_stable():void{
        $this->fk();$sid=$this->source['shipment'];$p=$this->pdo();
        foreach(['7.001','3.002']as$amount)DB::table('shipment_costs')->insert(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$sid,'expense_type_id'=>1,'expense_date'=>'2026-10-14','amount'=>$amount]);
        $p->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$p->beginTransaction();
        try{
            $p->query('SELECT id FROM shipments WHERE id='.$sid.' FOR UPDATE')->fetchAll();
            $sql='SELECT amount FROM shipment_costs FORCE INDEX(idx_shipment) WHERE company_id=1 AND shipment_id='.$sid.' ORDER BY id FOR UPDATE';
            $values=$p->query($sql)->fetchAll(\PDO::FETCH_COLUMN);self::assertSame(['20.000','7.001','3.002'],$values);
            $other=$this->probe($this->worker('service',2,$this->otherCompany));self::assertTrue($other['committed']);
            $related=$this->probe($this->worker('raw',1,$sid));self::assertFalse($related['committed']);self::assertSame(1205,$related['code']);
            self::assertSame($values,$p->query($sql)->fetchAll(\PDO::FETCH_COLUMN));self::assertTrue($p->inTransaction());
        }finally{if($p->inTransaction())$p->rollBack();}
    }
    private function allocationMode(bool $enabled):void{
        if(!$enabled)return;
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_cost_allocation_receipts.php');$migration->up();
        config(['shipment_allocation.enabled'=>true]);
    }
    public static function roots():array{return [['normal',false,false],['support',false,false],['support',true,false],['normal',false,true],['support',false,true],['support',true,true]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('roots')]
    public function test_rc_parent_fk_real_owner_freshness_and_no_isolation_leak(string$owner,bool$swallow,bool$allocation=false):void{
        $this->fk();$this->allocationMode($allocation);config(['shipment_cost_read.enabled'=>true]);$s=$this->source;$w=$this->pdo();$w->beginTransaction();$w->query('SELECT id FROM shipments WHERE id='.$s['shipment'].' FOR UPDATE')->fetchAll();$w->exec('UPDATE shipment_costs SET amount=20.001,foreign_amount=20.001 WHERE id='.$s['cost']);
        $global=DB::selectOne('SELECT @@global.tx_isolation v')->v;$active=true;$committed=false;$metrics=[];
        DB::listen(function($q)use(&$active,&$committed,$w){if($active&&!$committed&&DB::transactionLevel()>0&&str_contains($q->sql,'from `invoice_shipment_links`')){$w->commit();$committed=true;}});
        DB::connection()->beforeExecuting(function($sql)use(&$active,&$metrics,$s,$w){if(!$active||!str_starts_with($sql,'insert into `inventory_lots`'))return;$active=false;
            $capture=app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']);self::assertSame('20.001',$capture[0]['amount']);self::assertSame('20.001',DB::table('shipment_costs')->where('id',$s['cost'])->value('amount'));
            $metrics=$this->probe($this->worker('service',2,$this->otherCompany));self::assertTrue($metrics['committed']);self::assertLessThan(1000,$metrics['ms']);
            foreach(['UPDATE shipment_costs SET amount=22 WHERE id='.$s['cost'],'DELETE FROM shipment_costs WHERE id='.$s['cost'],"INSERT INTO shipment_costs(company_id,branch_id,shipment_id,expense_type_id,expense_date,amount) VALUES(1,1,".$s['shipment'].",1,'2026-10-14',7)"]as$query){try{$w->exec($query);self::fail('Related mutation bypassed protection');}catch(\PDOException$e){self::assertSame(1205,(int)$e->errorInfo[1]);}}
            self::assertSame($capture,app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']));throw new \RuntimeException('RC_OWNER_ROLLBACK_PROBE');
        });
        $scope=\App\Services\ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);$this->armOneRootRc();
        try{
            $op=fn()=>app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
            if($owner==='support')app(\App\Services\ShipmentTransactionOwner::class)->run(1,$s['invoice'],1,$scope,function()use($op,$swallow){if(!$swallow)return$op();try{$op();}catch(\Throwable$e){}return'swallowed';});else$op();self::fail('Owner committed failed probe');
        }catch(\RuntimeException$e){self::assertSame('RC_OWNER_ROLLBACK_PROBE',$e->getMessage());}finally{$active=false;if($w->inTransaction())$w->rollBack();}
        self::assertTrue($committed);self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());self::assertSame(1,DB::table('shipment_costs')->where('company_id',2)->count());
        // Behaviour, not @@session alone, proves next transaction reverted to RR.
        DB::beginTransaction();try{$old=DB::table('shipment_costs')->where('id',$s['cost'])->value('amount');$w->exec('UPDATE shipment_costs SET amount=20.002 WHERE id='.$s['cost']);self::assertSame($old,DB::table('shipment_costs')->where('id',$s['cost'])->value('amount'));}finally{DB::rollBack();}
        self::assertSame($global,DB::selectOne('SELECT @@global.tx_isolation v')->v);self::assertSame('REPEATABLE-READ',DB::selectOne('SELECT @@session.tx_isolation v')->v);
        fwrite(STDOUT,'RC_REAL_OWNER '.json_encode(['owner'=>$owner,'swallowed'=>$swallow,'fresh'=>'20.001','independent_committed_before_release'=>$metrics,'next_transaction_rr'=>true,'rollback_exact'=>true]).PHP_EOL);
    }
    public function test_active_transaction_isolation_change_is_rejected():void{
        $p=$this->pdo();$p->beginTransaction();$p->query('SELECT * FROM shipment_costs')->fetchAll();try{$p->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');self::fail('Active isolation changed');}catch(\PDOException$e){self::assertSame(1568,(int)$e->errorInfo[1]);}finally{$p->rollBack();}
    }
    public static function ownerModes():array{return[['normal',false],['support',false],['normal',true],['support',true]];}
    public static function cacheModes():array{return [[1],[2],[3]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('cacheModes')]
    public function test_l2_hot_metadata_cache_can_hide_real_void_cost_wait(int$round):void{
        $s=$this->source;app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $db=DB::connection()->getDatabaseName();$gate=$db.'-void-gate';$hotKey=$db.'-metadata-hot';
        self::assertEquals(1,DB::selectOne('SELECT GET_LOCK(?,1) n',[$gate])->n);
        self::assertEquals(1,DB::selectOne('SELECT GET_LOCK(?,1) n',[$hotKey])->n);$a=$b=$hot=null;
        try{
            $a=new Process([PHP_BINARY,base_path('scripts/batch_two_void_cost_probe.php'),'void',(string)$s['invoice']],base_path());$a->setTimeout(22);$a->start();$this->await($a,'VOID_OWNS_COST_BEFORE_YEAR');preg_match('/CONNECTION (\d+)/',$a->getOutput(),$holder);
            $hot=$this->worker('metadata-hot',1,$s['shipment']);$this->await($hot,'HOT_METADATA_PRIMED');
            $b=new Process([PHP_BINARY,base_path('scripts/batch_two_void_cost_probe.php'),'cost',(string)$s['shipment']],base_path());$b->setTimeout(22);$b->start();$this->await($b,'COST_REQUEST_SHIPMENT');preg_match('/CONNECTION (\d+)/',$b->getOutput(),$waiter);
            $obs=$this->pdo();$edgeSql='SELECT r.trx_mysql_thread_id waiter,b.trx_mysql_thread_id holder,l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id='.(int)$waiter[1].' AND b.trx_mysql_thread_id='.(int)$holder[1];
            $engine='';$end=microtime(true)+2;
            do{$status=$obs->query('SHOW ENGINE INNODB STATUS')->fetch(\PDO::FETCH_ASSOC)['Status'];$live=substr($status,strpos($status,"\nTRANSACTIONS\n")?:0);if(preg_match('/(?:MySQL|MariaDB) thread id '.(int)$waiter[1].',/',$live,$match,PREG_OFFSET_CAPTURE)){$pos=$match[0][1];$candidate=substr($live,max(0,$pos-350),1000);if(str_contains($candidate,'LOCK WAIT')&&str_contains($candidate,'shipments'))$engine=$candidate;}if($engine==='')usleep(10000);}while($engine===''&&microtime(true)<$end);
            self::assertNotSame('',$engine,'Independent InnoDB status must prove a live wait: '.$status.' WORKER '.$b->getOutput());self::assertTrue($hot->isRunning());self::assertSame([],$obs->query($edgeSql)->fetchAll(\PDO::FETCH_ASSOC),'Counterfactual: metadata cache is stale while engine reports the real wait');
            DB::selectOne('SELECT RELEASE_LOCK(?)',[$hotKey]);$hot->wait();self::assertSame(0,$hot->getExitCode(),$hot->getOutput());
            $edge=[];$end=microtime(true)+2;do{usleep(150000);$edge=$obs->query($edgeSql)->fetchAll(\PDO::FETCH_ASSOC);}while(!$edge&&microtime(true)<$end);
            self::assertNotEmpty($edge);self::assertStringContainsString('shipments',json_encode($edge));self::assertStringNotContainsString('COMMITTED',$a->getOutput());self::assertStringNotContainsString('COMMITTED',$b->getOutput());
            fwrite(STDOUT,'L2_CACHE_COUNTERFACTUAL '.json_encode(['round'=>$round,'engine_live_wait'=>$engine,'hot_cache_edge'=>[],'refreshed_edge'=>$edge,'holder_still_open'=>true]).PHP_EOL);
        }finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$hotKey]);DB::selectOne('SELECT RELEASE_LOCK(?)',[$gate]);foreach([$hot,$a,$b]as$p)if($p)$p->wait();}
        self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(1,$b->getExitCode(),$b->getOutput());self::assertStringContainsString('L2_FINAL {"level":0,"pdo":false}',$b->getOutput());self::assertSame('VOID',DB::table('purchase_invoices')->where('id',$s['invoice'])->value('document_status'));self::assertSame(4,DB::table('journal_entries')->count());
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('ownerModes')]
    public function test_real_purchase_finance_rc_and_existing_void_unchanged(string$owner,bool$allocation=false):void{
        $this->fk();$this->allocationMode($allocation);config(['shipment_cost_read.enabled'=>true]);$s=$this->source;$scope=\App\Services\ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);$this->armOneRootRc();$svc=app(\App\Services\EnterpriseInvoiceService::class);$op=fn()=>$svc->post('PURCHASE',1,$s['invoice'],1,1);
        if($owner==='support')app(\App\Services\ShipmentTransactionOwner::class)->run(1,$s['invoice'],1,$scope,$op);else$op();
        self::assertEquals(120,DB::table('inventory_lots')->sum('total_cost'));self::assertEquals(10,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());
        self::assertSame(DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id'),DB::table('vouchers')->value('journal_entry_id'));
        $svc->post('PURCHASE',1,$s['invoice'],1,1);self::assertSame(2,DB::table('journal_entries')->count());
        $svc->void('PURCHASE',1,$s['invoice'],1,'Protocol proof',1);self::assertEquals(0,DB::table('inventory_lots')->sum('qty_remaining_kg'));
        foreach(DB::table('journal_entries')->pluck('id')as$j)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit')));self::assertSame(0,DB::transactionLevel());
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('ownerModes')]
    public function test_real_1205_at_current_cost_read_rolls_back_owner(string$owner,bool$allocation=false):void{
        $this->fk();$this->allocationMode($allocation);config(['shipment_cost_read.enabled'=>true]);$s=$this->source;$p=$this->pdo();$p->beginTransaction();$p->query('SELECT id FROM shipment_costs WHERE id='.$s['cost'].' FOR UPDATE')->fetchAll();$before=DB::selectOne('SELECT @@session.innodb_lock_wait_timeout v')->v;DB::statement('SET SESSION innodb_lock_wait_timeout=1');$scope=\App\Services\ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);$this->armOneRootRc();
        try{$op=fn()=>app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);if($owner==='support')app(\App\Services\ShipmentTransactionOwner::class)->run(1,$s['invoice'],1,$scope,$op);else$op();self::fail('Expected real1205');}
        catch(\Throwable$e){self::assertStringContainsString('1205',$e->getMessage());}finally{$p->rollBack();DB::statement('SET SESSION innodb_lock_wait_timeout='.(int)$before);}
        self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
    public static function waitModes():array{return [['normal',1,false],['support',1,false],['normal',2,false],['support',2,false],['normal',3,false],['support',3,false],['normal',1,true],['support',1,true]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('waitModes')]
    public function test_cost_update_committed_while_actual_root_waits_is_current(string$owner,int$round,bool$allocation=false):void{
        $this->fk();$this->allocationMode($allocation);config(['shipment_cost_read.enabled'=>true]);$s=$this->source;
        // Outside a transaction Laravel may SELECT via read PDO. Observe the
        // write PDO that will actually own BEGIN, not that independent session.
        $id=(int)DB::selectOne('SELECT CONNECTION_ID() n',[],false)->n;
        $trace=true;DB::connection()->beforeExecuting(function($sql,$bindings,$connection)use(&$trace,$id){if($trace&&(str_contains($sql,'for update')||str_contains($sql,'invoice_shipment_links'))){fwrite(STDOUT,'L2_ROOT_QUERY '.json_encode(['announced'=>$id,'actual'=>$connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),'level'=>$connection->transactionLevel(),'sql'=>$sql]).PHP_EOL);}});
        $p=$this->worker('wait-update',1,$s['shipment'],$s['cost'],$id);$this->await($p,'OWNS_COST');$scope=\App\Services\ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);$this->armOneRootRc();
        try{$op=fn()=>app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);if($owner==='support')app(\App\Services\ShipmentTransactionOwner::class)->run(1,$s['invoice'],1,$scope,$op);else$op();}finally{$trace=false;$p->wait();fwrite(STDOUT,'L2_WORKER_TRACE '.$p->getOutput().PHP_EOL);}
        self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('ROOT_WAIT_SEEN',$p->getOutput());self::assertEquals(121,DB::table('inventory_lots')->sum('total_cost'));
        self::assertSame('21.000',DB::table('vouchers')->value('amount'));self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        self::assertSame('POSTED',DB::table('purchase_invoices')->where('id',$s['invoice'])->value('document_status'));self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());
        foreach(DB::table('journal_entries')->pluck('id')as$j)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit')));
        self::assertEquals(10,DB::table('inventory_lots')->sum('qty_remaining_kg'));
        app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());
        fwrite(STDOUT,'RC_UPDATE_DURING_WAIT '.json_encode(['owner'=>$owner,'round'=>$round,'worker'=>$p->getOutput(),'lot_value'=>'121.000','root_committed'=>true,'duplicate_post_safe'=>true]).PHP_EOL);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('ownerModes')]
    public function test_real_1213_before_current_cost_read_rolls_back_owner(string$owner,bool$allocation=false):void{
        $this->fk();$this->allocationMode($allocation);config(['shipment_cost_read.enabled'=>true]);$s=$this->source;DB::statement('CREATE TABLE protocol_weight(n INT PRIMARY KEY) ENGINE=InnoDB');$key=DB::connection()->getDatabaseName().'-protocol-deadlock';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$p=$this->worker('deadlock',1,$s['shipment'],$s['cost']);$this->await($p,'OWNS_COST');$active=true;
        DB::connection()->beforeExecuting(function($sql)use(&$active,$key){if($active&&str_contains($sql,'shipment_costs FORCE INDEX')&&str_contains($sql,'for update')){$active=false;DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);}});
        $scope=\App\Services\ShipmentPurchasePostingScope::snapshot(1,$s['invoice']);$this->armOneRootRc();
        try{$op=fn()=>app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);if($owner==='support')app(\App\Services\ShipmentTransactionOwner::class)->run(1,$s['invoice'],1,$scope,$op);else$op();self::fail('Expected real1213');}
        catch(\Throwable$e){self::assertStringContainsString('1213',$e->getMessage());}finally{$active=false;DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$p->wait();}
        self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        fwrite(STDOUT,'PROTOCOL_REAL_1213 '.json_encode(['owner'=>$owner,'no_partial_finance'=>true]).PHP_EOL);
    }
}
