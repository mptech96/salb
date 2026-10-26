<?php

namespace App\Services;

use App\Services\Accounting\AccountingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only evidence report. No inference of payment from missing allocations. */
final class DriverShipmentStatement
{
    public function drivers(Request $request): array
    {
        return $this->driverQuery($request)->select('id', 'driver_name')->orderBy('driver_name')->get()->all();
    }

    private function driverQuery(Request $request)
    {
        [$company, $branch] = $this->scope($request);
        $q = DB::table('drivers')->where('company_id', $company);
        if ($branch !== null) $q->where(fn($q) => $q->where('branch_id', $branch)->orWhereNull('branch_id'));
        return $q;
    }

    private function scope(Request $request): array
    {
        $context = app(AccountingContext::class);
        return [$context->companyId($request), $context->branchFilter($request)];
    }

    public function report(Request $request, int $driverId): array
    {
        [$company, $branch] = $this->scope($request);
        $driver = $this->driverQuery($request)->where('id',$driverId)->select('id','driver_name','branch_id')->first();
        abort_unless($driver, 404, 'السائق غير موجود ضمن نطاقك.');
        $filters = $request->validate(['from_date'=>'nullable|date','to_date'=>'nullable|date|after_or_equal:from_date','financial_year_id'=>'nullable|integer','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|in:25,50,100','search'=>'nullable|string|max:200']);
        if(!empty($filters['financial_year_id'])) {
            $year=DB::table('financial_years')->where('company_id',$company)->where('id',$filters['financial_year_id'])->first();
            abort_unless($year,404,'السنة المالية غير موجودة ضمن نطاقك.');
            $filters['from_date']=max($filters['from_date']??$year->start_date,$year->start_date);
            $filters['to_date']=min($filters['to_date']??$year->end_date,$year->end_date);
        }
        $q = DB::table('shipment_costs as c')->join('shipments as s', function($j) {
            $j->on('s.id','=','c.shipment_id')->on('s.company_id','=','c.company_id')->on('s.branch_id','=','c.branch_id');
        })->leftJoin('journal_entries as e', function($j) {
            $j->on('e.id','=','c.journal_entry_id')->on('e.company_id','=','c.company_id')->on('e.branch_id','=','c.branch_id');
        })->leftJoin('expense_types as t', function($j) use($company) {
            $j->on('t.id','=','c.expense_type_id')->where(fn($q)=>$q->where('t.company_id',$company)->orWhereNull('t.company_id'));
        })->where('c.company_id',$company)->where('c.cost_status','POSTED')
          ->where(fn($q)=>$q->where(fn($q)=>$q->where('c.payee_type','DRIVER')->where('c.payee_id',$driverId))
            ->orWhere(fn($q)=>$q->whereNull('c.payee_type')->where('s.driver_id',$driverId)));
        if ($branch !== null) $q->where('c.branch_id',$branch);
        // Complete original proof: one scoped source journal, balanced lines, one credit,
        // no financial-account dimension, matching driver and immutable original amount.
        $proof = "e.status='POSTED' AND e.source_type='SHIPMENT_COST' AND e.source_id=c.id AND e.reversed_by_id IS NULL AND e.reversed_at IS NULL AND e.reversal_of_id IS NULL
            AND NOT EXISTS(SELECT 1 FROM journal_entries r WHERE r.company_id=c.company_id AND r.reversal_of_id=e.id)
            AND (SELECT COUNT(*) FROM journal_entries x WHERE x.company_id=c.company_id AND x.source_type='SHIPMENT_COST' AND x.source_id=c.id)=1
            AND (SELECT COUNT(*) FROM journal_entry_lines l WHERE l.journal_entry_id=e.id AND l.credit>0)=1
            AND NOT EXISTS(SELECT 1 FROM journal_entry_lines l WHERE l.journal_entry_id=e.id AND (l.company_id<>c.company_id OR l.branch_id IS NULL OR l.branch_id<>c.branch_id))
            AND (SELECT COALESCE(SUM(l.debit-l.credit),0) FROM journal_entry_lines l WHERE l.journal_entry_id=e.id)=0
            AND EXISTS(SELECT 1 FROM journal_entry_lines l JOIN accounts a ON a.id=l.account_id AND a.company_id=c.company_id WHERE l.journal_entry_id=e.id AND l.party_type='DRIVER' AND l.party_id=? AND l.credit=c.amount AND l.credit>0 AND l.debit=0 AND l.financial_account_id IS NULL)
            AND NOT EXISTS(SELECT 1 FROM journal_entry_lines l WHERE l.journal_entry_id=e.id AND (l.debit<0 OR l.credit<0))
            AND NOT EXISTS(SELECT 1 FROM journal_entry_lines l LEFT JOIN accounts a ON a.id=l.account_id AND a.company_id=c.company_id WHERE l.journal_entry_id=e.id AND a.id IS NULL)
            AND c.payee_type='DRIVER' AND c.payee_id=? AND c.payment_status='UNPAID' AND c.financial_account_id IS NULL";
        if($driver->branch_id!==null) $proof.=' AND c.branch_id='.(int)$driver->branch_id;
        $q->select('c.id','c.amount','c.payment_status','c.cost_code','s.shipment_number','t.type_name','e.entry_date','e.id as journal_entry_id')->selectRaw('COALESCE(e.entry_date,c.expense_date) AS filter_date')
            ->selectRaw("CASE WHEN $proof THEN 1 ELSE 0 END AS verified",[$driverId,$driverId]);
        $paidProof = "c.payment_status='PAID' AND e.status='POSTED' AND e.source_type='SHIPMENT_COST' AND e.source_id=c.id AND e.reversed_by_id IS NULL AND e.reversed_at IS NULL
            AND NOT EXISTS(SELECT 1 FROM journal_entries r WHERE r.company_id=c.company_id AND r.reversal_of_id=e.id)
            AND (SELECT COALESCE(SUM(l.debit-l.credit),0) FROM journal_entry_lines l WHERE l.journal_entry_id=e.id)=0
            AND (SELECT COUNT(*) FROM journal_entry_lines l WHERE l.journal_entry_id=e.id AND l.credit>0)=1
            AND EXISTS(SELECT 1 FROM journal_entry_lines l JOIN financial_accounts f ON f.id=l.financial_account_id AND f.company_id=c.company_id AND f.gl_account_id=l.account_id WHERE l.journal_entry_id=e.id AND l.company_id=c.company_id AND l.branch_id=c.branch_id AND l.credit=c.amount AND l.debit=0 AND l.financial_account_id=c.financial_account_id)";
        $paidProof.=" AND e.reversal_of_id IS NULL AND (SELECT COUNT(*) FROM journal_entries x WHERE x.company_id=c.company_id AND x.source_type='SHIPMENT_COST' AND x.source_id=c.id)=1
            AND NOT EXISTS(SELECT 1 FROM journal_entry_lines l WHERE l.journal_entry_id=e.id AND (l.company_id IS NULL OR l.company_id<>c.company_id OR l.branch_id IS NULL OR l.branch_id<>c.branch_id OR l.debit<0 OR l.credit<0))";
        $q->selectRaw("CASE WHEN $paidProof THEN 1 ELSE 0 END AS direct_paid");
        $all = DB::query()->fromSub($q,'proof');
        $accountIds = DB::table('journal_entry_lines')->whereIn('journal_entry_id',(clone $all)->where('verified',1)->select('journal_entry_id'))
            ->where('party_type','DRIVER')->where('party_id',$driverId)->where('credit','>',0)->distinct()->pluck('account_id');
        $ledger = [];
        foreach($accountIds as $accountId) {
            $gl = DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')
                ->where('l.company_id',$company)->where('e.company_id',$company)->whereColumn('e.branch_id','l.branch_id')
                ->where('e.status','POSTED')->where('l.account_id',$accountId)->where('l.party_type','DRIVER')->where('l.party_id',$driverId);
            if($branch!==null) $gl->where('l.branch_id',$branch);
            $opening = !empty($filters['from_date']) ? (clone $gl)->where('e.entry_date','<',$filters['from_date'])->selectRaw('COALESCE(SUM(l.credit-l.debit),0) balance')->value('balance') : '0.000';
            if(!empty($filters['from_date'])) $gl->where('e.entry_date','>=',$filters['from_date']);
            if(!empty($filters['to_date'])) $gl->where('e.entry_date','<=',$filters['to_date']);
            $totals=(clone $gl)->selectRaw('COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit, CAST(? AS DECIMAL(18,3)) + COALESCE(SUM(l.credit-l.debit),0) closing',[$opening])->first();
            $unmatched=(clone $gl)->whereNotIn('l.journal_entry_id',(clone $all)->where('verified',1)->select('journal_entry_id'))->selectRaw('COUNT(*) line_count, COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit')->first();
            $ledger[]=['account_id'=>(int)$accountId,'account'=>DB::table('accounts')->where('company_id',$company)->where('id',$accountId)->select('account_code','account_name')->first(),
                'opening_payable'=>$opening,'period_debit'=>$totals->debit,'period_credit'=>$totals->credit,
                'closing_payable'=>(string)$totals->closing,
                'unclassified_period_lines'=>(int)$unmatched->line_count,'unclassified_period_debit'=>$unmatched->debit,'unclassified_period_credit'=>$unmatched->credit,
                'exception'=>(int)$unmatched->line_count>0?'UNCLASSIFIED_GL_MOVEMENTS_REVIEW_REQUIRED':'COST_PAYMENT_ALLOCATION_UNAVAILABLE'];
        }
        if(!empty($filters['from_date'])) $all->where('filter_date','>=',$filters['from_date']);
        if(!empty($filters['to_date'])) $all->where('filter_date','<=',$filters['to_date']);
        if(!empty($filters['search'])) $all->where(fn($q)=>$q->where('shipment_number','like','%'.$filters['search'].'%')->orWhere('type_name','like','%'.$filters['search'].'%'));
        $totals=(clone $all)->selectRaw('COUNT(*) total, COALESCE(SUM(CASE WHEN verified=1 THEN amount ELSE 0 END),0) original_liabilities, SUM(CASE WHEN verified=1 THEN 1 ELSE 0 END) verified_count')->first();
        $page=$all->orderBy('filter_date')->orderBy('id')->paginate($filters['per_page']??25,['*'],'page',$filters['page']??1);
        $page->getCollection()->transform(function($r) use($driverId,$company) {
            $r->evidence_status=$r->verified?'A_VERIFIED_ORIGINAL':($r->direct_paid?'B_VERIFIED_DIRECT_PAYMENT':'D_INCOMPLETE_OR_CONFLICTING');
            $r->category=$r->verified?'C_HISTORICAL_PAYMENT_UNKNOWN':($r->direct_paid?'B_DIRECT_PAID_NOT_PAYABLE':'D_INCOMPLETE_OR_CONFLICTING');
            $r->payment_eligibility='BLOCKED_REVIEW_REQUIRED';$r->paid_amount=null;$r->remaining_amount=null;
            $r->original_account=$r->verified?DB::table('journal_entry_lines as l')->join('accounts as a','a.id','=','l.account_id')->where('l.journal_entry_id',$r->journal_entry_id)->where('l.company_id',$company)->where('a.company_id',$company)->where('l.party_type','DRIVER')->where('l.party_id',$driverId)->where('l.credit','>',0)->select('a.id','a.account_code','a.account_name')->first():null;
            if(!$r->verified) $r->journal_entry_id=null;
            return $r;
        });
        return ['driver'=>$driver,'costs'=>$page,'totals'=>$totals,'reconciliation'=>$ledger,'settlement_enabled'=>false,
            'notice'=>'الالتزام الأصلي لا يثبت المتبقي. جميع التكاليف تحتاج مراجعة السداد السابق قبل أي صرف. السلف خارج هذا الكشف.'];
    }
}
