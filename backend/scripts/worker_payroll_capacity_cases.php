<?php
// Included only in the owned MariaDB accounting suite; never a runtime endpoint.
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$bindOwner();
request()->attributes->set('tenant_company_id', $cid);
request()->attributes->set('tenant_branch_id', $bid);
request()->attributes->set('effective_role_code', 'COMPANY_OWNER');
foreach ([99, 100, 101] as $capacity) {
    $headcount = $capacity - 1;
    $runId = fixture('worker_salary_runs', ['company_id'=>$cid, 'branch_id'=>$bid,
        'salary_month'=>[99=>'2026-12-01',100=>'2026-08-01',101=>'2026-09-01'][$capacity], 'run_number'=>'CAPACITY-'.$capacity,
        'status'=>'DRAFT', 'total_amount'=>$headcount * 100]);
    for ($i=0; $i<$headcount; $i++) {
        $workerId=fixture('workers', ['company_id'=>$cid, 'branch_id'=>$bid,
            'worker_name'=>'CAPACITY TEST '.$capacity.' '.$i, 'is_active'=>1]);
        fixture('worker_salary_lines', ['company_id'=>$cid, 'salary_run_id'=>$runId,
            'worker_id'=>$workerId, 'basic_amount'=>'100.000', 'net_salary'=>'100.000']);
    }
    $before=$workerCounts();
    if ($capacity === 101) {
        rejects(fn()=>app(\App\Services\Payroll\PayrollApprover::class)->approve($runId),
            'WORKER payroll 101 preflight rejects');
        check($workerCounts()===$before && DB::table('worker_salary_runs')->where('id',$runId)->value('status')==='DRAFT',
            'WORKER payroll 101 no partial approval journal voucher or number');
        continue;
    }
    $approved=app(\App\Services\Payroll\PayrollApprover::class)->approve($runId);
    check(DB::table('journal_entry_lines')->where('journal_entry_id',$approved['journal_entry_id'])->count()===$capacity,
        'WORKER payroll '.$capacity.' actual approval journal');
    $payment=app(\App\Services\Payroll\PayrollPayment::class)->pay($runId,'CASH',$fa);
    check(DB::table('journal_entry_lines')->where('journal_entry_id',$payment['journal_entry_id'])->count()===$capacity,
        'WORKER payroll '.$capacity.' actual payment journal');
    foreach ([$approved['journal_entry_id'],$payment['journal_entry_id']] as $jid) {
        $j=DB::table('journal_entry_lines')->where('journal_entry_id',$jid);
        check($j->sum('debit')===$j->sum('credit'),'WORKER payroll '.$capacity.' balanced '.$jid);
    }
}
$runId=fixture('worker_salary_runs',['company_id'=>$cid,'branch_id'=>$bid,'salary_month'=>'2026-06-01','run_number'=>'PAYMENT-CAPACITY-101','status'=>'DRAFT','total_amount'=>'10000.000']);
for($i=0;$i<100;$i++){
    $workerId=fixture('workers',['company_id'=>$cid,'branch_id'=>$bid,'worker_name'=>'PAYMENT CAPACITY '.$i,'is_active'=>1]);
    fixture('worker_salary_lines',['company_id'=>$cid,'salary_run_id'=>$runId,'worker_id'=>$workerId,'basic_amount'=>$i===99?'0.000':'100.000','commission_amount'=>$i===99?'100.000':'0.000','net_salary'=>'100.000']);
    if($i===99)$wOk($wCall("workers/$workerId/commissions",['commission_date'=>'2026-06-15','amount'=>'100.000','status'=>'APPROVED']),'WORKER capacity commission actual accrual');
}
$bindOwner();request()->attributes->set('tenant_company_id',$cid);request()->attributes->set('tenant_branch_id',$bid);request()->attributes->set('effective_role_code','COMPANY_OWNER');
app(\App\Services\Payroll\PayrollApprover::class)->approve($runId);
$before=$workerCounts();
rejects(fn()=>app(\App\Services\Payroll\PayrollPayment::class)->pay($runId,'CASH',$fa),'WORKER payment 101 preflight rejects');
check($workerCounts()===$before && DB::table('worker_salary_runs')->where('id',$runId)->value('status')==='APPROVED','WORKER payment 101 retains unpaid approved run without financial effects');
$faultWorker=fixture('workers',['company_id'=>$cid,'branch_id'=>$bid,'worker_name'=>'PAYROLL ROLLBACK TEST','is_active'=>1]);
$faultRun=fixture('worker_salary_runs',['company_id'=>$cid,'branch_id'=>$bid,'salary_month'=>'2026-04-01','run_number'=>'PAYROLL-FAULT','status'=>'DRAFT','total_amount'=>'100.000']);
fixture('worker_salary_lines',['company_id'=>$cid,'salary_run_id'=>$faultRun,'worker_id'=>$faultWorker,'basic_amount'=>'100.000','net_salary'=>'100.000']);
$before=$workerCounts();
DB::unprepared("CREATE TRIGGER payroll_approval_test_fault BEFORE UPDATE ON worker_salary_runs FOR EACH ROW BEGIN IF NEW.status='APPROVED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST after payroll journal'; END IF; END");
rejects(fn()=>app(\App\Services\Payroll\PayrollApprover::class)->approve($faultRun),'WORKER payroll fault after real journal posting');
DB::unprepared('DROP TRIGGER payroll_approval_test_fault');
check($workerCounts()===$before && DB::table('worker_salary_runs')->where('id',$faultRun)->value('status')==='DRAFT'
    && DB::table('worker_salary_runs')->where('id',$faultRun)->value('journal_entry_id')===null,'WORKER payroll rollback leaves no approval or orphan journal');
