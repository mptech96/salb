<?php
namespace App\Services;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/** Internal lock evidence only. Never starts or commits a transaction. */
#[Scoped]
final class ShipmentInventoryLockScope
{
    private ?array $prepared=null;

    public function prepare(int $company,array $shipments,iterable $extraItems=[]):void
    {
        $connection=DB::connection();
        if($connection->transactionLevel()<1||!$connection->getPdo()->inTransaction())throw new \RuntimeException('SHIPMENT_SCOPE_TRANSACTION_REQUIRED');
        $root=app('db.transactions')->getPendingTransactions()->first(fn($r)=>$r->connection===$connection->getName()&&$r->level===1);
        if(!$root)throw new \RuntimeException('SHIPMENT_SCOPE_ROOT_REQUIRED');
        $shipments=array_values(array_unique(array_map('intval',$shipments)));sort($shipments,SORT_NUMERIC);
        if($this->prepared!==null&&$this->prepared['root']===$root)throw new \RuntimeException('SHIPMENT_SCOPE_LATE_EXPANSION');
        foreach($shipments as$id)if(!DB::table('shipments')->where('company_id',$company)->where('id',$id)->lockForUpdate()->first())throw new \RuntimeException('SHIPMENT_SCOPE_SOURCE_MISSING');
        // Discovery may be an old RR view. Never trust it without a current
        // locked reread. I must precede R: FIFO updates source shipment rows
        // while holding I/L, even when the SALE has a different shipment H.
        $discovered=$shipments?DB::table('shipment_items')->where('company_id',$company)->whereIn('shipment_id',$shipments)->orderBy('id')->get():collect();
        $items=array_merge(is_array($extraItems)?$extraItems:iterator_to_array($extraItems),$discovered->pluck('item_id')->all());
        InventoryPostingLocks::lockItems($company,$items);
        $rows=$shipments?DB::table('shipment_items')->where('company_id',$company)->whereIn('shipment_id',$shipments)->orderBy('id')->lockForUpdate()->get():collect();
        if($rows->pluck('item_id','id')->all()!==$discovered->pluck('item_id','id')->all())throw new \RuntimeException('SHIPMENT_SCOPE_CHANGED');
        $this->prepared=['connection'=>$connection,'pdo'=>$connection->getPdo(),'root'=>$root,'company'=>$company,'shipments'=>$shipments,
            'rows'=>$rows->groupBy('shipment_id')->map(fn($group)=>$group->pluck('item_id','id')->all())->all()];
    }

    public function assertPrepared(int $company,int $shipment):void
    {
        $a=$this->prepared;$c=DB::connection();
        if(!$a||$a['connection']!==$c||$a['pdo']!==$c->getPdo()||!$c->getPdo()->inTransaction()
            ||!app('db.transactions')->getPendingTransactions()->contains(fn($r)=>$r===$a['root'])
            ||$a['company']!==$company||!in_array($shipment,$a['shipments'],true))throw new \RuntimeException('SHIPMENT_SCOPE_NOT_PREPARED');
        $rows=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$shipment)->orderBy('id')->lockForUpdate()->get();
        if($rows->pluck('item_id','id')->all()!==($a['rows'][$shipment]??[]))throw new \RuntimeException('SHIPMENT_SCOPE_CHANGED');
    }
}
