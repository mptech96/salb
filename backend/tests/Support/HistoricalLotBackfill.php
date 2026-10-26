<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;

/** Offline QA rehearsal only, deliberately not registered as a production command. */
final class HistoricalLotBackfill
{
    public static function install():void
    {
        UomStageAProof::guard();
        if(!DB::getSchemaBuilder()->hasTable('inventory_lot_value_balances'))(require base_path('../docs/erp-baseline/proposed-migrations/inventory_lot_value_balances.php'))->up();
        foreach(['original_quantity','remaining_quantity','original_value','remaining_value'] as $column){
            $type=DB::selectOne('SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['inventory_lot_value_balances',$column]);
            if(!$type||$type->t!=='decimal(18,3)')throw new \RuntimeException('QA_LOT_SCHEMA_MISMATCH');
        }
        if(!DB::getSchemaBuilder()->hasTable('qa_lot_backfill_runs'))DB::unprepared('CREATE TABLE qa_lot_backfill_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT NOT NULL, source_hash CHAR(64) NOT NULL, evidence LONGTEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_qa_backfill(company_id,source_hash)) ENGINE=InnoDB');
        if(self::schemaProblems())throw new \RuntimeException('QA_LOT_SCHEMA_MISMATCH');
    }

    public static function schemaProblems():array
    {
        UomStageAProof::guard();$problems=[];
        foreach(['inventory_lot_value_balances'=>['original_quantity'=>'decimal(18,3)','remaining_quantity'=>'decimal(18,3)','original_value'=>'decimal(18,3)','remaining_value'=>'decimal(18,3)','policy_version'=>'varchar(32)'],
            'qa_lot_backfill_runs'=>['source_hash'=>'char(64)','evidence'=>'longtext']] as $table=>$columns){
            if(!DB::getSchemaBuilder()->hasTable($table)){$problems[]=['table'=>$table,'reason'=>'MISSING_TABLE'];continue;}
            foreach($columns as $column=>$expected){
                $actual=DB::selectOne('SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
                if(!$actual||$actual->t!==$expected)$problems[]=['table'=>$table,'column'=>$column,'reason'=>'INCOMPATIBLE_COLUMN'];
            }
            $indexes=DB::select('SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columns_key FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 GROUP BY INDEX_NAME',[$table]);
            $expected=$table==='inventory_lot_value_balances'?'company_id,inventory_lot_id':'company_id,source_hash';
            if(!in_array($expected,array_column($indexes,'columns_key'),true))$problems[]=['table'=>$table,'reason'=>'MISSING_UNIQUE_SCOPE'];
        }
        return $problems;
    }

    private static function same(string $left,string $right):bool{return bccomp($left,$right,3)===0;}
    private static function snapshot(int $company):array
    {
        $out=[];
        foreach(['inventory_lots','inventory_lot_movements','purchase_invoices','purchase_invoice_lines','sales_invoices','sales_invoice_lines','sales_line_lot_sources','journal_entries','journal_entry_lines','items','opening_balance_batches','opening_inventory_lines','inventory_operations'] as $table)
            $out[$table]=DB::table($table)->where('company_id',$company)->orderBy('id')->get()->all();
        if(DB::getSchemaBuilder()->hasTable('uom_inventory_adjustments'))$out['uom_inventory_adjustments']=DB::table('uom_inventory_adjustments')->where('company_id',$company)->orderBy('id')->get()->all();
        foreach(['uom_processing_commands','uom_processing_recipes','uom_processing_cost_sources','uom_lot_capitalization_events','uom_invoice_header_cost_receipts','shipment_costs','shipment_cost_allocation_receipts'] as $table)if(DB::getSchemaBuilder()->hasTable($table))$out[$table]=DB::table($table)->where('company_id',$company)->orderBy('id')->get()->all();
        return $out;
    }

    public static function plan(int $company):array
    {
        UomStageAProof::guard();$snapshot=self::snapshot($company);$rows=[];$exceptions=[];$scopeValues=[];$preservedBase=[];
        $inventoryAccounts=collect($snapshot['items'])->pluck('inventory_account_id')->filter()->map(fn($id)=>(int)$id)->all();
        foreach($snapshot['journal_entry_lines'] as $line){
            if(in_array((int)$line->account_id,$inventoryAccounts,true))$scopeValues[$line->branch_id.':'.$line->account_id]='0.000';
        }
        foreach($snapshot['inventory_lots'] as $lot){
            try{
                $item=collect($snapshot['items'])->firstWhere('id',$lot->item_id);
                if(!$item||!$item->inventory_account_id)throw new \RuntimeException('INVENTORY_ACCOUNT_EVIDENCE_REQUIRED');
                if(($lot->uom_version??'LEGACY_KG')==='BASE_V1'){
                    $baseValue=self::auditBase($lot,$snapshot);$key=$lot->branch_id.':'.$item->inventory_account_id;
                    $scopeValues[$key]=bcadd($scopeValues[$key]??'0',$baseValue,3);
                    $preservedBase[]=['lot_id'=>$lot->id,'remaining_quantity'=>$lot->remaining_base_qty,'remaining_value'=>$baseValue,'action'=>'PRESERVE_EXISTING_BASE_VALUE'];continue;
                }
                $recipeReceipt=$lot->source_type==='INVENTORY_OPERATION';
                if($recipeReceipt){
                    if(!self::same($lot->allocated_cost,'0'))throw new \RuntimeException('SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED');
                    self::legacyProcessingEvidence($lot,$snapshot,(int)$lot->source_id,'IN',$lot->qty_received_kg,$lot->total_cost,false);
                    $invoice=(object)['id'=>$lot->source_id];
                }else{
                if($lot->source_type!=='PURCHASE'||!$lot->purchase_invoice_line_id||!self::same($lot->allocated_cost,'0'))throw new \RuntimeException('SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED');
                $invoice=collect($snapshot['purchase_invoices'])->firstWhere('id',$lot->purchase_invoice_id);
                $line=collect($snapshot['purchase_invoice_lines'])->firstWhere('id',$lot->purchase_invoice_line_id);
                if(!$invoice||!$line||$invoice->document_status!=='POSTED'||$invoice->voided_at||!$invoice->journal_entry_id
                    ||(int)$line->purchase_invoice_id!==(int)$invoice->id||(int)$line->item_id!==(int)$lot->item_id
                    ||(int)$invoice->branch_id!==(int)$lot->branch_id
                    ||!self::same($line->qty_kg,$lot->qty_received_kg)||!self::same($line->base_total_before_vat,$lot->total_cost))throw new \RuntimeException('PURCHASE_SOURCE_MISMATCH');
                if(collect($snapshot['inventory_lots'])->where('purchase_invoice_line_id',$line->id)->count()!==1)throw new \RuntimeException('SPLIT_RECEIPT_REVIEW_REQUIRED');
                }
                $quantity='0.000';$value='0.000';$receiptCount=0;$evidence=[];
                $movements=collect($snapshot['inventory_lot_movements'])->where('inventory_lot_id',$lot->id);
                foreach($movements as $movement){
                    if((int)$movement->item_id!==(int)$lot->item_id||(int)$movement->branch_id!==(int)$lot->branch_id
                        ||bccomp($movement->qty_kg,'0',3)<=0||bccomp($movement->total_cost,'0',3)<0)throw new \RuntimeException('MOVEMENT_SCOPE_OR_AMOUNT_MISMATCH');
                    if($movement->source_type===($recipeReceipt?'INVENTORY_OPERATION':'PURCHASE')&&$movement->movement_type==='IN'&&(int)$movement->source_id===(int)$invoice->id){
                        if(++$receiptCount!==1||!self::same($movement->qty_kg,$lot->qty_received_kg)||!self::same($movement->total_cost,$lot->total_cost))throw new \RuntimeException('RECEIPT_EVIDENCE_MISMATCH');
                        $quantity=bcadd($quantity,$movement->qty_kg,3);$value=bcadd($value,$movement->total_cost,3);
                    }elseif($movement->source_type==='SALE'&&$movement->movement_type==='OUT'){
                        $sale=collect($snapshot['sales_invoices'])->firstWhere('id',$movement->source_id);
                        if(!$sale||$sale->document_status!=='POSTED'||$sale->voided_at||!$sale->journal_entry_id)throw new \RuntimeException('SALE_SOURCE_REVIEW_REQUIRED');
                        $ids=collect($snapshot['sales_invoice_lines'])->where('sales_invoice_id',$sale->id)->where('item_id',$lot->item_id)->pluck('id');
                        $sources=collect($snapshot['sales_line_lot_sources'])->where('inventory_lot_id',$lot->id)->whereIn('sales_invoice_line_id',$ids);
                        $q='0';$v='0';foreach($sources as $source){$q=bcadd($q,$source->qty_kg,3);$v=bcadd($v,$source->total_cost,3);}
                        // Multiple movements for one sale/LOT require additional event identity; do not count the same allocation twice.
                        if($movements->where('source_type','SALE')->where('source_id',$sale->id)->count()!==1||!self::same($q,$movement->qty_kg)||!self::same($v,$movement->total_cost))throw new \RuntimeException('FIFO_SOURCE_EVIDENCE_MISMATCH');
                        $quantity=bcsub($quantity,$movement->qty_kg,3);$value=bcsub($value,$movement->total_cost,3);
                    }elseif(in_array($movement->source_type,['INVENTORY_OPERATION','UOM_PROCESS_REVERSAL'],true)){
                        $reversal=$movement->source_type==='UOM_PROCESS_REVERSAL';
                        self::legacyProcessingEvidence($lot,$snapshot,(int)$movement->source_id,$movement->movement_type,$movement->qty_kg,$movement->total_cost,$reversal);
                        if($movements->where('source_type',$movement->source_type)->where('source_id',$movement->source_id)->where('movement_type',$movement->movement_type)->count()!==1)throw new \RuntimeException('PROCESS_DUPLICATE_MOVEMENT_REVIEW_REQUIRED');
                        if($movement->movement_type==='IN'){$quantity=bcadd($quantity,$movement->qty_kg,3);$value=bcadd($value,$movement->total_cost,3);}
                        elseif($movement->movement_type==='OUT'){$quantity=bcsub($quantity,$movement->qty_kg,3);$value=bcsub($value,$movement->total_cost,3);}
                        else throw new \RuntimeException('PROCESS_MOVEMENT_DIRECTION');
                    }else throw new \RuntimeException('UNSUPPORTED_HISTORY_REQUIRES_REVIEW');
                    if(bccomp($quantity,'0',3)<0||bccomp($value,'0',3)<0)throw new \RuntimeException('NEGATIVE_REPLAY_BALANCE');
                    $evidence[]=(int)$movement->id;
                }
                if($receiptCount!==1||!self::same($quantity,$lot->qty_remaining_kg)||bccomp($value,$lot->total_cost,3)>0
                    ||(self::same($quantity,'0')&&!self::same($value,'0')))throw new \RuntimeException('REPLAY_BALANCE_MISMATCH');
                $key=$lot->branch_id.':'.$item->inventory_account_id;
                $scopeValues[$key]=bcadd($scopeValues[$key]??'0',$value,3);
                $rows[]=['company_id'=>$company,'inventory_lot_id'=>$lot->id,'original_quantity'=>$lot->qty_received_kg,'remaining_quantity'=>$quantity,
                    'original_value'=>$lot->total_cost,'remaining_value'=>$value,'policy_version'=>'FINAL_RESIDUAL_V1','evidence_movement_ids'=>$evidence];
            }catch(\Throwable $failure){$exceptions[]=['lot_id'=>$lot->id,'reason'=>$failure instanceof \JsonException?'MALFORMED_SOURCE_JSON':$failure->getMessage()];}
        }
        $financial=[];
        foreach($scopeValues as $key=>$expected){
            [$branch,$account]=array_map('intval',explode(':',$key));$actual='0';
            foreach($snapshot['journal_entry_lines'] as $line){
                if((int)$line->branch_id!==$branch||(int)$line->account_id!==$account)continue;
                $entry=collect($snapshot['journal_entries'])->firstWhere('id',$line->journal_entry_id);
                if(!$entry||$entry->status!=='POSTED'){$exceptions[]=['scope'=>$key,'reason'=>'JOURNAL_STATE_REVIEW_REQUIRED'];continue;}
                $actual=bcadd($actual,bcsub($line->debit,$line->credit,3),3);
            }
            $financial[$key]=['replayed_value'=>$expected,'gl_value'=>$actual];
            if(!self::same($expected,$actual))$exceptions[]=['scope'=>$key,'reason'=>'GL_RECONCILIATION_MISMATCH'];
        }
        return ['company_id'=>$company,'source_hash'=>hash('sha256',serialize($snapshot)),'rows'=>$rows,'preserved_base_lots'=>$preservedBase,'exceptions'=>$exceptions,'financial'=>$financial,'ready'=>$exceptions===[]];
    }

    /** Only immutable, hashed recipe commands qualify these newly adapted histories. */
    private static function legacyProcessingEvidence(object $lot,array $snapshot,int $operation,string $direction,string $quantity,string $value,bool $reversal):void
    {
        $head=collect($snapshot['inventory_operations'])->firstWhere('id',$operation);$command=collect($snapshot['uom_processing_commands']??[])->firstWhere('operation_id',$operation);$recipe=$command?collect($snapshot['uom_processing_recipes']??[])->firstWhere('id',$command->recipe_id):null;
        if(!$head||!$command||!$recipe||(int)$command->branch_id!==(int)$lot->branch_id||(int)$head->from_branch_id!==(int)$lot->branch_id||!in_array($head->status,['POSTED','CANCELLED'],true)||!in_array($command->status,['POSTED','VOIDED'],true)||$recipe->status!=='APPROVED'||!$command->result_hash||!hash_equals($command->result_hash,hash('sha256',$command->result_json??''))||!hash_equals($command->payload_hash,hash('sha256',$command->payload))||!hash_equals($recipe->payload_hash,hash('sha256',$recipe->payload)))throw new \RuntimeException('LEGACY_PROCESS_SOURCE_REVIEW_REQUIRED');
        $result=json_decode($command->result_json,true,512,JSON_THROW_ON_ERROR);$payload=json_decode($command->payload,true,512,JSON_THROW_ON_ERROR);
        \App\Services\Uom\ProcessingExtraCost::audit((int)$lot->company_id,$operation,$payload['costs']??[],$result);
        if(($result['recipe_hash']??'')!==$recipe->payload_hash||($payload['recipe']['recipe_hash']??'')!==$recipe->payload_hash)throw new \RuntimeException('LEGACY_PROCESS_SOURCE_REVIEW_REQUIRED');
        $originalDirection=$reversal?($direction==='IN'?'OUT':'IN'):$direction;$side=$originalDirection==='IN'?'outputs':'inputs';$source=collect($result[$side]??[])->firstWhere('inventory_lot_id',$lot->id);
        if(!$source||($source['uom_version']??'')!=='LEGACY_KG'||!self::same($source['qty_kg'],$quantity)||!self::same($source['total_cost'],$value))throw new \RuntimeException('LEGACY_PROCESS_ALLOCATION_MISMATCH');
        if(!$head->journal_entry_id){
            // A documented zero-cost FIFO allocation does not create a monetary journal.
            // Missing historical money is never treated as zero: every hashed value must be present.
            if(!isset($result['input_inventory_cost'])||!self::same($result['input_inventory_cost'],'0')||($result['journal_entry_id']??null)!==null)throw new \RuntimeException('LEGACY_PROCESS_JOURNAL_REQUIRED');
            foreach(array_merge($result['inputs'],$result['outputs']) as $allocation)if(!isset($allocation['total_cost'])||!self::same($allocation['total_cost'],'0'))throw new \RuntimeException('LEGACY_PROCESS_JOURNAL_REQUIRED');
            if(collect($snapshot['journal_entries'])->contains(fn($j)=>$j->source_type==='UOM_INVENTORY_PROCESS'&&(int)$j->source_id===$operation))throw new \RuntimeException('LEGACY_PROCESS_JOURNAL_REQUIRED');
            if($reversal&&($command->status!=='VOIDED'||$command->reversal_journal_entry_id!==null))throw new \RuntimeException('LEGACY_PROCESS_REVERSAL_REQUIRED');return;
        }
        $entry=collect($snapshot['journal_entries'])->firstWhere('id',$head->journal_entry_id);
        if(!$entry||$entry->status!=='POSTED'||(int)$entry->branch_id!==(int)$lot->branch_id||$entry->source_type!=='UOM_INVENTORY_PROCESS'||(int)$entry->source_id!==$operation)throw new \RuntimeException('LEGACY_PROCESS_JOURNAL_REQUIRED');
        $debit='0';$credit='0';foreach(collect($snapshot['journal_entry_lines'])->where('journal_entry_id',$entry->id) as $line){$debit=bcadd($debit,$line->debit,3);$credit=bcadd($credit,$line->credit,3);}
        $total=$result['total_output_cost']??$result['input_inventory_cost'];
        if(!self::same($debit,$total)||!self::same($credit,$total))throw new \RuntimeException('LEGACY_PROCESS_JOURNAL_AMOUNT');
        if($reversal){$reverse=collect($snapshot['journal_entries'])->firstWhere('id',$command->reversal_journal_entry_id);if($command->status!=='VOIDED'||!$reverse||$reverse->status!=='POSTED'||(int)$reverse->reversal_of_id!==(int)$entry->id)throw new \RuntimeException('LEGACY_PROCESS_REVERSAL_REQUIRED');}
    }

    private static function auditBase(object $lot,array $snapshot):string
    {
        $capitalized='0.000';
        if(DB::getSchemaBuilder()->hasTable('uom_lot_capitalization_events'))$capitalized=\App\Services\Uom\BaseLotCapitalization::audit($lot);
        elseif(!self::same($lot->allocated_cost,'0'))throw new \RuntimeException('BASE_CAPITALIZATION_REVIEW_REQUIRED');
        if($lot->source_type==='PURCHASE'){
            $head=collect($snapshot['purchase_invoices'])->firstWhere('id',$lot->purchase_invoice_id);
            $line=collect($snapshot['purchase_invoice_lines'])->firstWhere('id',$lot->purchase_invoice_line_id);
            if(!$head||!$line||!in_array($head->document_status,['POSTED','VOID','VOIDED'],true)||!$head->journal_entry_id||(int)$head->branch_id!==(int)$lot->branch_id||(int)$line->purchase_invoice_id!==(int)$head->id||(int)$line->item_id!==(int)$lot->item_id||bccomp($line->base_quantity,$lot->received_base_qty,6)!==0)throw new \RuntimeException('BASE_SOURCE_REVIEW_REQUIRED');
            \App\Services\Uom\BaseInvoiceHeaderCost::auditLot((int)$lot->company_id,$head,$line,$lot);
        }elseif($lot->source_type==='OPENING_BALANCE'){
            $head=collect($snapshot['opening_balance_batches'])->firstWhere('id',$lot->source_id);
            $line=collect($snapshot['opening_inventory_lines'])->firstWhere('inventory_lot_id',$lot->id);
            if(!$head||!$line||$head->status!=='POSTED'||!$head->journal_entry_id||(int)$line->batch_id!==(int)$head->id||(int)$line->branch_id!==(int)$lot->branch_id||(int)$line->item_id!==(int)$lot->item_id||bccomp($line->base_quantity,$lot->received_base_qty,6)!==0)throw new \RuntimeException('BASE_SOURCE_REVIEW_REQUIRED');
        }elseif($lot->source_type==='TRANSFER'){
            $head=collect($snapshot['inventory_operations'])->firstWhere('id',$lot->source_id);
            if(!$head||!in_array($head->status,['APPROVED','POSTED'],true)||(int)$head->to_branch_id!==(int)$lot->branch_id)throw new \RuntimeException('BASE_SOURCE_REVIEW_REQUIRED');
        }elseif($lot->source_type==='UOM_ADJUSTMENT'){
            $head=collect($snapshot['uom_inventory_adjustments']??[])->firstWhere('id',$lot->source_id);
            if(!$head||!in_array($head->document_status,['POSTED','VOIDED'],true)||$head->movement_type!=='IN'||!$head->journal_entry_id||(int)$head->item_id!==(int)$lot->item_id||(int)$head->branch_id!==(int)$lot->branch_id||bccomp($head->base_quantity,$lot->received_base_qty,6)!==0||!self::same($head->total_cost,$lot->total_cost))throw new \RuntimeException('BASE_SOURCE_REVIEW_REQUIRED');
        }elseif($lot->source_type==='INVENTORY_OPERATION'){
            $head=collect($snapshot['inventory_operations'])->firstWhere('id',$lot->source_id);
            $command=collect($snapshot['uom_processing_commands']??[])->firstWhere('operation_id',$lot->source_id);
            $recipe=$command?collect($snapshot['uom_processing_recipes']??[])->firstWhere('id',$command->recipe_id):null;
            if(!$head||!$command||!$recipe||!in_array($head->status,['POSTED','CANCELLED'],true)||!in_array($command->status,['POSTED','VOIDED'],true)||$recipe->status!=='APPROVED'||(int)$command->branch_id!==(int)$lot->branch_id||!$command->result_json||!$command->result_hash||!hash_equals($command->result_hash,hash('sha256',$command->result_json))||!hash_equals($command->payload_hash,hash('sha256',$command->payload))||!hash_equals($recipe->payload_hash,hash('sha256',$recipe->payload)))throw new \RuntimeException('BASE_PROCESS_SOURCE_REVIEW_REQUIRED');
            $result=json_decode($command->result_json,true,512,JSON_THROW_ON_ERROR);$payload=json_decode($command->payload,true,512,JSON_THROW_ON_ERROR);\App\Services\Uom\ProcessingExtraCost::audit((int)$lot->company_id,(int)$lot->source_id,$payload['costs']??[],$result);$output=collect($result['outputs'])->firstWhere('inventory_lot_id',$lot->id);
            if(!$output||$result['recipe_hash']!==$recipe->payload_hash||(int)$output['item_id']!==(int)$lot->item_id||bccomp($output['base_quantity'],$lot->received_base_qty,6)!==0||!self::same($output['total_cost'],$lot->total_cost))throw new \RuntimeException('BASE_PROCESS_SOURCE_REVIEW_REQUIRED');
        }else throw new \RuntimeException('BASE_SOURCE_REVIEW_REQUIRED');
        $quantity='0.000000';$value=$capitalized;$movements=collect($snapshot['inventory_lot_movements'])->where('inventory_lot_id',$lot->id);
        if($movements->isEmpty())throw new \RuntimeException('BASE_MOVEMENT_EVIDENCE_REQUIRED');
        foreach($movements as $movement){
            if(($movement->uom_version??'')!=='BASE_V1'||(int)$movement->item_id!==(int)$lot->item_id||(int)$movement->branch_id!==(int)$lot->branch_id||(int)$movement->base_uom_id_snapshot!==(int)$lot->base_uom_id_snapshot||!in_array($movement->movement_type,['IN','OUT'],true)||bccomp($movement->base_quantity,'0',6)<=0||bccomp($movement->total_cost,'0',3)<0)throw new \RuntimeException('BASE_MOVEMENT_SCOPE_MISMATCH');
            $quantity=$movement->movement_type==='IN'?bcadd($quantity,$movement->base_quantity,6):bcsub($quantity,$movement->base_quantity,6);
            $value=$movement->movement_type==='IN'?bcadd($value,$movement->total_cost,3):bcsub($value,$movement->total_cost,3);
            if(bccomp($quantity,'0',6)<0||bccomp($value,'0',3)<0)throw new \RuntimeException('BASE_REPLAY_NEGATIVE');
        }
        if(bccomp($quantity,$lot->remaining_base_qty,6)!==0||!self::same($value,$lot->uom_remaining_value))throw new \RuntimeException('BASE_REPLAY_BALANCE_MISMATCH');
        return $value;
    }

    public static function apply(array $approved,bool $writersPaused):int
    {
        UomStageAProof::guard();
        if(!$writersPaused||DB::transactionLevel()!==0)throw new \RuntimeException('OFFLINE_ROOT_REQUIRED');
        $company=(int)$approved['company_id'];
        // A QA-only offline operator gate, NOT proof that a future production writer cannot connect.
        if((int)DB::selectOne('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID()')->n!==0)throw new \RuntimeException('OTHER_DATABASE_CLIENTS_PRESENT');
        return DB::transaction(function()use($company,$approved){
            DB::table('inventory_lots')->where('company_id',$company)->orderBy('id')->lockForUpdate()->get();
            $current=self::plan($company);
            if($current!==$approved)throw new \RuntimeException('STALE_OR_ALTERED_BACKFILL_PLAN');
            if(!$current['ready'])throw new \RuntimeException('UNRESOLVED_HISTORY');
            foreach($current['rows'] as $row){
                unset($row['evidence_movement_ids']);
                $existing=DB::table('inventory_lot_value_balances')->where('company_id',$company)->where('inventory_lot_id',$row['inventory_lot_id'])->lockForUpdate()->first();
                if($existing){foreach($row as $key=>$value)if((string)$existing->$key!==(string)$value)throw new \RuntimeException('EXISTING_BALANCE_MISMATCH');}
                else DB::table('inventory_lot_value_balances')->insert($row+['created_at'=>now(),'updated_at'=>now()]);
            }
            $id=DB::table('qa_lot_backfill_runs')->where('company_id',$company)->where('source_hash',$current['source_hash'])->value('id');
            if($id)return (int)$id;
            return DB::table('qa_lot_backfill_runs')->insertGetId(['company_id'=>$company,'source_hash'=>$current['source_hash'],'evidence'=>json_encode($current,JSON_THROW_ON_ERROR),'created_at'=>now()]);
        });
    }

    public static function prepareSequence(int $company,int $year,bool $writersPaused):int
    {
        UomStageAProof::guard();
        if(!$writersPaused||DB::transactionLevel()!==0)throw new \RuntimeException('OFFLINE_ROOT_REQUIRED');
        if((int)DB::selectOne('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID()')->n!==0)throw new \RuntimeException('OTHER_DATABASE_CLIENTS_PRESENT');
        require_once __DIR__.'/LotSequencePreparationProof.php';
        return DB::transaction(function()use($company,$year){
            $scope=['company_id'=>$company,'branch_id'=>0,'document_type'=>'INVENTORY_LOT','document_year'=>$year];
            $before=DB::table('sulb_document_sequences')->where($scope)->lockForUpdate()->first();
            $next=LotSequencePreparationProof::prepare($company,$year,true);
            $after=DB::table('sulb_document_sequences')->where($scope)->first();
            if($before!=$after){
                $evidence=['operation'=>'LOT_SEQUENCE_PREPARATION','company_id'=>$company,'year'=>$year,'before'=>$before,'after'=>$after];
                DB::table('qa_lot_backfill_runs')->insert(['company_id'=>$company,'source_hash'=>hash('sha256',serialize($evidence)),
                    'evidence'=>json_encode($evidence,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            }
            return $next;
        });
    }
}
