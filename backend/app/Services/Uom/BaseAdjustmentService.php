<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
use App\Domain\Accounting\Services\JournalService;
use App\Services\InventoryPostingLocks;

final class BaseAdjustmentService
{
    public function __construct(private JournalService $journals){}
    private function root(int $company,int $document,int $user,string $purpose,callable $operation):array
    {
        $owner=app(OperationalTransactionOwner::class);$owned=$owner->owns($company,$document,$user,$purpose);
        if(!$owned&&(DB::transactionLevel()>0||DB::connection()->getPdo()->inTransaction()))throw new \RuntimeException('UOM_TRUSTED_ADJUSTMENT_ROOT_REQUIRED');
        try{return DB::transaction($operation);}catch(\Throwable $failure){if($owned)throw $owner->markRollbackOnly($failure);throw $failure;}
    }
    public function post(int $company,int $branch,int $user,array $input):array
    {
        if(!DB::getSchemaBuilder()->hasTable('uom_inventory_adjustments'))throw new \RuntimeException('UOM_ADJUSTMENT_SCHEMA_REQUIRED');
        $item=(int)$input['item_id'];$key=(string)($input['request_key']??'');$direction=$input['movement_type']??'';$date=(string)($input['movement_date']??date('Y-m-d'));
        if(!preg_match('/^[A-Za-z0-9-]{8,80}$/D',$key)||!in_array($direction,['IN','OUT'],true))throw new \RuntimeException('UOM_ADJUSTMENT_REQUEST_REQUIRED');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new \RuntimeException('UOM_ADJUSTMENT_DATE');
        $qty=DecimalQuantity::decimal((string)($input['quantity']??''));$unit=strtoupper(trim((string)($input['unit_code']??'')));$cost=$direction==='IN'?(string)($input['total_cost']??''):'0';
        if(!preg_match('/^\d{1,15}(?:\.\d{1,3})?$/D',$cost)||($direction==='IN'&&bccomp($cost,'0',3)<=0))throw new \RuntimeException('UOM_ADJUSTMENT_MONEY_PRECISION');
        if($direction==='OUT'&&isset($input['total_cost']))throw new \RuntimeException('UOM_OUTBOUND_COST_SERVER_OWNED');
        $notes=trim((string)($input['notes']??''));if(mb_strlen($notes)<3)throw new \RuntimeException('UOM_ADJUSTMENT_REASON_REQUIRED');
        $hash=hash('sha256',json_encode([$branch,$item,$direction,$date,$qty,$unit,bcadd($cost,'0',3),$notes],JSON_THROW_ON_ERROR));
        return $this->root($company,$item,$user,'ADJUSTMENT_CREATE',function()use($company,$branch,$user,$input,$item,$key,$direction,$date,$qty,$unit,$cost,$notes,$hash){
            OperationalDocument::ready();
            if(!DB::table('branches')->where('company_id',$company)->where('id',$branch)->where('is_active',1)->exists())throw new \RuntimeException('UOM_ADJUSTMENT_BRANCH');
            if($direction==='IN')InventoryPostingLocks::reserveLotScope($company,$date);InventoryPostingLocks::lockItems($company,[$item]);
            $old=DB::table('uom_inventory_adjustments')->where('company_id',$company)->where('request_key',$key)->lockForUpdate()->first();
            if($old){if(!hash_equals($old->request_hash,$hash))throw new \RuntimeException('UOM_ADJUSTMENT_REQUEST_CONFLICT');return $this->result($old);}
            $row=DB::table('items')->where('company_id',$company)->where('id',$item)->where('is_active',1)->first();
            if(!$row||($row->inventory_quantity_version??'')!=='BASE_V1')throw new \RuntimeException('UOM_ADJUSTMENT_ITEM_SCOPE');
            $prepared=OperationalDocument::prepare($company,$row,array_replace($input,['quantity'=>$qty,'unit_code'=>$unit,'unit_price'=>'0']),false,true);
            $columns=$prepared['columns'];unset($columns['uom_price_snapshot']);
            $id=DB::table('uom_inventory_adjustments')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'item_id'=>$item,'request_key'=>$key,'request_hash'=>$hash,'movement_type'=>$direction,'movement_date'=>$date,'quantity'=>$qty,'unit_code'=>$unit,...$columns,'total_cost'=>$cost,'notes'=>$notes,'created_by'=>$user,'created_at'=>now(),'updated_at'=>now()]);
            $command=DB::table('uom_inventory_adjustments')->where('id',$id)->first();$sources=[];
            if($direction==='IN'){
                $lot=app(\App\Services\InventoryLotService::class)->createInboundLot(['company_id'=>$company,'branch_id'=>$branch,'item_id'=>$item,...OperationalDocument::columns($command,$command->base_quantity),'base_cost'=>$cost,'source_type'=>'UOM_ADJUSTMENT','source_id'=>$id,'received_at'=>$date.' 00:00:00','created_by'=>$user]);
                $sources[]=['inventory_lot_id'=>$lot,'base_quantity'=>$command->base_quantity,'total_cost'=>bcadd($cost,'0',3)];
            }else{$consumed=BaseInventory::consume($company,$branch,$item,$command->base_quantity,'UOM_ADJUSTMENT',$id,$user);$sources=$consumed['allocations'];$cost=$consumed['total_cost'];}
            foreach($sources as $source){$lot=DB::table('inventory_lots')->where('company_id',$company)->where('id',$source['inventory_lot_id'])->first();BaseInventory::stock($lot,$company,$branch,$direction,$source['base_quantity'],$source['total_cost'],'UOM_ADJUSTMENT',$id,$user,$date,(int)$lot->id);}
            $journal=null;
            if(bccomp($cost,'0',3)>0){$inv=app(\App\Services\Accounting\ItemAccountingResolver::class)->inventory($company,$item);$adjust=app(\App\Services\Accounting\PostingSupport::class)->setting($company,'INVENTORY_ADJUSTMENT_ACCOUNT');
                if($inv===$adjust)throw new \RuntimeException('UOM_ADJUSTMENT_ACCOUNT_CONFLICT');
                $journal=$this->journals->post(['company_id'=>$company,'branch_id'=>$branch,'entry_date'=>$date,'source_type'=>'UOM_INVENTORY_ADJUSTMENT','source_id'=>$id,'description'=>$notes,'is_system_generated'=>1,'created_by'=>$user,'lines'=>[
                    ['account_id'=>$inv,'debit'=>$direction==='IN'?$cost:0,'credit'=>$direction==='OUT'?$cost:0],['account_id'=>$adjust,'debit'=>$direction==='OUT'?$cost:0,'credit'=>$direction==='IN'?$cost:0]]]);}
            // The legacy journal writer converts money to float. Refuse any loss of
            // precision before this transaction can commit a different LOT value.
            if($journal){
                $posted=DB::table('journal_entry_lines')->where('company_id',$company)->where('journal_entry_id',$journal)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
                if(bccomp((string)$posted->d,$cost,3)!==0||bccomp((string)$posted->c,$cost,3)!==0)throw new \RuntimeException('UOM_ADJUSTMENT_GL_PRECISION');
            }
            DB::table('uom_inventory_adjustments')->where('id',$id)->update(['total_cost'=>$cost,'document_status'=>'POSTED','journal_entry_id'=>$journal,'lot_sources_json'=>json_encode($sources,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            DB::table('stock_movements')->where('company_id',$company)->where('source_type','UOM_ADJUSTMENT')->where('source_id',$id)->where('uom_version','BASE_V1')->update(['journal_entry_id'=>$journal]);
            return $this->result(DB::table('uom_inventory_adjustments')->where('id',$id)->first());
        });
    }
    private function result(object $row):array
    {
        if($row->document_status==='DRAFT')throw new \RuntimeException('UOM_ADJUSTMENT_INCOMPLETE');
        return ['adjustment_id'=>(int)$row->id,'document_status'=>$row->document_status,'journal_entry_id'=>$row->journal_entry_id,'reversal_journal_entry_id'=>$row->reversal_journal_entry_id,'base_quantity'=>$row->base_quantity,'total_cost'=>$row->total_cost];
    }
    public function void(int $company,int $branch,int $id,int $user,string $reason):array
    {
        if(mb_strlen(trim($reason))<5)throw new \RuntimeException('UOM_ADJUSTMENT_VOID_REASON');
        return $this->root($company,$id,$user,'ADJUSTMENT_VOID',function()use($company,$branch,$id,$user,$reason){
            OperationalDocument::ready();$command=DB::table('uom_inventory_adjustments')->where('company_id',$company)->where('branch_id',$branch)->where('id',$id)->lockForUpdate()->first();
            if(!$command)throw new \RuntimeException('UOM_ADJUSTMENT_SCOPE');if($command->document_status==='VOIDED')return $this->result($command);if($command->document_status!=='POSTED')throw new \RuntimeException('UOM_ADJUSTMENT_NOT_POSTED');
            InventoryPostingLocks::lockItems($company,[$command->item_id]);$sources=json_decode($command->lot_sources_json,true,512,JSON_THROW_ON_ERROR);
            foreach($sources as $source){
                $lot=DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->where('item_id',$command->item_id)->where('id',$source['inventory_lot_id'])->lockForUpdate()->first();if(!$lot||!OperationalDocument::isBase($lot))throw new \RuntimeException('UOM_ADJUSTMENT_LOT_SCOPE');
                if($command->movement_type==='IN'){
                    if(bccomp($lot->remaining_base_qty,$source['base_quantity'],6)!==0||bccomp($lot->uom_remaining_value,$source['total_cost'],3)!==0)throw new \RuntimeException('UOM_ADJUSTMENT_ALREADY_ISSUED');
                    BaseInventory::remove($lot,$source['base_quantity'],$source['total_cost'],false);$direction='OUT';
                }else{BaseInventory::restore($lot,$source['base_quantity'],$source['total_cost'],false);$direction='IN';}
                BaseInventory::movement($lot,$direction,$source['base_quantity'],$source['total_cost'],'UOM_ADJUSTMENT_REVERSAL',$id,$user,date('Y-m-d'));
                BaseInventory::stock($lot,$company,$branch,$direction,$source['base_quantity'],$source['total_cost'],'UOM_ADJUSTMENT_REVERSAL',$id,$user,date('Y-m-d'),(int)$lot->id);
            }
            $reversal=$command->journal_entry_id?$this->journals->reverse($company,(int)$command->journal_entry_id,['reason'=>$reason,'entry_date'=>date('Y-m-d'),'source_type'=>'UOM_INVENTORY_ADJUSTMENT_REVERSAL','created_by'=>$user]):null;
            DB::table('stock_movements')->where('company_id',$company)->where('source_type','UOM_ADJUSTMENT_REVERSAL')->where('source_id',$id)->where('uom_version','BASE_V1')->update(['journal_entry_id'=>$reversal]);
            DB::table('uom_inventory_adjustments')->where('id',$id)->update(['document_status'=>'VOIDED','reversal_journal_entry_id'=>$reversal,'void_reason'=>$reason,'updated_at'=>now()]);return $this->result(DB::table('uom_inventory_adjustments')->where('id',$id)->first());
        });
    }
}
