<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

final class ShipmentFreshCostReadTest extends \Tests\TestCase
{
    protected function setUp():void{
        parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $n)require_once __DIR__.'/../../Support/'.$n.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES')as$r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();config(['shipment_cost_read.enabled'=>true]);
    }
    private function writer():\PDO{return new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName().';charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);}
    public static function changes():array{return [['update'],['insert'],['delete']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function test_current_locked_membership_ignores_old_repeatable_read(string $change):void{
        $s=F::purchase();$w=$this->writer();$w->beginTransaction();$w->query('SELECT id FROM shipments WHERE id='.$s['shipment'].' FOR UPDATE')->fetchAll();
        if($change==='update')$w->exec('UPDATE shipment_costs SET amount=20.001,foreign_amount=20.001 WHERE id='.$s['cost']);
        elseif($change==='delete')$w->exec('DELETE FROM shipment_costs WHERE id='.$s['cost']);
        else{$row=(array)DB::table('shipment_costs')->where('id',$s['cost'])->first();unset($row['id']);$row['amount']='7.000';$row['foreign_amount']='7.000';$cols=implode(',',array_map(fn($k)=>'`'.$k.'`',array_keys($row)));$w->prepare('INSERT INTO shipment_costs('.$cols.') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));}
        $committed=false;$active=true;$captured=[];$writes=[];
        DB::listen(function($q)use(&$active,&$committed,$w){if($active&&!$committed&&DB::transactionLevel()===1&&str_starts_with($q->sql,'select * from `invoice_shipment_links`')&&!str_contains($q->sql,'for update')){$w->commit();$committed=true;}});
        DB::connection()->beforeExecuting(function($sql)use(&$active,&$captured,&$writes,$s){if(!$active)return;if(str_starts_with($sql,'insert into `inventory_lots`')){
            $active=false;$captured=app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']);throw new \RuntimeException('FRESH_READ_BEFORE_FIRST_WRITE');
        }if(preg_match('/^(insert into|update) `(inventory_lots|stock_movements|journal_entries|journal_entry_lines|vouchers)`/',$sql))$writes[]=$sql;});
        try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);self::fail('Expected probe');}catch(\RuntimeException$e){self::assertSame('FRESH_READ_BEFORE_FIRST_WRITE',$e->getMessage());}finally{$active=false;if($w->inTransaction())$w->rollBack();}
        self::assertTrue($committed);self::assertCount($change==='insert'?2:($change==='delete'?0:1),$captured);if($change==='update')self::assertSame('20.001',$captured[0]['amount']);
        self::assertSame([],$writes);self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::transactionLevel());
        try{app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']);self::fail('Stale root accepted');}catch(\RuntimeException$e){self::assertSame('COST_READ_TRANSACTION_REQUIRED',$e->getMessage());}
        fwrite(STDOUT,'FRESH_COST_SET '.json_encode(['change'=>$change,'amounts'=>array_column($captured,'amount'),'before_financial_writes'=>true]).PHP_EOL);
    }
    private function await(Process$p,string$text):void{$end=microtime(true)+5;while($p->isRunning()&&!str_contains($p->getOutput(),$text)&&microtime(true)<$end)usleep(10000);self::assertStringContainsString($text,$p->getOutput(),$p->getErrorOutput());}
    private function worker(string$op,int$c,int$s,int$cost=0):Process{$p=new Process([PHP_BINARY,base_path('scripts/shipment_cost_read_worker.php'),$op,(string)$c,(string)$s,(string)$cost],base_path());$p->setTimeout(12);$p->start();return$p;}
    public static function afterChanges():array{return [['create'],['update'],['delete']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('afterChanges')]
    public function test_sanctioned_mutation_waits_on_parent_until_owner_releases(string$op):void{
        $s=F::purchase();$active=true;$p=null;
        DB::connection()->beforeExecuting(function($sql)use(&$active,&$p,$s,$op){if(!$active||!str_starts_with($sql,'insert into `inventory_lots`'))return;$active=false;
            $original=app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']);
            $p=$this->worker($op,1,$s['shipment'],$s['cost']);$this->await($p,'REQUEST_H');preg_match('/CONNECTION (\d+)/',$p->getOutput(),$id);
            $wait=[];$end=microtime(true)+2;do{$wait=DB::select('SELECT l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?',[(int)$id[1]]);if(!$wait)usleep(10000);}while(!$wait&&microtime(true)<$end);
            self::assertNotEmpty($wait);self::assertStringContainsString('shipments',json_encode($wait));self::assertStringNotContainsString('OWNS_H',$p->getOutput());self::assertStringNotContainsString('COMMITTED',$p->getOutput());
            self::assertSame($original,app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']));
            fwrite(STDOUT,'COST_SET_PARENT_MUTEX '.json_encode(['operation'=>$op,'wait'=>$wait]).PHP_EOL);throw new \RuntimeException('RELEASE_PARENT_MUTEX');
        });
        try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);}catch(\RuntimeException$e){self::assertSame('RELEASE_PARENT_MUTEX',$e->getMessage());}finally{$active=false;if($p)$p->wait();}
        self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('COMMITTED',$p->getOutput());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::transactionLevel());
    }
    public function test_own_root_cost_mutation_invalidates_capture():void{
        $s=F::purchase();$active=true;
        DB::connection()->beforeExecuting(function($sql)use(&$active,$s){if(!$active||!str_starts_with($sql,'insert into `inventory_lots`'))return;$active=false;
            DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>22]);app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment']);
        });
        try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);self::fail('Stale capture used');}catch(\RuntimeException$e){self::assertSame('COST_READ_INPUTS_CHANGED',$e->getMessage());}finally{$active=false;}
        self::assertSame('20.000',DB::table('shipment_costs')->where('id',$s['cost'])->value('amount'));self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::transactionLevel());
    }
    public function test_exact_cost_row_is_locked_before_inventory_write():void{
        $s=F::purchase();$active=true;$w=$this->writer();$w->exec('SET SESSION innodb_lock_wait_timeout=1');
        DB::connection()->beforeExecuting(function($sql)use(&$active,$s,$w){if(!$active||!str_starts_with($sql,'insert into `inventory_lots`'))return;$active=false;
            try{$w->exec('UPDATE shipment_costs SET amount=22 WHERE id='.$s['cost']);self::fail('Cost row was not locked');}catch(\PDOException$e){self::assertSame(1205,(int)$e->errorInfo[1]);}
            self::assertSame('20.000',app(\App\Services\ShipmentPurchaseCostRead::class)->captured(1,$s['shipment'])[0]['amount']);
            fwrite(STDOUT,'COST_CURRENT_READ_PLAN '.json_encode(DB::select('EXPLAIN SELECT * FROM shipment_costs FORCE INDEX (idx_shipment) WHERE company_id=1 AND shipment_id=? ORDER BY id FOR UPDATE',[$s['shipment']])).PHP_EOL);
            throw new \RuntimeException('EXACT_ROW_LOCK_PROBE');
        });
        try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);}catch(\RuntimeException$e){self::assertSame('EXACT_ROW_LOCK_PROBE',$e->getMessage());}finally{$active=false;}
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::transactionLevel());
    }
    public function test_other_company_new_cost_is_not_blocked_by_purchase_cost_range():void{
        $s=F::purchase();DB::table('expense_types')->insert(['id'=>2,'company_id'=>2,'type_name'=>'Other','type_code'=>'OTHER','account_id'=>22,'usage_type'=>'SHIPMENT','affects_cost'=>1]);
        $other=DB::table('shipments')->insertGetId(['company_id'=>2,'branch_id'=>2,'supplier_id'=>2,'shipment_number'=>'OTHER-SHIP','shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-14','commercial_status'=>'READY','currency_code'=>'SAR']);
        $p=null;$active=true;$blocked=false;
        DB::connection()->beforeExecuting(function($sql)use(&$active,&$p,&$blocked,$other){if(!$active||!str_starts_with($sql,'insert into `inventory_lots`'))return;$active=false;
            $p=$this->worker('create',2,$other);$this->await($p,'REQUEST_COST_INSERT');$end=microtime(true)+1.5;while($p->isRunning()&&microtime(true)<$end)usleep(10000);$blocked=$p->isRunning();
            if($blocked){$wait=DB::select('SELECT l.lock_table,l.lock_index,l.lock_data,r.trx_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id');fwrite(STDOUT,'COST_RANGE_CROSS_COMPANY_WAIT '.json_encode($wait).PHP_EOL);}
            throw new \RuntimeException('RELEASE_PURCHASE_PROBE');
        });
        try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);}catch(\RuntimeException$e){self::assertSame('RELEASE_PURCHASE_PROBE',$e->getMessage());}finally{$active=false;if($p)$p->wait();}
        self::assertNotNull($p);self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertFalse($blocked,'Independent company cost INSERT was blocked by the range-read gap lock');
    }
}
