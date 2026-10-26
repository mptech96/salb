<?php
namespace App\Services\Uom;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use App\Services\ShipmentPostingFailure;

/** Internal root for BASE Support documents only. No API ownership token or isolation change. */
#[Scoped]
final class OperationalTransactionOwner
{
    private ?array $active=null;

    public function owns(int $company,int $document,int $user,string $purpose):bool
    {
        if($this->active===null)return false;
        $a=$this->active;$c=DB::connection();
        if($a['company']!==$company||$a['document']!==$document||$a['user']!==$user||$a['purpose']!==$purpose
            ||$a['connection']!==$c||$a['pdo']!==$c->getPdo()||!$a['pdo']->inTransaction()
            ||!app('db.transactions')->getPendingTransactions()->contains(fn($r)=>$r===$a['root']))
            throw new \RuntimeException('UOM_OWNER_SCOPE_MISMATCH');
        return true;
    }

    public static function eligible(int $company,int $document,string $purpose):bool
    {
        if(!config('uom.operational_enabled')||!DB::getSchemaBuilder()->hasTable('uom_operational_schema'))return false;
        if($purpose==='PROCESS_CREATE')return DB::getSchemaBuilder()->hasTable('uom_processing_recipes')&&DB::table('uom_processing_recipes')->where('company_id',$company)->where('id',$document)->where('status','APPROVED')->exists();
        if($purpose==='ADJUSTMENT_CREATE')return DB::getSchemaBuilder()->hasTable('uom_inventory_adjustments')&&DB::table('items')->where('company_id',$company)->where('id',$document)->where('inventory_quantity_version','BASE_V1')->exists();
        if(in_array($purpose,['PROCESS_POST','PROCESS_VOID'],true))return DB::getSchemaBuilder()->hasTable('uom_processing_commands')&&DB::table('uom_processing_commands')->where('company_id',$company)->where('operation_id',$document)->exists();
        $definition=match($purpose){
            'PURCHASE_POST'=>['purchase_invoice_lines','purchase_invoice_id'],
            'SALE_POST','SALE_VOID'=>['sales_invoice_lines','sales_invoice_id'],
            'RETURN_POST','RETURN_VOID'=>['commercial_return_lines','return_id'],
            'OPENING_POST'=>DB::getSchemaBuilder()->hasColumn('opening_inventory_lines','uom_version')?['opening_inventory_lines','batch_id']:null,
            'PROCESS_POST','PROCESS_VOID'=>DB::getSchemaBuilder()->hasTable('uom_processing_commands')?['inventory_operation_lines','operation_id']:null,
            'ADJUSTMENT_VOID'=>DB::getSchemaBuilder()->hasTable('uom_inventory_adjustments')?['uom_inventory_adjustments','id']:null,
            'ORDER_CONVERT'=>DB::getSchemaBuilder()->hasColumn('purchase_order_lines','uom_version')?['purchase_order_lines','purchase_order_id']:null,
            'QUOTE_CONVERT'=>DB::getSchemaBuilder()->hasColumn('sales_quotation_lines','uom_version')?['sales_quotation_lines','sales_quotation_id']:null,
            default=>null,
        };
        if(!$definition)return false;
        // Linked invoices keep their existing Batch2/L3 owner and lock protocol.
        if(in_array($purpose,['PURCHASE_POST','SALE_POST','SALE_VOID'],true)
            && DB::table('invoice_shipment_links')->where('company_id',$company)->where('invoice_id',$document)
                ->where('invoice_type',$purpose==='PURCHASE_POST'?'PURCHASE':'SALE')->exists())return false;
        return DB::table($definition[0])->where('company_id',$company)->where($definition[1],$document)->where('uom_version','BASE_V1')->exists();
    }

    public function run(int $company,int $document,int $user,string $purpose,callable $operation):mixed
    {
        $c=DB::connection();$pdo=$c->getPdo();
        if($this->active!==null||$c->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('UOM_OUTERMOST_REQUIRED');
        if(!self::eligible($company,$document,$purpose))throw new \RuntimeException('UOM_OWNER_SOURCE_REQUIRED');
        try{
            return $c->transaction(function()use($c,$pdo,$company,$document,$user,$purpose,$operation){
                $root=app('db.transactions')->getPendingTransactions()->first(fn($r)=>$r->connection===$c->getName()&&$r->level===1)
                    ??throw new \RuntimeException('UOM_OWNER_ROOT_REQUIRED');
                $this->active=compact('company','document','user','purpose','root','pdo')+['connection'=>$c,'failure'=>null];
                $result=$operation();$this->throwIfRollbackOnly();
                $this->owns($company,$document,$user,$purpose);
                if($c->transactionLevel()!==1)throw new \RuntimeException('UOM_OWNER_ROOT_LEAK');
                return $result;
            });
        }catch(\Throwable $failure){
            try{
                if($c->getPdo()!==$pdo)throw new \RuntimeException('UOM owner PDO replaced');
                if($c->transactionLevel()>0)$c->rollBack(0);
                if($pdo->inTransaction())$pdo->rollBack();
                if($c->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('UOM rollback incomplete');
            }catch(\Throwable $rollback){throw new \RuntimeException('UOM_ROLLBACK_UNCONFIRMED',0,$rollback);}
            if($failure instanceof ShipmentPostingFailure)throw new ShipmentPostingFailure($failure->getPrevious()??$failure,true);
            throw $failure;
        }finally{$this->active=null;}
    }

    public function markRollbackOnly(\Throwable $cause):ShipmentPostingFailure
    {
        if($this->active===null)throw new \LogicException('No UOM root owner');
        return $this->active['failure']??=new ShipmentPostingFailure($cause);
    }
    public function throwIfRollbackOnly():void
    {if($this->active!==null&&$this->active['failure']!==null)throw $this->active['failure'];}
    public function assertResponseSuccessful(int $status):void
    {
        if($this->active!==null&&$status>=400)throw $this->markRollbackOnly(new \RuntimeException('UOM_SUPPORT_OPERATION_FAILED'));
        $this->throwIfRollbackOnly();
    }
}
