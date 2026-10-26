<?php
namespace App\Services;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/** Internal request-scoped ownership. Never accepts a transport token. */
#[Scoped]
final class ShipmentTransactionOwner
{
    private ?array $active = null;

    public function run(int $company, int $invoice, int $user, array $scope, callable $operation, string $purpose='PURCHASE_POST'): mixed
    {
        $connection=DB::connection();
        $pdo=$connection->getPdo();
        if(!in_array($purpose,['PURCHASE_POST','PURCHASE_VOID','SALE_POST'],true))throw new \LogicException('Unknown internal financial purpose');
        if($this->active!==null || $connection->transactionLevel()!==0 || $pdo->inTransaction() || (!$scope&&$purpose!=='PURCHASE_VOID'))
            throw new \RuntimeException('SHIPMENT_POST_OUTERMOST_REQUIRED');
        $restoreIsolation=$purpose==='PURCHASE_POST'?ShipmentCostReadIsolation::prepare($scope):null;
        try {
            return $connection->transaction(function () use ($connection,$company,$invoice,$user,$scope,$operation,$purpose) {
                // Purchase-only first financial X-lock; SALE adds no company lock.
                if($scope&&$purpose!=='SALE_POST')app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);
                $transaction=app('db.transactions')->getPendingTransactions()->first(
                    fn($record)=>$record->connection===$connection->getName() && $record->level===1);
                if(!$transaction)throw new \RuntimeException('SHIPMENT_POST_OWNER_TRANSACTION_MISSING');
                $this->active=['connection'=>$connection,'pdo'=>$connection->getPdo(),
                    'transaction'=>$transaction,'company'=>$company,'invoice'=>$invoice,'user'=>$user,'scope'=>$scope,'purpose'=>$purpose,'failure'=>null];
                $result=$operation(); // Includes successful financial audit.
                $this->throwIfRollbackOnly();
                $this->scope($company,$invoice,$user,$purpose); // Same physical/root transaction before COMMIT.
                return $result;
            });
        } catch (\Throwable $failure) {
            // Nested Laravel concurrency handling can decrement its bookkeeping
            // without rolling back a live PDO transaction (notably MySQL1205).
            // This root owner, not the Controller, must abort the physical root.
            try {
                if($connection->getPdo()!==$pdo)throw new \RuntimeException('Owner connection replaced');
                if($connection->transactionLevel()>0)$connection->rollBack(0);
                if($pdo->inTransaction())$pdo->rollBack();
                if($connection->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('Root rollback incomplete');
                if($this->active!==null && app('db.transactions')->getPendingTransactions()->contains(
                    fn($record)=>$record===$this->active['transaction']))throw new \RuntimeException('Root transaction record still pending');
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException('SHIPMENT_ROLLBACK_UNCONFIRMED',0,$rollbackFailure);
            }
            if($failure instanceof ShipmentPostingFailure)
                throw new ShipmentPostingFailure($failure->getPrevious()??$failure,true);
            throw $failure;
        } finally {
            $this->active=null; // Also after COMMIT failure, exception or rollback.
            if($restoreIsolation!==null)$restoreIsolation();
        }
    }

    public function markRollbackOnly(\Throwable $cause): ShipmentPostingFailure
    {
        if($this->active===null)throw new \LogicException('No trusted shipment owner');
        return $this->active['failure']??=new ShipmentPostingFailure($cause);
    }

    public function throwIfRollbackOnly(): void
    {
        if($this->active!==null && $this->active['failure']!==null)throw $this->active['failure'];
    }

    public function scope(int $company,int $invoice,int $user,string $purpose='PURCHASE_POST'): ?array
    {
        if($this->active===null) return null;
        $a=$this->active; $connection=DB::connection();
        if($a['connection']!==$connection || $a['pdo']!==$connection->getPdo()
            || !$connection->getPdo()->inTransaction()
            || !app('db.transactions')->getPendingTransactions()->contains(fn($record)=>$record===$a['transaction'])
            || $connection->transactionLevel()!==1 || $a['company']!==$company
            || $a['invoice']!==$invoice || $a['user']!==$user || $a['purpose']!==$purpose)
            throw new \RuntimeException('SHIPMENT_POST_OWNER_SCOPE_MISMATCH');
        return $a['scope'];
    }
}
