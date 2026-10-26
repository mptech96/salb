<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;

/** QA orchestration only. A read-only InnoDB snapshot; no installer or apply calls. */
final class LotUpgradeDryRun
{
    public static function run(int $company,int $year):array
    {
        UomStageAProof::guard();
        if($company<1||$year<1900||$year>9999||DB::transactionLevel()!==0)throw new \RuntimeException('QA_DRY_RUN_SCOPE');
        DB::statement('SET TRANSACTION READ ONLY');DB::beginTransaction();
        try{
            $plan=HistoricalLotBackfill::plan($company);$exceptions=[];$high=0;$count=0;$seen=[];
            $lots=DB::table('inventory_lots')->where('company_id',$company)->orderBy('id')->get(['id','lot_number','received_at']);
            foreach($lots as $lot){
                $dated=(int)substr((string)$lot->received_at,0,4);$number=(string)$lot->lot_number;
                if($dated===$year)$count++;
                if(!preg_match('/^LOT-([0-9]{4})-([0-9]{6,18})$/D',$number,$m)){
                    if($dated===$year)$exceptions[]=['lot_id'=>$lot->id,'reason'=>'UNREVIEWED_CUSTOM_NUMBER'];continue;
                }
                if($dated!==$year&&(int)$m[1]!==$year)continue;
                if((int)$m[1]!==$dated){$exceptions[]=['lot_id'=>$lot->id,'reason'=>'NAMESPACE_DATE_MISMATCH'];continue;}
                if(isset($seen[$number]))$exceptions[]=['lot_id'=>$lot->id,'reason'=>'DUPLICATE_HISTORY'];$seen[$number]=true;
                $suffix=(int)$m[2];if($suffix<1)$exceptions[]=['lot_id'=>$lot->id,'reason'=>'INVALID_SUFFIX'];$high=max($high,$suffix);
            }
            // Opening reservations share the namespace even before a LOT is posted.
            $opening=DB::table('opening_inventory_lines as l')->join('opening_balance_batches as b',fn($j)=>$j->on('b.id','=','l.batch_id')->on('b.company_id','=','l.company_id'))
                ->where('l.company_id',$company)->whereNotNull('l.lot_number')->get(['l.id','l.lot_number','b.opening_date']);
            foreach($opening as $line){
                $dated=(int)substr((string)$line->opening_date,0,4);$number=(string)$line->lot_number;
                if(!preg_match('/^LOT-([0-9]{4})-([0-9]{6,18})$/D',$number,$m)){if($dated===$year)$exceptions[]=['opening_line_id'=>$line->id,'reason'=>'UNREVIEWED_CUSTOM_NUMBER'];continue;}
                if($dated!==$year&&(int)$m[1]!==$year)continue;
                if((int)$m[1]!==$dated)$exceptions[]=['opening_line_id'=>$line->id,'reason'=>'NAMESPACE_DATE_MISMATCH'];
                $high=max($high,(int)$m[2]);
            }
            $current=DB::table('sulb_document_sequences')->where(['company_id'=>$company,'branch_id'=>0,'document_type'=>'INVENTORY_LOT','document_year'=>$year])->value('next_number');
            $missing=[];foreach(['inventory_lot_value_balances','qa_lot_backfill_runs'] as $table)if(!DB::getSchemaBuilder()->hasTable($table))$missing[]=$table;
            $schemaProblems=HistoricalLotBackfill::schemaProblems();
            return ['mode'=>'DRY_RUN','writes_performed'=>false,'company_id'=>$company,'year'=>$year,'schema_changes_required'=>$missing,
                'schema_exceptions'=>$schemaProblems,'backfill'=>$plan,'sequence'=>['current_next_number'=>$current,'proposed_next_number'=>$exceptions?null:max((int)$current,$count+1,$high+1),'exceptions'=>$exceptions],
                'ready'=>$plan['ready']&&!$exceptions&&!$missing&&!$schemaProblems,'production_qualified'=>false];
        }finally{DB::rollBack();}
    }
}
