<?php

namespace App\Domain\Accounting\Services;

use App\Services\Accounting\PostingSupport;
use App\Services\FixedAssets\FixedAssetYearEndService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialYearService
{
    public function __construct(private JournalService $journals, private AccountingReportService $reports, private PostingSupport $support, private FixedAssetYearEndService $assetYearEnd) {}

    public function create(int $companyId,array $d): int
    {
        $start=Carbon::parse($d['start_date'])->toDateString();$end=Carbon::parse($d['end_date'])->toDateString();
        if($end<$start) throw new \RuntimeException('تاريخ نهاية السنة يجب أن يكون بعد البداية.');
        $overlap=DB::table('financial_years')->where('company_id',$companyId)->where(fn($q)=>$q->whereBetween('start_date',[$start,$end])->orWhereBetween('end_date',[$start,$end])->orWhere(fn($x)=>$x->where('start_date','<=',$start)->where('end_date','>=',$end)))->exists();
        if($overlap) throw new \RuntimeException('الفترة تتداخل مع سنة مالية مسجلة.');
        return DB::table('financial_years')->insertGetId(['company_id'=>$companyId,'year_name'=>$d['year_name']??$this->name($start,$end),'start_date'=>$start,'end_date'=>$end,'is_closed'=>0,'created_at'=>now(),'updated_at'=>now()]);
    }

    public function preview(int $companyId,int $yearId): array
    {
        $y=DB::table('financial_years')->where('company_id',$companyId)->where('id',$yearId)->first();if(!$y)throw new \RuntimeException('السنة المالية غير موجودة.');
        $trial=$this->reports->trialBalance($companyId,null,['from_date'=>$y->start_date,'to_date'=>$y->end_date]);
        $income=$this->reports->incomeStatement($companyId,null,['from_date'=>$y->start_date,'to_date'=>$y->end_date]);
        $entries=DB::table('journal_entries')->where('company_id',$companyId)->where('financial_year_id',$yearId)->where('status','POSTED')->count();
        $missingDepreciation=$this->assetYearEnd->missingCount($companyId,$y->start_date,$y->end_date);return ['year'=>$y,'entries_count'=>$entries,'trial_balance_difference'=>$trial['totals']['difference'],'revenue_total'=>$income['revenue_total'],'expense_total'=>$income['cost_of_revenue_total']+$income['operating_expenses_total'],'net_result'=>$income['net_result'],'pending_depreciation_months'=>$missingDepreciation,'depreciation_will_post_on_close'=>$missingDepreciation>0,'can_close'=>abs((float)$trial['totals']['difference'])<0.001 && !(int)$y->is_closed];
    }

    public function close(int $companyId,int $yearId,?int $userId=null): array
    {
        return DB::transaction(function() use($companyId,$yearId,$userId){
            $y=DB::table('financial_years')->where('company_id',$companyId)->where('id',$yearId)->lockForUpdate()->first();if(!$y)throw new \RuntimeException('السنة المالية غير موجودة.');if((int)$y->is_closed)throw new \RuntimeException('السنة المالية مقفلة بالفعل.');
            $depreciation=$this->assetYearEnd->complete($companyId,$y->start_date,$y->end_date,$userId);
            $p=$this->preview($companyId,$yearId);if(!$p['can_close'])throw new \RuntimeException('لا يمكن الإقفال قبل توازن القيود ومعالجة الفروقات.');
            $resultAcc=$this->support->setting($companyId,'CURRENT_YEAR_RESULT_ACCOUNT');$retained=$this->support->setting($companyId,'RETAINED_EARNINGS_ACCOUNT');
            $balances=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->join('accounts as a','a.id','=','l.account_id')
                ->where('l.company_id',$companyId)->where('e.financial_year_id',$yearId)->where('e.status','POSTED')->where('e.is_closing_entry',0)->whereIn('a.account_type',['REVENUE','EXPENSE'])
                ->select('a.id','a.account_name','a.account_type',DB::raw('SUM(l.debit) debit'),DB::raw('SUM(l.credit) credit'))->groupBy('a.id','a.account_name','a.account_type')->get();
            $lines=[];$td=0.0;$tc=0.0;foreach($balances as $b){$raw=(float)$b->debit-(float)$b->credit;if(abs($raw)<0.0001)continue;
                if($raw<0){$d=abs($raw);$c=0;}else{$d=0;$c=$raw;} // عكس الرصيد الفعلي
                $lines[]=['account_id'=>$b->id,'debit'=>$d,'credit'=>$c,'description'=>'إقفال '.$b->account_name];$td+=$d;$tc+=$c;}
            $net=round((float)$p['net_result'],3);$plEntry=null;$retEntry=null;
            if($lines){$diff=round($td-$tc,3);if($diff>0)$lines[]=['account_id'=>$resultAcc,'debit'=>0,'credit'=>$diff,'description'=>'نتيجة السنة - ربح'];elseif($diff<0)$lines[]=['account_id'=>$resultAcc,'debit'=>abs($diff),'credit'=>0,'description'=>'نتيجة السنة - خسارة'];
                $plEntry=$this->journals->post(['company_id'=>$companyId,'branch_id'=>null,'allow_company_level'=>true,'entry_date'=>$y->end_date,'source_type'=>'YEAR_CLOSE_PNL','source_id'=>$yearId,'description'=>'إقفال حسابات الإيرادات والمصروفات للسنة '.$y->year_name,'lines'=>$lines,'is_closing_entry'=>1,'is_system_generated'=>1,'created_by'=>$userId]);}
            if(abs($net)>0.0001){$transfer=$net>0?[['account_id'=>$resultAcc,'debit'=>$net,'credit'=>0,'description'=>'إقفال نتيجة السنة'],['account_id'=>$retained,'debit'=>0,'credit'=>$net,'description'=>'ترحيل الربح إلى الأرباح المحتجزة']]
                :[['account_id'=>$retained,'debit'=>abs($net),'credit'=>0,'description'=>'ترحيل الخسارة إلى الأرباح المحتجزة'],['account_id'=>$resultAcc,'debit'=>0,'credit'=>abs($net),'description'=>'إقفال نتيجة السنة']];
                $retEntry=$this->journals->post(['company_id'=>$companyId,'branch_id'=>null,'allow_company_level'=>true,'entry_date'=>$y->end_date,'source_type'=>'YEAR_CLOSE_RETAINED','source_id'=>$yearId,'description'=>'ترحيل نتيجة السنة إلى الأرباح المحتجزة','lines'=>$transfer,'is_closing_entry'=>1,'is_system_generated'=>1,'created_by'=>$userId]);}
            DB::table('financial_years')->where('id',$yearId)->where('company_id',$companyId)->update(['is_closed'=>1,'closed_at'=>now(),'closed_by'=>$userId,'updated_at'=>now()]);
            $nextStart=Carbon::parse($y->end_date)->addDay()->toDateString();$nextEnd=Carbon::parse($nextStart)->addYear()->subDay()->toDateString();
            $next=DB::table('financial_years')->where('company_id',$companyId)->where('start_date',$nextStart)->first();
            $nextId=$next?(int)$next->id:DB::table('financial_years')->insertGetId(['company_id'=>$companyId,'year_name'=>$this->name($nextStart,$nextEnd),'start_date'=>$nextStart,'end_date'=>$nextEnd,'is_closed'=>0,'created_at'=>now(),'updated_at'=>now()]);
            $last=DB::table('financial_year_closures')->where('company_id',$companyId)->max('id')??0;$cn='FYC-'.date('Y',strtotime($y->end_date)).'-'.str_pad($last+1,5,'0',STR_PAD_LEFT);
            $cid=DB::table('financial_year_closures')->insertGetId(['company_id'=>$companyId,'financial_year_id'=>$yearId,'closure_number'=>$cn,'close_date'=>$y->end_date,'revenue_total'=>$p['revenue_total'],'expense_total'=>$p['expense_total'],'net_result'=>$net,'profit_loss_entry_id'=>$plEntry,'retained_earnings_entry_id'=>$retEntry,'next_financial_year_id'=>$nextId,'status'=>'CLOSED','closed_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
            return ['closure_id'=>$cid,'closure_number'=>$cn,'net_result'=>$net,'next_financial_year_id'=>$nextId,'profit_loss_entry_id'=>$plEntry,'retained_earnings_entry_id'=>$retEntry,'depreciation'=>$depreciation];
        });
    }

    public function reopen(int $companyId,int $yearId,?int $userId=null): array
    {
        return DB::transaction(function() use($companyId,$yearId,$userId){
            $y=DB::table('financial_years')->where('company_id',$companyId)->where('id',$yearId)->lockForUpdate()->first();if(!$y)throw new \RuntimeException('السنة المالية غير موجودة.');if(!(int)$y->is_closed)throw new \RuntimeException('السنة المالية مفتوحة بالفعل.');
            $later=DB::table('journal_entries')->where('company_id',$companyId)->where('status','POSTED')->where('is_closing_entry',0)->whereDate('entry_date','>',$y->end_date)->exists();
            if($later)throw new \RuntimeException('لا يمكن إعادة فتح السنة بعد وجود قيود مرحلة في سنة لاحقة.');
            $closure=DB::table('financial_year_closures')->where('company_id',$companyId)->where('financial_year_id',$yearId)->where('status','CLOSED')->orderByDesc('id')->first();
            if(!$closure)throw new \RuntimeException('لم يتم العثور على سجل الإقفال.');
            DB::table('financial_years')->where('id',$yearId)->update(['is_closed'=>0,'closed_at'=>null,'closed_by'=>null,'updated_at'=>now()]);
            $reversed=[];foreach([$closure->retained_earnings_entry_id,$closure->profit_loss_entry_id] as $eid){if($eid)$reversed[]=$this->journals->reverse($companyId,(int)$eid,['entry_date'=>$y->end_date,'source_type'=>'YEAR_REOPEN','reason'=>'إعادة فتح السنة المالية '.$y->year_name,'is_closing_entry'=>1,'created_by'=>$userId]);}
            DB::table('financial_year_closures')->where('id',$closure->id)->update(['status'=>'REOPENED','reopened_by'=>$userId,'reopened_at'=>now(),'updated_at'=>now()]);
            return ['reversed_entries'=>$reversed];
        });
    }

    private function name(string $start,string $end): string { $a=date('Y',strtotime($start));$b=date('Y',strtotime($end));return $a===$b?$a:$a.'/'.$b; }
}
