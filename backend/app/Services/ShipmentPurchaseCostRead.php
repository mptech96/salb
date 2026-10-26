<?php
namespace App\Services;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/** Purchase-only current-read gate; never begins/commits a transaction or allocates money. */
#[Scoped]
final class ShipmentPurchaseCostRead
{
    private ?array $scope=null;

    private function root():object
    {
        $c=DB::connection();
        if($c->transactionLevel()<1||!$c->getPdo()->inTransaction())throw new \RuntimeException('COST_READ_TRANSACTION_REQUIRED');
        return app('db.transactions')->getPendingTransactions()->first(fn($r)=>$r->connection===$c->getName()&&$r->level===1)
            ??throw new \RuntimeException('COST_READ_ROOT_REQUIRED');
    }
    private function read(int $company,int $shipment):\Illuminate\Support\Collection
    {
        // Existing shipment index bounds this current-read scan. Parent H is
        // the sanctioned producer membership mutex, including an empty set.
        return DB::table(DB::raw('shipment_costs FORCE INDEX (idx_shipment)'))
            ->where('company_id',$company)->where('shipment_id',$shipment)->orderBy('id')->lockForUpdate()->get();
    }
    public function prepare(int $company,array $shipments):void
    {
        $root=$this->root();$c=DB::connection();$ids=array_values(array_unique(array_map('intval',$shipments)));sort($ids,SORT_NUMERIC);$rows=[];
        if($this->scope!==null&&$this->scope['root']===$root)throw new \RuntimeException('COST_READ_LATE_EXPANSION');
        foreach($ids as $id){
            app(ShipmentInventoryLockScope::class)->assertPrepared($company,$id);
            $rows[$id]=$this->read($company,$id)->map(fn($row)=>(array)$row)->all();
        }
        $this->scope=['root'=>$root,'connection'=>$c,'pdo'=>$c->getPdo(),'company'=>$company,'rows'=>$rows,'consumed'=>[]];
    }
    public function draftsForCurrentPurchase(int $company,int $shipment):?\Illuminate\Support\Collection
    {
        $s=$this->scope;$c=DB::connection();
        if(!$s||$s['root']!==$this->root()||$s['connection']!==$c||$s['pdo']!==$c->getPdo()
            ||$s['company']!==$company||!array_key_exists($shipment,$s['rows'])||isset($s['consumed'][$shipment]))return null;
        app(ShipmentInventoryLockScope::class)->assertPrepared($company,$shipment);
        $current=$this->read($company,$shipment);
        if($current->map(fn($row)=>(array)$row)->all()!==$s['rows'][$shipment])throw new \RuntimeException('COST_READ_INPUTS_CHANGED');
        $this->scope['consumed'][$shipment]=true;
        return $current->filter(fn($row)=>$row->cost_status==='DRAFT')->values();
    }
    /** Fresh captured inputs for the later allocator; not an API deserializer. */
    public function captured(int $company,int $shipment):array
    {
        $s=$this->scope;$c=DB::connection();
        if(!$s||$s['root']!==$this->root()||$s['company']!==$company||$s['connection']!==$c||$s['pdo']!==$c->getPdo()||!array_key_exists($shipment,$s['rows']))throw new \RuntimeException('COST_READ_SCOPE_REQUIRED');
        if($this->read($company,$shipment)->map(fn($r)=>(array)$r)->all()!==$s['rows'][$shipment])throw new \RuntimeException('COST_READ_INPUTS_CHANGED');
        return $s['rows'][$shipment];
    }
}
