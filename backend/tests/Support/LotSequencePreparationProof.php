<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
/** Offline disposable proof ONLY. Not an application service or CLI production tool. */
final class LotSequencePreparationProof
{
 public static function prepare(int $company,int $year,bool $writersPaused):int{
  UomStageAProof::guard();if(!$writersPaused)throw new \RuntimeException('WRITERS_NOT_PAUSED');
  return DB::transaction(function()use($company,$year){
   $scope=['company_id'=>$company,'branch_id'=>0,'document_type'=>'INVENTORY_LOT','document_year'=>$year];
   // Offline only: inventory writers must be stopped before creating a missing scope.
   DB::table('sulb_document_sequences')->upsert([$scope+['next_number'=>1,'created_at'=>now(),'updated_at'=>now()]],array_keys($scope),['document_type']);
   $counter=DB::table('sulb_document_sequences')->where($scope)->lockForUpdate()->first();$high=0;$count=0;$seen=[];
   foreach(['inventory_lots'=>'received_at','opening_inventory_lines'=>'opening_date'] as $table=>$date){
    $rows=$table==='inventory_lots'?DB::table($table)->where('company_id',$company)->whereNotNull('lot_number')->get():DB::table('opening_inventory_lines as l')->join('opening_balance_batches as b',function($j){$j->on('b.id','=','l.batch_id')->on('b.company_id','=','l.company_id');})->where('l.company_id',$company)->whereNotNull('l.lot_number')->select('l.*','b.opening_date')->get();
    foreach($rows as $row){
     $datedYear=(int)date('Y',strtotime($row->$date));
     if($table==='inventory_lots'&&$datedYear===$year)$count++;
     $number=(string)$row->lot_number;
     if(!preg_match('/^LOT-([0-9]{4})-([0-9]{6,18})$/D',$number,$m)){if($datedYear===$year)throw new \RuntimeException('UNREVIEWED_CUSTOM_NUMBER');continue;}
     if($datedYear!==$year&&(int)$m[1]!==$year)continue;
     if((int)$m[1]!==$datedYear)throw new \RuntimeException('NAMESPACE_DATE_MISMATCH');
     if((int)$m[1]!==$year)continue;
     $n=(int)$m[2];if($n<1)throw new \RuntimeException('INVALID_SUFFIX');$high=max($high,$n);
     if($table==='inventory_lots'){if(isset($seen[$number]))throw new \RuntimeException('DUPLICATE_HISTORY');$seen[$number]=true;}
    }
   }
   $next=max((int)$counter->next_number,$count+1,$high+1);
   if($next!== (int)$counter->next_number)DB::table('sulb_document_sequences')->where('id',$counter->id)->update(['next_number'=>$next,'updated_at'=>now()]);
   return $next;
  });
 }
}
