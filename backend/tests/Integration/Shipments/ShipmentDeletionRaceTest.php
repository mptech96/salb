<?php
namespace Tests\Integration\Shipments;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Real two-connection InnoDB lock tests, run only by the owned-server runner. */
class ShipmentDeletionRaceTest extends \Tests\Feature\Shipments\ShipmentStageOneSafetyTest
{
    private function worker(string $operation,int $id=0): Process
    {
        $process=new Process([PHP_BINARY,base_path('scripts/shipment_race_worker.php'),$operation,(string)$id],base_path());
        $process->setTimeout(15);$process->start();
        $deadline=microtime(true)+5;
        while(!str_contains($process->getOutput(),'READY')&&microtime(true)<$deadline){usleep(10000);}
        self::assertStringContainsString('READY',$process->getOutput(),$process->getErrorOutput());
        // Require actual lock waiting, not merely a sleeping subprocess.
        preg_match('/READY (\d+)/',$process->getOutput(),$match);
        $observer=new \PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $waiting=false;$start=microtime(true);$deadline=$start+10;$polls=0;
        while(microtime(true)<$deadline){
            $query=$observer->prepare("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'");
            $query->execute([(int)$match[1]]);$waiting=(int)$query->fetchColumn()>0;$polls++;
            if($waiting)break;usleep(10000);
        }
        $snapshot=$observer->query('SELECT trx_mysql_thread_id,trx_state,trx_wait_started FROM information_schema.INNODB_TRX')->fetchAll(\PDO::FETCH_ASSOC);
        fwrite(STDOUT,'LOCK_OBSERVER '.json_encode(['observed'=>$waiting,'polls'=>$polls,'observation_ms'=>(microtime(true)-$start)*1000,'child_id'=>(int)$match[1],'snapshot'=>$snapshot])."\n");
        if(!$waiting){$processState=$observer->query('SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID='.(int)$match[1])->fetchAll(\PDO::FETCH_ASSOC);$diagnostic=$process->getOutput().' '.$process->getErrorOutput().' '.json_encode(['transactions'=>$snapshot,'process'=>$processState,'child_running'=>$process->isRunning()]);$process->stop(1);self::fail('Child must block on an actual database lock: '.$diagnostic);}
        return $process;
    }

    public function test_delete_wins_then_actual_post_creates_no_orphan(): void
    {
        self::assertSame('mysql',DB::connection()->getDriverName());
        $service=app(\App\Services\ShipmentCostService::class);
        $id=$service->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>100,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>false]);
        DB::beginTransaction();
        try{DB::table('shipments')->where('id',1)->lockForUpdate()->first();$child=$this->worker('post');$service->deleteDraft(1,$id,1);DB::commit();}
        catch(\Throwable $e){DB::rollBack();throw $e;}
        $child->wait();self::assertSame(0,$child->getExitCode(),$child->getErrorOutput());
        self::assertSame(0,DB::table('shipment_costs')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('vouchers')->count());
    }

    public function test_post_wins_then_waiting_delete_rejects_and_preserves_evidence(): void
    {
        self::assertSame('mysql',DB::connection()->getDriverName());
        $child=null;
        $distribution=\Mockery::mock(\App\Services\ShipmentCostDistributionService::class);
        $distribution->shouldReceive('distributeByShipmentId')->andReturnUsing(function()use(&$child){$child=$this->worker('delete',1);return [];});
        $this->app->instance(\App\Services\ShipmentCostDistributionService::class,$distribution);
        $service=app(\App\Services\ShipmentCostService::class);
        $id=$service->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>100,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>false]);
        self::assertSame(1,$id);
        $service->postPendingForShipment(1,1,1);
        $child->wait();self::assertSame(1,$child->getExitCode(),$child->getErrorOutput());self::assertStringContainsString('REJECTED',$child->getOutput());
        self::assertSame('POSTED',DB::table('shipment_costs')->where('id',$id)->value('cost_status'));
        self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(2,DB::table('journal_entry_lines')->count());self::assertSame(1,DB::table('vouchers')->count());
    }
    public function test_lock_wait_observer_repeated_ten_times():void
    {
        $service=app(\App\Services\ShipmentCostService::class);
        for($i=0;$i<10;$i++){
            $id=$service->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>100,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>false]);
            DB::beginTransaction();try{DB::table('shipments')->where('id',1)->lockForUpdate()->first();$child=$this->worker('post');}finally{DB::rollBack();}
            $child->wait();self::assertSame(0,$child->getExitCode(),$child->getOutput().$child->getErrorOutput());
            self::assertSame('POSTED',DB::table('shipment_costs')->where('id',$id)->value('cost_status'));
        }
        self::assertSame(10,DB::table('vouchers')->count());self::assertSame(10,DB::table('journal_entries')->count());
    }
    public function test_observer_misses_are_separated_from_financial_completion():void
    {
        $service=app(\App\Services\ShipmentCostService::class);$results=[];
        for($i=0;$i<10;$i++){
            $id=$service->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>100,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>false]);
            DB::beginTransaction();DB::table('shipments')->where('id',1)->lockForUpdate()->first();
            $p=new Process([PHP_BINARY,base_path('scripts/shipment_race_worker.php'),'post'],base_path());$p->setTimeout(30);$p->start();$observed=false;$state=[];
            try{
                $deadline=microtime(true)+5;while(!str_contains($p->getOutput(),'READY')&&microtime(true)<$deadline)usleep(10000);self::assertStringContainsString('READY',$p->getOutput());preg_match('/READY (\d+)/',$p->getOutput(),$m);
                $observer=new \PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$deadline=microtime(true)+3;
                while(microtime(true)<$deadline){$observed=(int)$observer->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=".(int)$m[1]." AND trx_state='LOCK WAIT'")->fetchColumn()>0;if($observed)break;usleep(20000);}
                $state=$observer->query('SELECT COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID='.(int)$m[1])->fetchAll(\PDO::FETCH_ASSOC);
                // Independently prove source/voucher/journal are still untouched under the held parent lock.
                self::assertSame('DRAFT',DB::table('shipment_costs')->where('id',$id)->value('cost_status'));self::assertSame($i,DB::table('vouchers')->count());
            }finally{DB::rollBack();}
            $p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());self::assertSame('POSTED',DB::table('shipment_costs')->where('id',$id)->value('cost_status'));
            $results[]=['observed'=>$observed,'financial_completion'=>true,'before_release'=>$state];
        }
        self::assertSame(10,DB::table('vouchers')->count());fwrite(STDOUT,'OBSERVER_VS_FINANCIAL '.json_encode($results)."\n");
        // This diagnostic proves financial completion; missing LOCK WAIT remains a separately failed monitor contract.
    }
}
