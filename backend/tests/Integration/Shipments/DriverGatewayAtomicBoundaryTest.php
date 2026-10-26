<?php
namespace Tests\Integration\Shipments;
use Illuminate\Support\Facades\DB;

class DriverGatewayAtomicBoundaryTest extends DriverPurchaseGatewayTest
{
    public function test_five_real_after_write_faults_rollback_and_retry():void
    {
        $g=new \Tests\Support\DriverGatewayPrototype($this->writer);
        foreach(['after_inventory','after_invoice','after_cost_journal','before_origin','after_origin'] as $stage){
            try{$g->purchaseOrigin('fault-stages',false,null,$stage);self::fail('Fault did not execute: '.$stage);}catch(\RuntimeException $e){self::assertStringContainsString('FAULT_STAGE_'.$stage,$e->getMessage());}
            foreach(['purchase_invoices','purchase_invoice_lines','invoice_shipment_links','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','shipment_costs','gw_origins','gw_requests','gw_payments','vouchers'] as $t)self::assertSame(0,DB::table($t)->count(),$stage.' '.$t);
            self::assertSame('READY',DB::table('shipments')->value('commercial_status'));self::assertNull(DB::table('shipment_items')->value('inventory_lot_id'));
        }
        $o=$g->purchaseOrigin('fault-stages');self::assertSame($o,$g->purchaseOrigin('fault-stages'));
        $p=$g->pay(1,1,$o['origin_id'],'400','partial');self::assertSame($p,$g->pay(1,1,$o['origin_id'],'400','partial'));
        self::assertSame('0.000',$g->pay(1,1,$o['origin_id'],'600','full')['remaining']);
        try{$g->pay(1,1,$o['origin_id'],'1','excess');self::fail('Overpayment accepted');}catch(\RuntimeException $e){self::assertSame('OVERPAYMENT',$e->getMessage());}
        self::assertSame(1,DB::table('gw_origins')->count());self::assertSame(2,DB::table('gw_payments')->count());self::assertSame(4,DB::table('journal_entries')->count());
    }
    public function test_caller_rollback_cannot_rollback_committed_writer_process():void
    {
        // Negative control: intentionally demonstrate the WRONG two-transaction assumption.
        DB::beginTransaction();DB::table('drivers')->where('id',1)->update(['driver_name'=>'Uncommitted caller']);
        $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'purchase','separate-writer'],base_path(),['SULB_GW_WRITER'=>json_encode($this->writer)]);$p->setTimeout(20);
        try{$p->run();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());}finally{DB::rollBack();}
        self::assertSame('Synthetic driver',DB::table('drivers')->where('id',1)->value('driver_name'));
        self::assertSame(1,DB::table('gw_origins')->count());self::assertSame(1,DB::table('purchase_invoices')->where('document_status','POSTED')->count());
    }
    public function test_one_writer_connection_and_no_visibility_before_commit():void
    {
        $connections=[];$active=true;$name=$this->writer['username'];
        DB::listen(function($event)use(&$connections,&$active,$name){if($active&&$event->connection->getConfig('username')===$name)$connections[spl_object_id($event->connection->getPdo())]=true;});
        $g=new \Tests\Support\DriverGatewayPrototype($this->writer);
        $o=$g->purchaseOrigin('single-transaction',false,function(){
            self::assertSame(1,DB::connection()->transactionLevel());self::assertTrue(DB::connection()->getPdo()->inTransaction());
            $observer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            foreach(['purchase_invoices','inventory_lots','stock_movements','shipment_costs','journal_entries','gw_origins'] as $t)self::assertSame(0,(int)$observer->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(),'Uncommitted visibility '.$t);
        });$active=false;
        self::assertCount(1,$connections);self::assertSame(1,DB::table('gw_origins')->count());self::assertSame(1,DB::table('purchase_invoices')->where('document_status','POSTED')->count());
    }
    private function abortedWriter(bool $timeout):void
    {
        $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'purchase-hold','abort-retry'],base_path(),['SULB_GW_WRITER'=>json_encode($this->writer)]);
        $input=new \Symfony\Component\Process\InputStream();$p->setInput($input);$p->setTimeout($timeout?3:15);$p->start();
        $deadline=microtime(true)+2;while(!str_contains($p->getOutput(),'BEFORE_COMMIT_BARRIER')&&microtime(true)<$deadline)usleep(10000);
        self::assertStringContainsString('BEFORE_COMMIT_BARRIER',$p->getOutput(),$p->getErrorOutput());
        self::assertTrue($p->isRunning(),'Writer must actually be waiting before cancellation');
        foreach(['gw_origins','journal_entries','vouchers','stock_movements'] as $t)self::assertSame(0,DB::table($t)->count());
        if($timeout){try{$p->wait();self::fail('Timeout not raised');}catch(\Symfony\Component\Process\Exception\ProcessTimedOutException $e){self::assertTrue(true);}finally{$p->stop(0);}}else $p->stop(0);
        // Reacquisition is deterministic evidence that writer no longer holds company lock.
        DB::statement('SET SESSION innodb_lock_wait_timeout=3');DB::beginTransaction();try{DB::table('companies')->where('id',1)->lockForUpdate()->first();}finally{DB::rollBack();}
        foreach(['purchase_invoices','shipment_costs','inventory_lots','stock_movements','journal_entries','gw_origins','gw_requests'] as $t)self::assertSame(0,DB::table($t)->count(),$t.' after writer exit');
        $g=new \Tests\Support\DriverGatewayPrototype($this->writer);$result=$g->purchaseOrigin('abort-retry');self::assertSame($result,$g->purchaseOrigin('abort-retry'));self::assertSame(1,DB::table('gw_origins')->count());
    }
    public function test_writer_process_exit_before_commit_rolls_back():void{$this->abortedWriter(false);}
    public function test_writer_timeout_cancel_before_commit_rolls_back():void{$this->abortedWriter(true);}
    public function test_known_lock_barrier_prevents_payment_without_monitor_dependency():void
    {
        $g=new \Tests\Support\DriverGatewayPrototype($this->writer);$o=$g->purchaseOrigin('barrier-origin');
        DB::beginTransaction();DB::table('companies')->where('id',1)->lockForUpdate()->first();
        $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),'pay-barrier',(string)$o['origin_id'],'400','barrier-pay'],base_path(),['SULB_GW_WRITER'=>json_encode($this->writer)]);$p->setTimeout(15);
        try{
            $p->start();$deadline=microtime(true)+5;while(!str_contains($p->getOutput(),'COMPANY_LOCK_REQUEST')&&microtime(true)<$deadline)usleep(10000);
            self::assertStringContainsString('COMPANY_LOCK_REQUEST',$p->getOutput());usleep(300000);self::assertTrue($p->isRunning());
            self::assertSame(0,DB::table('vouchers')->count());self::assertSame(0,DB::table('gw_payments')->count());self::assertSame(2,DB::table('journal_entries')->count());
            $state=DB::select("SELECT trx_mysql_thread_id,trx_state FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT'");fwrite(STDOUT,'OPTIONAL_LOCK_MONITOR '.json_encode($state)."\n");
        }finally{DB::rollBack();}
        $p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());self::assertSame(1,DB::table('vouchers')->count());self::assertSame(1,DB::table('gw_payments')->count());self::assertSame(3,DB::table('journal_entries')->count());
        self::assertEquals(600,DB::table('journal_entry_lines')->where('account_id',2)->where('party_type','DRIVER')->sum(DB::raw('credit-debit')));
    }
    public function test_definer_procedure_uses_callers_transaction_without_direct_dml():void
    {
        $db=DB::connection()->getDatabaseName();$user='g6_probe_'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(16));
        DB::unprepared('CREATE TABLE gw_definer_probe(id INT PRIMARY KEY,amount DECIMAL(15,3)) ENGINE=InnoDB');
        DB::unprepared("CREATE PROCEDURE gw_probe(IN p_amount DECIMAL(15,3)) SQL SECURITY DEFINER BEGIN IF p_amount <> 1000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Typed operation rejected'; END IF; INSERT INTO gw_definer_probe VALUES(1,p_amount); END");
        DB::unprepared("CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$password'");
        try{
            DB::unprepared("GRANT SELECT ON `$db`.`gw_definer_probe` TO '$user'@'127.0.0.1'");DB::unprepared("GRANT EXECUTE ON PROCEDURE `$db`.`gw_probe` TO '$user'@'127.0.0.1'");
            $app=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.$db,$user,$password,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            foreach(['UPDATE journal_entry_lines SET debit=99','INSERT INTO gw_origins(id) VALUES(999)','DELETE FROM gw_payments',"SELECT authentication_string FROM mysql.user"] as $sql){try{$app->exec($sql);self::fail('Protected access permitted');}catch(\PDOException $e){self::assertStringContainsString('denied',$e->getMessage());}}
            try{$app->exec('INSERT INTO gw_definer_probe VALUES(1,1000)');self::fail('Direct write permitted');}catch(\PDOException $e){self::assertStringContainsString('denied',$e->getMessage());}
            $app->beginTransaction();$app->exec('CALL gw_probe(1000)');self::assertSame(1,(int)$app->query('SELECT COUNT(*) FROM gw_definer_probe')->fetchColumn());$app->rollBack();self::assertSame(0,DB::table('gw_definer_probe')->count());
            $app->beginTransaction();$app->exec('CALL gw_probe(1000)');$app->commit();self::assertSame(1,DB::table('gw_definer_probe')->count());
            try{$app->exec('CALL gw_probe(99)');self::fail('Untyped value accepted');}catch(\PDOException $e){self::assertStringContainsString('Typed operation rejected',$e->getMessage());}
        }finally{DB::unprepared("DROP USER IF EXISTS '$user'@'127.0.0.1'");DB::unprepared('DROP PROCEDURE gw_probe');}
    }
}
