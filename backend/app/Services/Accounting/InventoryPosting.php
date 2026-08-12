<?php
namespace App\Services\Accounting;
use App\Domain\Accounting\Services\JournalService;
use Illuminate\Support\Facades\DB;
class InventoryPosting
{
    public function __construct(private JournalService $journals,private PostingSupport $support){}
    public function post(array $data): PostingResult
    {
        try{$cid=(int)$data['company_id'];$id=(int)$data['movement_id'];$m=DB::table('stock_movements')->where('company_id',$cid)->where('id',$id)->first();if(!$m)throw new \RuntimeException('حركة المخزون غير موجودة.');if($m->journal_entry_id)return PostingResult::success('الحركة مرحلة مسبقًا',(int)$m->journal_entry_id);$value=round((float)$m->total_cost,3);if($value<=0)throw new \RuntimeException('قيمة تسوية المخزون يجب أن تكون أكبر من صفر.');$inv=$this->support->setting($cid,'INVENTORY_ACCOUNT');$adj=$this->support->setting($cid,'INVENTORY_ADJUSTMENT_ACCOUNT');
            $lines=$m->movement_type==='IN'?[['account_id'=>$inv,'debit'=>$value,'credit'=>0,'description'=>'زيادة مخزون'],['account_id'=>$adj,'debit'=>0,'credit'=>$value,'description'=>'فرق جرد موجب']]:[['account_id'=>$adj,'debit'=>$value,'credit'=>0,'description'=>'فرق جرد/هالك'],['account_id'=>$inv,'debit'=>0,'credit'=>$value,'description'=>'نقص مخزون']];
            $jid=$this->journals->post(['company_id'=>$cid,'branch_id'=>(int)$m->branch_id,'entry_date'=>date('Y-m-d',strtotime($m->movement_date)),'source_type'=>'INVENTORY_ADJUSTMENT','source_id'=>$id,'description'=>'تسوية مخزون رقم '.$id,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$data['created_by']??$m->created_by]);DB::table('stock_movements')->where('id',$id)->update(['journal_entry_id'=>$jid,'source_id'=>$id,'updated_at'=>now()]);return PostingResult::success('تم ترحيل التسوية',$jid);
        }catch(\Throwable $e){return PostingResult::error($e->getMessage());}
    }
}
