<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Two independent PHP connections, observed in InnoDB's live wait graph. */
final class BaseAdapterRace
{
    public static function run(string $mode,string $table,int $id,int|array|null $commandId=null):array
    {
        UomStageAProof::guard();if(!in_array($table,['purchase_orders','purchase_invoices','opening_balance_batches','items','uom_inventory_adjustments','inventory_operations','shipments','companies','financial_years','journal_entries','financial_accounts'],true))throw new \RuntimeException('QA race table required');
        $holder=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $holder->beginTransaction();$lock=$holder->prepare("SELECT id FROM `$table` WHERE id=? FOR UPDATE");$lock->execute([$id]);$lock->fetchAll();
        $holderId=(int)$holder->query('SELECT CONNECTION_ID()')->fetchColumn();
        $observer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $workers=[];$threads=[];$waiting=[];$evidence='';
        try{
            for($i=0;$i<2;$i++){$p=new Process([PHP_BINARY,base_path('scripts/qa_base_adapter_worker.php'),$mode,(string)(is_array($commandId)?$commandId[$i]:($commandId??$id))],base_path());$p->setEnv(['SULB_QA_ADAPTER_VALUE_LEDGER'=>config('inventory_value.enabled')?'1':'0','SULB_QA_ADAPTER_SHIPMENT_L3'=>(config('shipment_allocation.enabled')&&config('shipment_cost_read.enabled'))?'1':'0']);$p->setTimeout(25);$p->start();$workers[]=$p;}
            $deadline=microtime(true)+8;
            do{
                foreach($workers as $i=>$worker)if(preg_match('/QA_THREAD ([0-9]+)/',$worker->getOutput(),$m))$threads[$i]=(int)$m[1];
                if(count($threads)===2){
                    $statement=$observer->prepare('SELECT req.trx_mysql_thread_id id FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX req ON req.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX held ON held.trx_id=w.blocking_trx_id WHERE req.trx_mysql_thread_id IN (?,?) AND held.trx_mysql_thread_id=?');
                    $statement->execute([...array_values($threads),$holderId]);$waiting=$statement->fetchAll(\PDO::FETCH_OBJ);$statement->closeCursor();
                    if(count(array_unique(array_column($waiting,'id')))===2){$evidence='INNODB_LOCK_WAITS';break;}
                    // MariaDB may omit live waits from information_schema. Accept only
                    // CURRENT monitor blocks proving both exact threads/table and holder.
                    $monitor=$observer->query('SHOW ENGINE INNODB STATUS')->fetch(\PDO::FETCH_ASSOC)['Status'];
                    $position=strpos($monitor,'LIST OF TRANSACTIONS FOR EACH SESSION:');
                    $current=$position===false?'':explode('FILE I/O',substr($monitor,$position),2)[0];
                    $found=[];$holding=false;
                    foreach(preg_split('/(?=^---TRANSACTION )/m',$current) as $block){
                        if(preg_match('/(?:MySQL|MariaDB) thread id '.$holderId.',/',$block)&&str_contains($block,'row lock(s)'))$holding=true;
                        foreach($threads as $thread)if(preg_match('/(?:MySQL|MariaDB) thread id '.$thread.',/',$block)&&str_contains($block,'LOCK WAIT')&&str_contains($block,'`'.DB::connection()->getDatabaseName().'`.`'.$table.'`')&&str_contains($block,'index PRIMARY')&&str_contains($block,' waiting'))$found[$thread]=(object)['id'=>$thread];
                    }
                    if($holding&&count($found)===2){$waiting=array_values($found);$evidence='CURRENT_INNODB_MONITOR';break;}
                }
                usleep(20000);
            }while(microtime(true)<$deadline);
        }finally{if($holder->inTransaction())$holder->rollBack();}
        $results=[];foreach($workers as $worker){$worker->wait();if(!$worker->isSuccessful())throw new \RuntimeException('QA worker failed: '.$worker->getErrorOutput());if(!preg_match('/QA_RESULT ([0-9]+)/',$worker->getOutput(),$m))throw new \RuntimeException('QA result missing: '.$worker->getOutput().' STDERR: '.$worker->getErrorOutput());$results[]=(int)$m[1];}
        if(count(array_unique(array_column($waiting,'id')))!==2)throw new \RuntimeException('Two real InnoDB waits were not observed: '.json_encode(['results'=>$results,'threads'=>$threads]));
        fwrite(STDOUT,'BASE_ADAPTER_WAIT '.json_encode(['threads'=>$threads,'holder'=>$holderId,'table'=>$table,'evidence'=>$evidence]).PHP_EOL);
        return ['threads'=>$threads,'waiting_threads'=>array_column($waiting,'id'),'results'=>$results];
    }
}
