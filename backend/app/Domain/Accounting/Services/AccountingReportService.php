<?php

namespace App\Domain\Accounting\Services;

use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    private function period(int $companyId, array $f): array
    {
        $fy=null;
        if(!empty($f['financial_year_id'])) $fy=DB::table('financial_years')->where('company_id',$companyId)->where('id',$f['financial_year_id'])->first();
        if(!$fy) $fy=DB::table('financial_years')->where('company_id',$companyId)->whereDate('start_date','<=',now()->toDateString())->whereDate('end_date','>=',now()->toDateString())->first();
        $from=$f['from_date']??($fy->start_date??now()->startOfYear()->toDateString());
        $to=$f['to_date']??($fy->end_date??now()->endOfYear()->toDateString());
        return [$from,$to,$fy];
    }

    private function sums(int $companyId,?int $branchId,?string $from,?string $to,bool $before=false,bool $excludeClosing=false): array
    {
        $q=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')
            ->where('l.company_id',$companyId)->where('e.status','POSTED');
        if($branchId!==null) $q->where('l.branch_id',$branchId);
        if($excludeClosing) $q->where('e.is_closing_entry',0);
        if($before && $from) $q->whereDate('e.entry_date','<',$from);
        else { if($from) $q->whereDate('e.entry_date','>=',$from); if($to) $q->whereDate('e.entry_date','<=',$to); }
        return $q->select('l.account_id',DB::raw('SUM(l.debit) debit'),DB::raw('SUM(l.credit) credit'))->groupBy('l.account_id')->get()->keyBy('account_id')->all();
    }

    public function trialBalance(int $companyId,?int $branchId,array $filters=[]): array
    {
        [$from,$to,$fy]=$this->period($companyId,$filters); $opening=$this->sums($companyId,$branchId,$from,null,true); $period=$this->sums($companyId,$branchId,$from,$to);
        $accounts=DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->orderBy('account_code')->get();
        $rows=[];$tot=['opening_debit'=>0,'opening_credit'=>0,'period_debit'=>0,'period_credit'=>0,'closing_debit'=>0,'closing_credit'=>0];
        foreach($accounts as $a){
            $od=(float)($opening[$a->id]->debit??0);$oc=(float)($opening[$a->id]->credit??0);$pd=(float)($period[$a->id]->debit??0);$pc=(float)($period[$a->id]->credit??0);
            $open=$od-$oc;$close=$open+$pd-$pc;
            $r=['account_id'=>$a->id,'account_code'=>$a->account_code,'account_name'=>$a->account_name,'account_type'=>$a->account_type,
                'opening_debit'=>$open>0?$open:0,'opening_credit'=>$open<0?abs($open):0,'period_debit'=>$pd,'period_credit'=>$pc,
                'closing_debit'=>$close>0?$close:0,'closing_credit'=>$close<0?abs($close):0];
            foreach($tot as $k=>$_)$tot[$k]+=$r[$k]; if(array_sum(array_map('abs',array_slice($r,4)))>0.0001)$rows[]=$r;
        }
        $tot['difference']=round($tot['period_debit']-$tot['period_credit'],3);
        return ['from_date'=>$from,'to_date'=>$to,'financial_year'=>$fy,'rows'=>$rows,'totals'=>$tot];
    }

    public function incomeStatement(int $companyId,?int $branchId,array $filters=[]): array
    {
        [$from,$to,$fy]=$this->period($companyId,$filters);$s=$this->sums($companyId,$branchId,$from,$to,false,true);
        $acc=DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->whereIn('account_type',['REVENUE','EXPENSE'])->orderBy('account_code')->get();
        $rev=[];$cost=[];$opex=[];$rt=0;$ct=0;$ot=0;
        foreach($acc as $a){$d=(float)($s[$a->id]->debit??0);$c=(float)($s[$a->id]->credit??0);$amount=$a->account_type==='REVENUE'?($c-$d):($d-$c); if(abs($amount)<0.0001)continue;
            $row=['account_id'=>$a->id,'account_code'=>$a->account_code,'account_name'=>$a->account_name,'amount'=>$amount];
            if($a->account_type==='REVENUE'){$rev[]=$row;$rt+=$amount;} elseif(str_starts_with((string)$a->account_code,'5')){$cost[]=$row;$ct+=$amount;} else {$opex[]=$row;$ot+=$amount;}}
        return ['from_date'=>$from,'to_date'=>$to,'financial_year'=>$fy,'revenues'=>$rev,'cost_of_revenue'=>$cost,'operating_expenses'=>$opex,
            'revenue_total'=>$rt,'cost_of_revenue_total'=>$ct,'gross_profit'=>$rt-$ct,'operating_expenses_total'=>$ot,'net_result'=>$rt-$ct-$ot];
    }

    public function balanceSheet(int $companyId,?int $branchId,array $filters=[]): array
    {
        $asOf=$filters['as_of']??$filters['to_date']??now()->toDateString();
        $s=$this->sums($companyId,$branchId,null,$asOf,false,false);
        $acc=DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->whereIn('account_type',['ASSET','LIABILITY','EQUITY'])->orderBy('account_code')->get();
        $assets=[];$liab=[];$equity=[];$at=0;$lt=0;$et=0;$eliminations=[];$eliminatedAsset=0.0;$eliminatedLiability=0.0;
        $dueFrom=$this->settingAccountId($companyId,'INTERBRANCH_DUE_FROM_ACCOUNT');$dueTo=$this->settingAccountId($companyId,'INTERBRANCH_DUE_TO_ACCOUNT');
        foreach($acc as $a){$d=(float)($s[$a->id]->debit??0);$c=(float)($s[$a->id]->credit??0);$amount=$a->account_type==='ASSET'?($d-$c):($c-$d);if(abs($amount)<0.0001)continue;
            $row=['account_id'=>$a->id,'account_code'=>$a->account_code,'account_name'=>$a->account_name,'amount'=>$amount];
            // في القوائم الموحدة فقط نلغي جاري الفروع حتى لا تتضخم أصول/التزامات الشركة.
            if($branchId===null&&($a->id===$dueFrom||$a->id===$dueTo)){$eliminations[]=$row;if($a->account_type==='ASSET')$eliminatedAsset+=$amount;else$eliminatedLiability+=$amount;continue;}
            if($a->account_type==='ASSET'){$assets[]=$row;$at+=$amount;}elseif($a->account_type==='LIABILITY'){$liab[]=$row;$lt+=$amount;}else{$equity[]=$row;$et+=$amount;}}
        $fy=DB::table('financial_years')->where('company_id',$companyId)->whereDate('start_date','<=',$asOf)->whereDate('end_date','>=',$asOf)->first();
        $current=0.0;if($fy && !(int)$fy->is_closed){$is=$this->incomeStatement($companyId,$branchId,['from_date'=>$fy->start_date,'to_date'=>$asOf]);$current=(float)$is['net_result'];}
        return ['as_of'=>$asOf,'financial_year'=>$fy,'assets'=>$assets,'liabilities'=>$liab,'equity'=>$equity,'current_period_result'=>$current,
            'interbranch_eliminations'=>$eliminations,'interbranch_eliminated_asset'=>$eliminatedAsset,'interbranch_eliminated_liability'=>$eliminatedLiability,
            'total_assets'=>$at,'total_liabilities'=>$lt,'total_equity'=>$et+$current,'total_liabilities_equity'=>$lt+$et+$current,'difference'=>round($at-($lt+$et+$current),3)];
    }

    public function ledger(int $companyId,?int $branchId,int $accountId,array $filters=[]): array
    {
        [$from,$to,$fy]=$this->period($companyId,$filters);
        $account=DB::table('accounts')->where('company_id',$companyId)->where('id',$accountId)->where('is_active',1)->first();
        if(!$account) throw new \RuntimeException('الحساب غير موجود.');
        $base=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('l.company_id',$companyId)->where('l.account_id',$accountId)->where('e.status','POSTED');
        if($branchId!==null)$base->where('l.branch_id',$branchId);
        if(!empty($filters['cost_center_id']))$base->where('l.cost_center_id',$filters['cost_center_id']);
        if(!empty($filters['party_type']))$base->where('l.party_type',strtoupper($filters['party_type']));
        if(!empty($filters['party_id']))$base->where('l.party_id',(int)$filters['party_id']);
        $opening=(clone $base)->whereDate('e.entry_date','<',$from)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) v')->value('v');
        $rows=(clone $base)->leftJoin('branches as b','b.id','=','l.branch_id')->leftJoin('cost_centers as cc','cc.id','=','l.cost_center_id')
            ->leftJoin('financial_accounts as fa','fa.id','=','l.financial_account_id')->leftJoin('branches as cb','cb.id','=','l.counterparty_branch_id')
            ->whereDate('e.entry_date','>=',$from)->whereDate('e.entry_date','<=',$to)
            ->select('l.id','e.id as entry_id','e.entry_number','e.entry_date','e.source_type','e.source_id','e.description as entry_description','e.is_closing_entry',
                'l.description','l.debit','l.credit','l.party_type','l.party_id','l.currency_code','l.foreign_debit','l.foreign_credit','l.exchange_rate',
                'b.branch_name','cc.cost_center_name','fa.id as financial_account_id','fa.account_name as financial_account_name','cb.branch_name as counterparty_branch_name')
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')->get();
        $running=(float)$opening;$td=0;$tc=0;foreach($rows as $r){$td+=(float)$r->debit;$tc+=(float)$r->credit;$running+=(float)$r->debit-(float)$r->credit;$r->running_balance=round(abs($running),3);$r->running_side=$running>=0?'DEBIT':'CREDIT';}
        return ['account'=>$account,'from_date'=>$from,'to_date'=>$to,'financial_year'=>$fy,'opening_balance'=>abs((float)$opening),'opening_side'=>(float)$opening>=0?'DEBIT':'CREDIT',
            'rows'=>$rows,'total_debit'=>$td,'total_credit'=>$tc,'closing_balance'=>abs($running),'closing_side'=>$running>=0?'DEBIT':'CREDIT'];
    }

    public function overview(int $companyId,?int $branchId,array $filters=[]): array
    {
        $income=$this->incomeStatement($companyId,$branchId,$filters);$balance=$this->balanceSheet($companyId,$branchId,['as_of'=>$filters['to_date']??now()->toDateString()]);$trial=$this->trialBalance($companyId,$branchId,$filters);
        return ['income'=>$income,'balance_sheet'=>$balance,'trial_balance_difference'=>$trial['totals']['difference']];
    }

    private function settingAccountId(int $companyId,string $key): ?int
    {
        $id=DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key',$key)->value('account_id');
        return $id?(int)$id:null;
    }

}
