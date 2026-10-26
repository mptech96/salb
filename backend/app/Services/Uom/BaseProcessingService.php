<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\InventoryPostingLocks;
use App\Services\InventoryLotService;
use App\Services\Accounting\ItemAccountingResolver;
use App\Domain\Accounting\Services\JournalService;

/** Recipe quantities and posted FIFO values are the only stock/cost authorities. */
final class BaseProcessingService
{
    private function stock(object $lot,string $direction,array $source,string $type,int $id,int $user,string $date):void
    {
        if(OperationalDocument::isBase($lot))BaseInventory::stock($lot,$lot->company_id,$lot->branch_id,$direction,$source['base_quantity'],$source['total_cost'],$type,$id,$user,$date,(int)$lot->id);
        else LegacyRecipeInventory::stock($lot,$direction,$source['qty_kg'],$source['total_cost'],$type,$id,$user,$date);
    }
    private function linkColumns(object $lot,array $source):array
    {
        return OperationalDocument::isBase($lot)?['qty_kg'=>0,'unit_cost_per_kg'=>0,...OperationalDocument::columns($lot,$source['base_quantity'])]:['uom_version'=>'LEGACY_KG','qty_kg'=>$source['qty_kg'],'unit_cost_per_kg'=>$lot->unit_cost_per_kg];
    }
    private function result(object $command):array
    {
        if(!$command->result_json||!$command->result_hash||!hash_equals($command->result_hash,hash('sha256',$command->result_json)))throw new \RuntimeException('UOM_PROCESS_RESULT_EVIDENCE');
        return json_decode($command->result_json,true,512,JSON_THROW_ON_ERROR);
    }
    public function create(int $company,int $branch,int $user,array $input):int
    {
        $owner=app(OperationalTransactionOwner::class);$recipeId=(int)($input['recipe_id']??0);
        if(!$owner->owns($company,$recipeId,$user,'PROCESS_CREATE')){
            if(DB::transactionLevel()>0||DB::connection()->getPdo()->inTransaction())throw new \RuntimeException('UOM_PROCESS_CREATE_ROOT_REQUIRED');
            if(!OperationalTransactionOwner::eligible($company,$recipeId,'PROCESS_CREATE'))throw new \RuntimeException('UOM_APPROVED_RECIPE_REQUIRED: يلزم وصفة معتمدة ضمن الشركة والفرع.');
            return $owner->run($company,$recipeId,$user,'PROCESS_CREATE',fn()=>$this->create($company,$branch,$user,$input));
        }
        if(!DB::getSchemaBuilder()->hasTable('uom_processing_commands'))throw new \RuntimeException('UOM_PROCESS_SCHEMA_REQUIRED');
        if(!empty($input['from_lines'])||!empty($input['to_lines']))throw new \RuntimeException('UOM_PROCESS_RECIPE_LINES_OWNED: تحدد الوصفة المدخلات والمخرجات؛ لا تتجاهل تكاليف تشغيل إضافية.');
        $key=(string)($input['request_key']??'');$date=(string)($input['operation_date']??'');$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!preg_match('/^[A-Za-z0-9-]{8,80}$/D',$key)||!$parsed||$parsed->format('Y-m-d')!==$date)throw new \RuntimeException('UOM_PROCESS_REQUEST');
        if(!DB::table('branches')->where('company_id',$company)->where('id',$branch)->where('is_active',1)->exists())throw new \RuntimeException('UOM_PROCESS_BRANCH');
        try{return DB::transaction(function()use($company,$branch,$user,$input,$key,$date){
            OperationalDocument::ready();$recipe=app(ProcessingRecipeService::class)->requiredLines($company,$branch,(int)($input['recipe_id']??0),(string)($input['batch_multiplier']??''));
            if(isset($input['operation_type'])&&$input['operation_type']!==$recipe['operation_type'])throw new \RuntimeException('UOM_PROCESS_RECIPE_TYPE');
            $rows=[];foreach($recipe['to'] as $line){$code=DB::table('items')->where('company_id',$company)->where('id',$line['item_id'])->value('item_code');$percent=(string)($input['output_percentages'][$line['item_id']]??(count($recipe['to'])===1?'100':''));$rows[$line['item_id']]=['item_code'=>$code,'cost_share_percent'=>(new \App\Services\ShipmentLargestRemainder)->decimal($percent,6)];}
            // Validate even a zero-cost run: missing percentages must not later
            // become an implicit quantity/weight allocation.
            if($rows)(new ShipmentCostBasis)->plan('0','MANUAL_PERCENT',$rows,true);
            elseif($recipe['operation_type']!=='SCRAP')throw new \RuntimeException('UOM_RECIPE_LINES_REQUIRED');
            $costs=ProcessingExtraCost::capture($company,$branch,$input['costs']??[]);
            $payload=['date'=>$date,'branch'=>$branch,'recipe'=>$recipe,'outputs'=>$rows];if($costs)$payload['costs']=$costs;$json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$json);
            $percentages=array_map(fn($row)=>$row['cost_share_percent'],$rows);ksort($percentages);
            $requestCosts=array_map(function($cost){unset($cost['base_currency_code'],$cost['foreign_credit']);return $cost;},$costs);
            $requestHash=hash('sha256',json_encode([$date,$branch,$recipe['recipe_id'],$recipe['recipe_hash'],$recipe['multiplier'],...($requestCosts?[$percentages,$requestCosts]:[$percentages])],JSON_THROW_ON_ERROR));
            $old=DB::table('uom_processing_commands')->where('company_id',$company)->where('request_key',$key)->lockForUpdate()->first();
            if($old){if(!hash_equals($old->request_hash,$requestHash))throw new \RuntimeException('UOM_PROCESS_REQUEST_CONFLICT');return (int)$old->operation_id;}
            $number=app(\App\Services\SulbDocumentSequenceService::class)->next($company,$branch,'INVENTORY_OPERATION',$date,'IO');
            $id=DB::table('inventory_operations')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'from_branch_id'=>$branch,'to_branch_id'=>$branch,'operation_number'=>$number,'operation_type'=>$recipe['operation_type'],'allocation_method'=>'MANUAL_PERCENT','operation_date'=>$date,'loss_gain_reason'=>$recipe['yield_notes'],'status'=>'DRAFT','created_by'=>$user,'created_at'=>now(),'updated_at'=>now()]);
            foreach(['from'=>'FROM','to'=>'TO'] as $side=>$direction)foreach($recipe[$side] as $line){$columns=$line;unset($columns['uom_price_snapshot']);DB::table('inventory_operation_lines')->insert(['company_id'=>$company,'branch_id'=>$branch,'operation_id'=>$id,'line_type'=>$direction,...$columns,'qty'=>$line['qty_kg']??0,'qty_kg'=>$line['qty_kg']??0,'unit_cost'=>0,'unit_cost_per_kg'=>0,'total_cost'=>0,'created_at'=>now(),'updated_at'=>now()]);}
            ProcessingExtraCost::save($company,$branch,$id,$user,$costs);
            DB::table('uom_processing_commands')->insert(['company_id'=>$company,'branch_id'=>$branch,'operation_id'=>$id,'recipe_id'=>$recipe['recipe_id'],'request_key'=>$key,'request_hash'=>$requestHash,'payload'=>$json,'payload_hash'=>$hash,'created_by'=>$user,'created_at'=>now()]);return $id;
        });}catch(\Throwable $e){throw $owner->markRollbackOnly($e);}
    }
    public function void(int $company,int $branch,int $id,int $user,string $reason):array
    {
        if(mb_strlen(trim($reason))<5)throw new \RuntimeException('UOM_PROCESS_VOID_REASON');
        $owner=app(OperationalTransactionOwner::class);$owned=$owner->owns($company,$id,$user,'PROCESS_VOID');
        if(!$owned){if(DB::transactionLevel()>0||DB::connection()->getPdo()->inTransaction())throw new \RuntimeException('UOM_PROCESS_TRUSTED_ROOT_REQUIRED');return $owner->run($company,$id,$user,'PROCESS_VOID',fn()=>$this->void($company,$branch,$id,$user,$reason));}
        try{return DB::transaction(function()use($company,$branch,$id,$user,$reason){
            $operation=DB::table('inventory_operations')->where('company_id',$company)->where('from_branch_id',$branch)->where('id',$id)->lockForUpdate()->first();
            $command=DB::table('uom_processing_commands')->where('company_id',$company)->where('branch_id',$branch)->where('operation_id',$id)->lockForUpdate()->first();
            if(!$operation||!$command)throw new \RuntimeException('UOM_PROCESS_COMMAND_SCOPE');
            if($command->status==='VOIDED')return ['operation_id'=>$id,'reversal_journal_entry_id'=>$command->reversal_journal_entry_id];
            if($command->status!=='POSTED'||$operation->status!=='POSTED')throw new \RuntimeException('UOM_PROCESS_STATUS');
            $result=$this->result($command);$payload=json_decode($command->payload,true,512,JSON_THROW_ON_ERROR);ProcessingExtraCost::audit($company,$id,$payload['costs']??[],$result);ProcessingExtraCost::verify($company,$branch,$id,$payload['costs']??[],true);$lotIds=array_merge(array_column($result['inputs'],'inventory_lot_id'),array_column($result['outputs'],'inventory_lot_id'));
            $lots=DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->whereIn('id',$lotIds)->get();
            InventoryPostingLocks::lockItems($company,$lots->pluck('item_id')->all());
            $locked=DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->whereIn('id',$lotIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach($result['outputs'] as $output){$lot=$locked->get($output['inventory_lot_id']);if(!$lot)throw new \RuntimeException('UOM_PROCESS_LOT_SCOPE');
                if(OperationalDocument::isBase($lot)){$quantity=$lot->remaining_base_qty;$expected=$output['base_quantity'];$value=$lot->uom_remaining_value;}
                else{$quantity=$lot->qty_remaining_kg;$expected=$output['qty_kg'];$value=DB::table('inventory_lot_value_balances')->where('company_id',$company)->where('inventory_lot_id',$lot->id)->value('remaining_value');}
                if($value===null||bccomp($quantity,$expected,6)||bccomp($value,$output['total_cost'],3))throw new \RuntimeException('UOM_PROCESS_OUTPUT_ALREADY_ISSUED');
            }
            foreach(['outputs'=>'OUT','inputs'=>'IN'] as $side=>$direction)foreach($result[$side] as $source){$lot=$locked->get($source['inventory_lot_id']);if(!$lot)throw new \RuntimeException('UOM_PROCESS_LOT_SCOPE');
                if(OperationalDocument::isBase($lot)){
                    if($direction==='OUT')BaseInventory::remove($lot,$source['base_quantity'],$source['total_cost'],false);else BaseInventory::restore($lot,$source['base_quantity'],$source['total_cost'],false);
                    BaseInventory::movement($lot,$direction,$source['base_quantity'],$source['total_cost'],'UOM_PROCESS_REVERSAL',$id,$user,date('Y-m-d'));
                }else{LegacyRecipeInventory::reverse($lot,$direction,$source['qty_kg'],$source['total_cost']);LegacyRecipeInventory::movement($lot,$direction,$source['qty_kg'],$source['total_cost'],'UOM_PROCESS_REVERSAL',$id,$user,date('Y-m-d'));}
                $this->stock($lot,$direction,$source,'UOM_PROCESS_REVERSAL',$id,$user,date('Y-m-d'));
                $locked[$lot->id]=DB::table('inventory_lots')->where('company_id',$company)->where('id',$lot->id)->first();
            }
            $journal=$operation->journal_entry_id?app(JournalService::class)->reverse($company,(int)$operation->journal_entry_id,['reason'=>$reason,'entry_date'=>date('Y-m-d'),'source_type'=>'UOM_PROCESS_REVERSAL','created_by'=>$user]):null;
            DB::table('stock_movements')->where('company_id',$company)->where('source_type','UOM_PROCESS_REVERSAL')->where('source_id',$id)->update(['journal_entry_id'=>$journal]);
            DB::table('inventory_operations')->where('id',$id)->update(['status'=>'CANCELLED','updated_at'=>now()]);DB::table('uom_processing_commands')->where('id',$command->id)->update(['status'=>'VOIDED','reversal_journal_entry_id'=>$journal,'void_reason'=>$reason]);return ['operation_id'=>$id,'reversal_journal_entry_id'=>$journal];
        });}catch(\Throwable $e){throw $owner->markRollbackOnly($e);}
    }
    public function post(int $company,int $branch,int $id,int $user):array
    {
        $owner=app(OperationalTransactionOwner::class);$owned=$owner->owns($company,$id,$user,'PROCESS_POST');
        if(!$owned){if(DB::transactionLevel()>0||DB::connection()->getPdo()->inTransaction())throw new \RuntimeException('UOM_PROCESS_TRUSTED_ROOT_REQUIRED');return $owner->run($company,$id,$user,'PROCESS_POST',fn()=>$this->post($company,$branch,$id,$user));}
        try{return DB::transaction(function()use($company,$branch,$id,$user){
            OperationalDocument::ready();$operation=DB::table('inventory_operations')->where('company_id',$company)->where('from_branch_id',$branch)->where('id',$id)->lockForUpdate()->first();
            $command=DB::table('uom_processing_commands')->where('company_id',$company)->where('branch_id',$branch)->where('operation_id',$id)->lockForUpdate()->first();
            if(!$operation||!$command||!hash_equals($command->payload_hash,hash('sha256',$command->payload)))throw new \RuntimeException('UOM_PROCESS_COMMAND_SCOPE');
            if($command->status==='POSTED')return $this->result($command);if($command->status!=='DRAFT'||$operation->status!=='DRAFT')throw new \RuntimeException('UOM_PROCESS_STATUS');
            $p=json_decode($command->payload,true,512,JSON_THROW_ON_ERROR);$recipe=$p['recipe'];
            if(DB::table('inventory_operation_costs')->where('company_id',$company)->where('operation_id',$id)->exists()&&empty($p['costs']))throw new \RuntimeException('UOM_PROCESS_COST_SOURCE_REQUIRED');
            $overhead=ProcessingExtraCost::verify($company,$branch,$id,$p['costs']??[]);
            ProcessingExtraCost::claim($company,$id,$p['costs']??[]);
            InventoryPostingLocks::reserveLotScope($company,$operation->operation_date);InventoryPostingLocks::lockItems($company,array_merge(array_column($recipe['from'],'item_id'),array_column($recipe['to'],'item_id')));
            $inventoryAccounts=[];foreach(array_merge($recipe['from'],$recipe['to']) as $line)$inventoryAccounts[]=app(ItemAccountingResolver::class)->inventory($company,$line['item_id']);
            foreach($overhead['credits'] as $credit)if(in_array($credit['account_id'],$inventoryAccounts,true))throw new \RuntimeException('UOM_PROCESS_COST_CREDIT_CONFLICT');
            $inputs=[];$outputs=[];$total='0.000';$credits=[];$debits=[];$lines=DB::table('inventory_operation_lines')->where('company_id',$company)->where('operation_id',$id)->orderBy('id')->lockForUpdate()->get();
            foreach(['from'=>'FROM','to'=>'TO'] as $side=>$direction)foreach($recipe[$side] as $expected){$actual=$lines->first(fn($l)=>$l->line_type===$direction&&(int)$l->item_id===$expected['item_id']);
                if(!$actual)throw new \RuntimeException('UOM_PROCESS_LINE_CHANGED');foreach(['uom_version','quantity','unit_code','base_quantity','uom_factor_snapshot','uom_mapping_id','base_uom_id_snapshot'] as $field)if((string)$actual->$field!==(string)$expected[$field])throw new \RuntimeException('UOM_PROCESS_LINE_CHANGED');
                if(($expected['uom_version']??'')==='LEGACY_KG'&&bccomp($actual->qty_kg,$expected['qty_kg'],3))throw new \RuntimeException('UOM_PROCESS_LINE_CHANGED');
            }
            if($lines->count()!==count($recipe['from'])+count($recipe['to']))throw new \RuntimeException('UOM_PROCESS_LINE_CHANGED');
            foreach($recipe['from'] as $line){$consumed=OperationalDocument::isBase((object)$line)?BaseInventory::consume($company,$branch,$line['item_id'],$line['base_quantity'],'INVENTORY_OPERATION',$id,$user):LegacyRecipeInventory::consume($company,$branch,$line['item_id'],$line['qty_kg'],$id,$user,$operation->operation_date);$total=bcadd($total,$consumed['total_cost'],3);$account=app(ItemAccountingResolver::class)->inventory($company,$line['item_id']);$credits[$account]=bcadd($credits[$account]??'0',$consumed['total_cost'],3);
                $operationLine=$lines->first(fn($l)=>$l->line_type==='FROM'&&(int)$l->item_id===$line['item_id']);DB::table('inventory_operation_lines')->where('id',$operationLine->id)->update(['total_cost'=>$consumed['total_cost']]);
                foreach($consumed['allocations'] as $source){$lot=DB::table('inventory_lots')->where('company_id',$company)->where('id',$source['inventory_lot_id'])->first();$this->stock($lot,'OUT',$source,'INVENTORY_OPERATION',$id,$user,$operation->operation_date);$inputs[]=$source;
                    DB::table('inventory_operation_lot_links')->insert(['company_id'=>$company,'operation_id'=>$id,'operation_line_id'=>$operationLine->id,'direction'=>'FROM','source_lot_id'=>$lot->id,'item_id'=>$line['item_id'],'branch_id'=>$branch,'total_cost'=>$source['total_cost'],...$this->linkColumns($lot,$source),'created_at'=>now(),'updated_at'=>now()]);
                }
            }
            $inputCost=$total;$total=bcadd($total,$overhead['amount'],3);
            $shares=$p['outputs']?(new ShipmentCostBasis)->plan($total,'MANUAL_PERCENT',$p['outputs'],true)['allocations']:[];
            if(!$recipe['to']&&$recipe['operation_type']==='SCRAP'&&bccomp($total,'0',3)>0){
                $expense=app(\App\Services\Accounting\PostingSupport::class)->setting($company,'INVENTORY_ADJUSTMENT_ACCOUNT');
                if(array_key_exists($expense,$credits)||DB::table('accounts')->where('company_id',$company)->where('id',$expense)->value('account_type')!=='EXPENSE')throw new \RuntimeException('UOM_SCRAP_EXPENSE_ACCOUNT_REQUIRED');
                $debits[$expense]=$total;
            }
            foreach($recipe['to'] as $line){$value=$shares[$line['item_id']]['amount'];$data=['company_id'=>$company,'branch_id'=>$branch,...$line,'base_cost'=>$value,'source_type'=>'INVENTORY_OPERATION','source_id'=>$id,'received_at'=>$operation->operation_date.' 00:00:00','created_by'=>$user];$lot=OperationalDocument::isBase((object)$line)?app(InventoryLotService::class)->createInboundLot($data):LegacyRecipeInventory::receive($data);$account=app(ItemAccountingResolver::class)->inventory($company,$line['item_id']);$debits[$account]=bcadd($debits[$account]??'0',$value,3);
                $savedLot=DB::table('inventory_lots')->where('company_id',$company)->where('id',$lot)->first();$output=['inventory_lot_id'=>$lot,'item_id'=>$line['item_id'],'uom_version'=>$line['uom_version'],'base_quantity'=>$line['base_quantity'],'qty_kg'=>$line['qty_kg']??'0.000','total_cost'=>$value];$this->stock($savedLot,'IN',$output,'INVENTORY_OPERATION',$id,$user,$operation->operation_date);$outputs[]=$output;
                DB::table('inventory_lots')->where('id',$lot)->update(['inventory_operation_id'=>$id]);$operationLine=$lines->first(fn($l)=>$l->line_type==='TO'&&(int)$l->item_id===$line['item_id']);DB::table('inventory_operation_lines')->where('id',$operationLine->id)->update(['total_cost'=>$value]);
                DB::table('inventory_operation_lot_links')->insert(['company_id'=>$company,'operation_id'=>$id,'operation_line_id'=>$operationLine->id,'direction'=>'TO','produced_lot_id'=>$lot,'item_id'=>$line['item_id'],'branch_id'=>$branch,'total_cost'=>$value,...$this->linkColumns($savedLot,$output),'created_at'=>now(),'updated_at'=>now()]);
            }
            $journal=null;if(bccomp($total,'0',3)>0){$j=$overhead['credits'];foreach($debits as $account=>$value)if(bccomp($value,'0',3)>0)$j[]=['account_id'=>$account,'debit'=>$value,'credit'=>0];foreach($credits as $account=>$value)if(bccomp($value,'0',3)>0)$j[]=['account_id'=>$account,'debit'=>0,'credit'=>$value];
                $journal=app(JournalService::class)->post(['company_id'=>$company,'branch_id'=>$branch,'entry_date'=>$operation->operation_date,'source_type'=>'UOM_INVENTORY_PROCESS','source_id'=>$id,'description'=>$recipe['yield_notes'],'lines'=>$j,'is_system_generated'=>1,'created_by'=>$user]);
                $actual=DB::table('journal_entry_lines')->where('company_id',$company)->where('journal_entry_id',$journal)->selectRaw('SUM(debit) d,SUM(credit) c')->first();if(bccomp($actual->d,$total,3)||bccomp($actual->c,$total,3))throw new \RuntimeException('UOM_PROCESS_GL_PRECISION');
                $expected=[];foreach($j as $entry){$account=$entry['account_id'];$expected[$account]=bcadd($expected[$account]??'0',bcsub((string)$entry['debit'],(string)$entry['credit'],3),3);}foreach($expected as $account=>$value){$persisted=DB::table('journal_entry_lines')->where('company_id',$company)->where('journal_entry_id',$journal)->where('account_id',$account)->selectRaw('SUM(debit-credit) v')->first()->v;if(bccomp($persisted,$value,3))throw new \RuntimeException('UOM_PROCESS_GL_ACCOUNT_PRECISION');}
            }
            DB::table('stock_movements')->where('company_id',$company)->where('source_type','INVENTORY_OPERATION')->where('source_id',$id)->update(['journal_entry_id'=>$journal]);
            ProcessingExtraCost::posted($company,$id,$user,$journal);
            $result=['message'=>'تم ترحيل المعالجة وفق الوصفة المعتمدة وبتكلفة FIFO الأصلية.','operation_id'=>$id,'journal_entry_id'=>$journal,'input_inventory_cost'=>$inputCost,'operation_overhead_cost'=>$overhead['amount'],'total_output_cost'=>$total,'cost_allocation'=>$shares,'inputs'=>$inputs,'outputs'=>$outputs,'recipe_hash'=>$recipe['recipe_hash']];
            $resultJson=json_encode($result,JSON_THROW_ON_ERROR);
            DB::table('inventory_operations')->where('id',$id)->update(['status'=>'POSTED','journal_entry_id'=>$journal,'posted_by'=>$user,'posted_at'=>now(),'approved_by'=>$user,'approved_at'=>now(),'updated_at'=>now()]);DB::table('uom_processing_commands')->where('id',$command->id)->update(['status'=>'POSTED','result_json'=>$resultJson,'result_hash'=>hash('sha256',$resultJson)]);return $result;
        });}catch(\Throwable $e){throw $owner->markRollbackOnly($e);}
    }
}
