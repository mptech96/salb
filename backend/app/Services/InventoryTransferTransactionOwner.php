<?php
namespace App\Services;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/** Dedicated root for TRANSFER approval only; never a client-issued owner token. */
#[Scoped]
final class InventoryTransferTransactionOwner
{
    private ?array $active=null;

    public function owns(int $company,int $operation,int $user): bool
    {
        if($this->active===null)return false;
        $a=$this->active;$c=DB::connection();
        if($a['company']!==$company||$a['operation']!==$operation||$a['user']!==$user||$a['connection']!==$c||$a['pdo']!==$c->getPdo()
            ||!$c->getPdo()->inTransaction()||!app('db.transactions')->getPendingTransactions()->contains(fn($r)=>$r===$a['root']))throw new \RuntimeException('TRANSFER_OWNER_SCOPE_MISMATCH');
        return true;
    }
    public function run(int $company,int $operation,int $user,callable $callback): mixed
    {
        if(!config('inventory_transfer.root_protocol_enabled'))throw new \RuntimeException('TRANSFER_PROTOCOL_NOT_ENABLED');
        $c=DB::connection();$pdo=$c->getPdo();
        if($this->active!==null||$c->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('TRANSFER_OUTERMOST_REQUIRED');
        if(DB::table('inventory_operations')->useWritePdo()->where('company_id',$company)->where('id',$operation)->value('operation_type')!=='TRANSFER')throw new \RuntimeException('TRANSFER_SOURCE_REQUIRED');
        $default=null;
        if(in_array($c->getDriverName(),['mysql','mariadb'],true)){
            $default=str_replace('-',' ',(string)$pdo->query('SELECT @@session.tx_isolation')->fetchColumn());
            if(!in_array($default,['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'],true))throw new \RuntimeException('TRANSFER_ISOLATION_UNKNOWN');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        try {
            return $c->transaction(function()use($c,$pdo,$company,$operation,$user,$callback){
                $root=app('db.transactions')->getPendingTransactions()->first(fn($r)=>$r->connection===$c->getName()&&$r->level===1)
                    ??throw new \RuntimeException('TRANSFER_ROOT_REQUIRED');
                $this->active=['company'=>$company,'operation'=>$operation,'user'=>$user,'connection'=>$c,'pdo'=>$pdo,'root'=>$root,'failure'=>null];
                $result=$callback();$this->throwIfRollbackOnly();$this->owns($company,$operation,$user);
                return $result;
            });
        } catch(\Throwable $failure) {
            try {
                if($c->getPdo()!==$pdo)throw new \RuntimeException('Transfer PDO replaced');
                if($c->transactionLevel()>0)$c->rollBack(0);
                if($pdo->inTransaction())$pdo->rollBack();
                if($c->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('Transfer rollback incomplete');
            } catch(\Throwable $rollback) { throw new \RuntimeException('TRANSFER_ROLLBACK_UNCONFIRMED',0,$rollback); }
            if($failure instanceof ShipmentPostingFailure)throw new ShipmentPostingFailure($failure->getPrevious()??$failure,true);
            throw $failure;
        } finally {
            $this->active=null;
            if($default!==null){
                if($c->getPdo()!==$pdo||$c->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('TRANSFER_CLEANUP_UNCONFIRMED');
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL '.$default);
            }
        }
    }
    public function markRollbackOnly(\Throwable $failure): ShipmentPostingFailure
    {
        if($this->active===null)throw new \LogicException('No transfer owner');
        return $this->active['failure']??=new ShipmentPostingFailure($failure);
    }
    public function throwIfRollbackOnly():void
    {
        if($this->active!==null&&$this->active['failure']!==null)throw $this->active['failure'];
    }
}
