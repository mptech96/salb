<?php

namespace App\Domain\Accounting\Repositories;

use Illuminate\Support\Facades\DB;

class JournalRepository
{
    public function createEntry(array $d): int
    {
        return DB::table('journal_entries')->insertGetId([
            'company_id'=>$d['company_id'],'branch_id'=>$d['branch_id']??null,'financial_year_id'=>$d['financial_year_id'],'cost_center_id'=>$d['cost_center_id']??null,
            'entry_number'=>$d['entry_number'],'reference_no'=>$d['reference_no']??null,'entry_date'=>$d['entry_date'],'source_type'=>$d['source_type']??null,'source_id'=>$d['source_id']??null,
            'reversal_of_id'=>$d['reversal_of_id']??null,'description'=>$d['description']??null,'status'=>$d['status']??'POSTED','currency_code'=>$d['currency_code']??null,
            'exchange_rate'=>$d['exchange_rate']??null,'is_closing_entry'=>(int)($d['is_closing_entry']??0),'is_system_generated'=>(int)($d['is_system_generated']??0),
            'created_by'=>$d['created_by']??null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function createLines(int $entryId,int $companyId,?int $branchId,int $financialYearId,array $lines): void
    {
        foreach($lines as $line)DB::table('journal_entry_lines')->insert([
            'journal_entry_id'=>$entryId,'company_id'=>$companyId,'branch_id'=>$line['branch_id']??$branchId,'financial_year_id'=>$financialYearId,
            'cost_center_id'=>$line['cost_center_id']??null,'account_id'=>$line['account_id'],'financial_account_id'=>$line['financial_account_id']??null,
            'counterparty_branch_id'=>$line['counterparty_branch_id']??null,'party_type'=>$line['party_type']??null,'party_id'=>$line['party_id']??null,
            'currency_code'=>$line['currency_code']??null,'foreign_debit'=>round((float)($line['foreign_debit']??0),3),'foreign_credit'=>round((float)($line['foreign_credit']??0),3),
            'exchange_rate'=>$line['exchange_rate']??null,'debit'=>round((float)($line['debit']??0),3),'credit'=>round((float)($line['credit']??0),3),
            'description'=>$line['description']??null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function nextEntryNumber(int $companyId,int $financialYearId,string $date): string
    {
        $year=date('Y',strtotime($date));$last=DB::table('journal_entries')->where('company_id',$companyId)->where('financial_year_id',$financialYearId)->orderByDesc('id')->value('entry_number');$n=1;
        if(is_string($last)&&preg_match('/^JE-\d{4}-(\d+)$/',$last,$m))$n=((int)$m[1])+1;else$n=DB::table('journal_entries')->where('company_id',$companyId)->where('financial_year_id',$financialYearId)->count()+1;
        do{$number='JE-'.$year.'-'.str_pad($n,6,'0',STR_PAD_LEFT);$exists=DB::table('journal_entries')->where('company_id',$companyId)->where('financial_year_id',$financialYearId)->where('entry_number',$number)->exists();$n++;}while($exists);
        return$number;
    }

    public function findWithLines(int $companyId,int $entryId,?int $branchId=null)
    {
        $q=DB::table('journal_entries as e')->leftJoin('branches as b','b.id','=','e.branch_id')->leftJoin('users as u','u.id','=','e.created_by')->where('e.company_id',$companyId)->where('e.id',$entryId);
        if($branchId!==null)$q->where(function($x)use($branchId){$x->where('e.branch_id',$branchId)->orWhereExists(fn($s)=>$s->selectRaw('1')->from('journal_entry_lines as sx')->whereColumn('sx.journal_entry_id','e.id')->where('sx.branch_id',$branchId));});
        $entry=$q->select('e.*','b.branch_name','u.name as created_by_name')->first();if(!$entry)return null;
        $lines=DB::table('journal_entry_lines as l')->leftJoin('accounts as a','a.id','=','l.account_id')->leftJoin('cost_centers as cc','cc.id','=','l.cost_center_id')
            ->leftJoin('branches as lb','lb.id','=','l.branch_id')->leftJoin('financial_accounts as fa','fa.id','=','l.financial_account_id')->leftJoin('branches as cb','cb.id','=','l.counterparty_branch_id')
            ->where('l.company_id',$companyId)->where('l.journal_entry_id',$entryId)->when($branchId!==null,fn($x)=>$x->where('l.branch_id',$branchId))
            ->select('l.*','a.account_code','a.account_name','a.normal_side','a.allow_cost_center','cc.cost_center_code','cc.cost_center_name','lb.branch_name as line_branch_name',
                'fa.account_code as financial_account_code','fa.account_name as financial_account_name','cb.branch_name as counterparty_branch_name')->orderBy('l.id')->get();
        return['entry'=>$entry,'lines'=>$lines];
    }
}
