<?php
namespace App\Services;

use App\Services\Accounting\ItemAccountingResolver;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/** One same-root calculation, frozen as an immutable receipt for GL and LOT. */
#[Scoped]
final class ShipmentAllocationLedger
{
    private array $plans=[];

    private function root(): object
    {
        $c=DB::connection();
        if($c->transactionLevel()<1||!$c->getPdo()->inTransaction())throw new \RuntimeException('ALLOCATION_ROOT_REQUIRED');
        return app('db.transactions')->getPendingTransactions()->first(fn($r)=>$r->connection===$c->getName()&&$r->level===1)
            ??throw new \RuntimeException('ALLOCATION_ROOT_REQUIRED');
    }

    public function prepare(int $company,int $shipment,$invoiceLines): void
    {
        if(!config('shipment_cost_read.enabled'))throw new \RuntimeException('ALLOCATION_FRESH_READ_REQUIRED');
        if(!DB::getSchemaBuilder()->hasTable('shipment_cost_allocation_receipts'))throw new \RuntimeException('ALLOCATION_SCHEMA_REQUIRED');
        app(ShipmentInventoryLockScope::class)->assertPrepared($company,$shipment);
        $root=$this->root(); $pdo=DB::connection()->getPdo();
        if(isset($this->plans[$company.':'.$shipment])&&$this->plans[$company.':'.$shipment]['root']===$root)throw new \RuntimeException('ALLOCATION_LATE_REPLAN_FORBIDDEN');
        $header=DB::table('shipments')->where('company_id',$company)->where('id',$shipment)->lockForUpdate()->first();
        $rows=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$shipment)->orderBy('id')->lockForUpdate()->get();
        $method=strtoupper((string)($header->cost_allocation_method??'WEIGHT'));
        $mixed=$rows->contains(fn($row)=>\App\Services\Uom\OperationalDocument::isBase($row));
        if($mixed&&(($header->uom_cost_policy??'')!=='INDEPENDENT_INVENTORY_V1'||!in_array($method,['RELATIVE_VALUE','MANUAL_PERCENT'],true)||($method==='MANUAL_PERCENT'&&!$header->uom_cost_basis_approved_by)))throw new \RuntimeException('UOM_SHIPMENT_COST_POLICY_REQUIRED');
        $costs=app(ShipmentPurchaseCostRead::class)->captured($company,$shipment);
        $sources=[];
        foreach($invoiceLines as $line)if((int)$line->shipment_id===$shipment){
            $id=(int)$line->shipment_item_id;
            if(!$id||isset($sources[$id]))throw new \RuntimeException('ALLOCATION_SOURCE_LINE_AMBIGUOUS');
            $sources[$id]=$line;
        }
        $beneficiaries=[]; $basisTotal='0'; $lr=new ShipmentLargestRemainder;
        foreach($rows as $row){
            $base=\App\Services\Uom\OperationalDocument::isBase($row);$quantity=$base?$row->base_quantity:$row->accepted_qty_kg;
            if(bccomp((string)$quantity,'0',6)<=0)continue;
            $line=$sources[(int)$row->id]??throw new \RuntimeException('ALLOCATION_SOURCE_LINE_MISSING');
            if((int)$line->item_id!==(int)$row->item_id||bccomp((string)($base?$line->base_quantity:$line->qty_kg),(string)$quantity,6)!==0||($base&&(!\App\Services\Uom\OperationalDocument::isBase($line)||(int)$line->uom_mapping_id!==(int)$row->uom_mapping_id||(int)$line->base_uom_id_snapshot!==(int)$row->base_uom_id_snapshot)))throw new \RuntimeException('ALLOCATION_SOURCE_LINE_CHANGED');
            $item=DB::table('items')->where('company_id',$company)->where('id',$row->item_id)->lockForUpdate()->first();
            $code=(string)($item->item_code??'');
            if(trim($code)===''||DB::table('items')->where('company_id',$company)->where('item_code',$code)->count()!==1)throw new \RuntimeException('ALLOCATION_UNIQUE_BUSINESS_CODE_REQUIRED');
            $basis=match($method){
                'RELATIVE_VALUE'=>(string)$line->base_total_before_vat,
                'MANUAL_PERCENT'=>(string)($row->cost_share_percent??0),
                'MANUAL_COST'=>(string)($row->manual_allocated_cost??0),
                default=>(string)$row->accepted_qty_kg,
            };
            if(bccomp($basis,'0',6)<0)$basis='0';
            $basis=$lr->decimal($basis,6); $basisTotal=bcadd($basisTotal,$basis,6);
            $beneficiaries[(int)$row->id]=['shipment_item_id'=>(int)$row->id,'allocation_line_ref'=>$row->allocation_line_ref??null,
                'item_id'=>(int)$row->item_id,'item_code'=>$code,'qty'=>(string)$quantity,'uom_version'=>$base?'BASE_V1':'LEGACY_KG',
                'cost_share_percent'=>(string)($row->cost_share_percent??0),'manual_allocated_cost'=>(string)($row->manual_allocated_cost??0),
                'base_cost'=>$lr->decimal((string)$line->base_total_before_vat),
                'basis'=>$basis,'account_id'=>app(ItemAccountingResolver::class)->inventory($company,(int)$row->item_id)];
        }
        if($mixed&&$method==='MANUAL_PERCENT'&&bccomp($basisTotal,'100',6)!==0)throw new \RuntimeException('UOM_SHIPMENT_PERCENT_SUM');
        if(!$mixed&&bccomp($basisTotal,'0',6)<=0){foreach($beneficiaries as &$row)$row['basis']=$lr->decimal($row['qty'],6);unset($row);}
        $plans=[];
        foreach($costs as $cost){
            if($cost['cost_status']!=='DRAFT'||!(int)$cost['capitalizable'])continue;
            $base=$lr->decimal((string)$cost['amount']);
            if(bccomp($base,'0',3)<=0)throw new \RuntimeException('ALLOCATION_POSITIVE_COST_REQUIRED');
            $shares=$lr->allocate($base,$beneficiaries);
            $foreign=$lr->allocate($lr->decimal((string)($cost['foreign_amount']??$cost['amount'])),$beneficiaries);
            foreach($shares as $id=>&$share)$share+=['foreign_amount'=>$foreign[$id]['amount']];unset($share);
            $plans[(int)$cost['id']]=['source'=>$cost,'shares'=>$shares];
        }
        $this->plans[$company.':'.$shipment]=['root'=>$root,'pdo'=>$pdo,'method'=>$method,'rows'=>$beneficiaries,'costs'=>$plans,'used'=>[]];
    }

    private function &plan(int $company,int $shipment): array
    {
        $key=$company.':'.$shipment;
        if(!isset($this->plans[$key])||$this->plans[$key]['root']!==$this->root()||$this->plans[$key]['pdo']!==DB::connection()->getPdo())throw new \RuntimeException('ALLOCATION_PLAN_NOT_OWNED');
        return $this->plans[$key];
    }

    public function debitLines(int $company,int $shipment,object $cost,string $currency,string $baseCurrency,float $rate): array
    {
        $plan=&$this->plan($company,$shipment);
        $method=strtoupper((string)(DB::table('shipments')->where('company_id',$company)->where('id',$shipment)->value('cost_allocation_method')??'WEIGHT'));
        if($method!==$plan['method'])throw new \RuntimeException('ALLOCATION_INPUTS_CHANGED');
        if(isset($plan['used'][(int)$cost->id])||!isset($plan['costs'][(int)$cost->id]))throw new \RuntimeException('ALLOCATION_PLAN_ALREADY_USED_OR_MISSING');
        $source=$plan['costs'][(int)$cost->id]['source'];
        foreach(['amount','foreign_amount','currency_code','exchange_rate','capitalizable','cost_status'] as $field)if((string)$source[$field] !== (string)$cost->$field)throw new \RuntimeException('ALLOCATION_INPUTS_CHANGED');
        $byAccount=[];
        foreach($plan['costs'][(int)$cost->id]['shares'] as $id=>$share){
            $row=$plan['rows'][$id];
            $current=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$shipment)->where('id',$id)->first();
            $base=($row['uom_version']??'LEGACY_KG')==='BASE_V1';
            if(!$current||bccomp((string)$current->base_cost,$row['base_cost'],3)!==0||bccomp((string)($base?$current->base_quantity:$current->accepted_qty_kg),$row['qty'],6)!==0)throw new \RuntimeException('ALLOCATION_INPUTS_CHANGED');
            foreach(['cost_share_percent','manual_allocated_cost'] as $field)if(bccomp((string)($current->$field??0),$row[$field],6)!==0)throw new \RuntimeException('ALLOCATION_INPUTS_CHANGED');
            $account=$row['account_id'];$byAccount[$account]??=['base'=>'0.000','foreign'=>'0.000'];
            $byAccount[$account]['base']=bcadd($byAccount[$account]['base'],$share['amount'],3);
            $byAccount[$account]['foreign']=bcadd($byAccount[$account]['foreign'],$share['foreign_amount'],3);
        }
        $lines=[];
        foreach($byAccount as $account=>$amount){
            // A zero-valued recipient remains in the receipt, not as an invalid GL line.
            if(bccomp($amount['base'],'0',3)===0&&bccomp($amount['foreign'],'0',3)===0)continue;
            $lines[]=['account_id'=>$account,'debit'=>$amount['base'],'credit'=>0,'description'=>'رسملة تكلفة الشحنة — LR 0.001',
                'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>$currency!==$baseCurrency?$amount['foreign']:0,'foreign_credit'=>0];
        }
        return $lines;
    }

    public function record(int $company,int $shipment,object $cost,int $journal,int $user): void
    {
        $plan=&$this->plan($company,$shipment);$costId=(int)$cost->id;
        if(isset($plan['used'][$costId])||!isset($plan['costs'][$costId]))throw new \RuntimeException('ALLOCATION_PLAN_ALREADY_USED_OR_MISSING');
        $entries=[];
        foreach($plan['costs'][$costId]['shares'] as $id=>$share){
            $row=$plan['rows'][$id];
            $lot=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$shipment)->where('id',$id)->value('inventory_lot_id');
            if(!$lot)throw new \RuntimeException('ALLOCATION_LOT_BINDING_REQUIRED');
            $entries[]=$row+['inventory_lot_id'=>(int)$lot]+$share;
        }
        $payload=json_encode(['method'=>$plan['method'],'recipients'=>$entries],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        DB::table('shipment_cost_allocation_receipts')->insert(['company_id'=>$company,'branch_id'=>$cost->branch_id,'shipment_id'=>$shipment,
            'shipment_cost_id'=>$costId,'journal_entry_id'=>$journal,'policy_version'=>'LR_MILLI_V1','currency_code'=>$cost->currency_code,
            'base_amount'=>$cost->amount,'foreign_amount'=>$cost->foreign_amount??$cost->amount,'payload'=>$payload,'payload_hash'=>hash('sha256',$payload),
            'posting_status'=>'POSTED','created_by'=>$user,'created_at'=>now()]);
        $plan['used'][$costId]=true;
    }

    /** Reads the persisted calculation; never reruns LR for LOT consumption. */
    public function sharesForDistribution(int $company,int $shipment,$costs): array
    {
        $shares=[];
        foreach($costs as $cost){
            $receipt=DB::table('shipment_cost_allocation_receipts')->where('company_id',$company)->where('shipment_id',$shipment)->where('shipment_cost_id',$cost->id)->first();
            if(!$receipt||$receipt->policy_version!=='LR_MILLI_V1'||(int)$receipt->journal_entry_id!==(int)$cost->journal_entry_id||!hash_equals($receipt->payload_hash,hash('sha256',$receipt->payload)))throw new \RuntimeException('ALLOCATION_RECEIPT_REQUIRED_OR_INVALID');
            $payload=json_decode($receipt->payload,true,512,JSON_THROW_ON_ERROR); $sum='0.000';$seen=[];
            foreach($payload['recipients'] as $row){
                $id=(int)$row['shipment_item_id'];
                if(isset($seen[$id]))throw new \RuntimeException('ALLOCATION_DUPLICATE_RECIPIENT');$seen[$id]=true;
                $current=DB::table('shipment_items')->where('company_id',$company)->where('shipment_id',$shipment)->where('id',$id)->first();
                if(!$current||(int)$current->inventory_lot_id!==(int)$row['inventory_lot_id']||(int)$current->item_id!==(int)$row['item_id'])throw new \RuntimeException('ALLOCATION_LOT_BINDING_CHANGED');
                $sum=bcadd($sum,$row['amount'],3);$shares[$id]=bcadd($shares[$id]??'0.000',$row['amount'],3);
            }
            if(bccomp($sum,(string)$cost->amount,3)!==0||bccomp($sum,(string)$receipt->base_amount,3)!==0)throw new \RuntimeException('ALLOCATION_RECEIPT_SUM_MISMATCH');
        }
        return $shares;
    }
}
