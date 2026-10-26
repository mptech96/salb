<?php
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$settlementMigration=require __DIR__.'/../../docs/erp-baseline/proposed-migrations/create_worker_loan_settlements.php';
$settlementMigration->up();
rejects(fn()=>$settlementMigration->up(),'WORKER settlement migration rerun refuses existing evidence schema');
$settlementMigration->down();$settlementMigration->up();
check(DB::getSchemaBuilder()->hasTable('worker_loan_settlements'),'WORKER settlement empty rollback and reapply');
$settlements=app(\App\Services\Payroll\IsolatedWorkerLoanSettlement::class);
$settleWorker=fixture('workers',['company_id'=>$cid,'branch_id'=>$bid,'worker_name'=>'ALLOCATION TEST ONLY','is_active'=>1]);
$body=$loanBody;$body['amount']='1000.000';
$newLoan=$requests->create('CREATE_LOAN',$settleWorker,'WORKER_ALLOCATION_LOAN_01',$body);
$receiptBody=$makeOld('RECEIPT');$receiptBody['reference_type']='WORKER';$receiptBody['reference_id']=$settleWorker;
$receiptBody['amount']='300.000';$receiptBody['foreign_amount']='300.000';
$allocatedReceipt=DB::transaction(function()use($receiptBody,$oldOk,$settlements,$newLoan){
    $r=$oldOk($receiptBody,'WORKER allocated receipt real posting');
    $settlements->record($newLoan['id'],'RECEIPT',$r['id'],'300.000');return $r;
});
$remaining=fn()=>\App\Services\Accounting\HistoricalDecimal::format(
    \App\Services\Accounting\HistoricalDecimal::units('1000.000')-
    \App\Services\Accounting\HistoricalDecimal::units((string)DB::table('worker_loan_settlements')->where('loan_id',$newLoan['id'])->sum('amount')));
check($remaining()==='700.000','WORKER receipt 300 leaves loan 700');
$bindOwner();request()->attributes->set('tenant_company_id',$cid);request()->attributes->set('tenant_branch_id',$bid);request()->attributes->set('effective_role_code','COMPANY_OWNER');
// Proposed partial-deduction plan uses the real journal engine; NOT runtime PayrollApprover activation.
DB::transaction(function()use($cid,$bid,$settleWorker,$wLoan,$wPay,$gl,$settlements,$newLoan,$uid){
    $run=fixture('worker_salary_runs',['company_id'=>$cid,'branch_id'=>$bid,'salary_month'=>'2026-07-01','run_number'=>'ALLOCATION-PROTOTYPE','status'=>'DRAFT','total_amount'=>'800.000']);
    $line=fixture('worker_salary_lines',['company_id'=>$cid,'salary_run_id'=>$run,'worker_id'=>$settleWorker,'basic_amount'=>'1000.000','loan_deduction'=>'200.000','net_salary'=>'800.000']);
    $jid=app(\App\Services\AccountingService::class)->post(['company_id'=>$cid,'branch_id'=>$bid,'source_type'=>'PAYROLL','source_id'=>$run,'entry_date'=>'2026-07-31','currency_code'=>'SAR','exchange_rate'=>1,'created_by'=>$uid,'lines'=>[
        ['account_id'=>$gl['expense'],'debit'=>'1000.000','credit'=>0],
        ['account_id'=>$wLoan,'party_type'=>'WORKER','party_id'=>$settleWorker,'debit'=>0,'credit'=>'200.000'],
        ['account_id'=>$wPay,'party_type'=>'WORKER','party_id'=>$settleWorker,'debit'=>0,'credit'=>'800.000']]]);
    DB::table('worker_salary_runs')->where('id',$run)->update(['status'=>'APPROVED','journal_entry_id'=>$jid]);
    $settlements->record($newLoan['id'],'PAYROLL',$line,'200.000');
});
check($remaining()==='500.000','WORKER receipt then payroll 200 leaves loan 500');
$net=DB::table('journal_entry_lines')->where('company_id',$cid)->where('account_id',$wLoan)->where('party_type','WORKER')->where('party_id',$settleWorker)->sum(DB::raw('debit-credit'));
check((string)$net==='500.000','WORKER allocation detail matches actual advance GL 500');
$before=$workerCounts();$allocBefore=DB::table('worker_loan_settlements')->count();
$over=$receiptBody;$over['amount']='500.001';$over['foreign_amount']='500.001';
rejects(fn()=>DB::transaction(function()use($over,$oldOk,$settlements,$newLoan){$r=$oldOk($over,'WORKER overreceipt provisional posting');$settlements->record($newLoan['id'],'RECEIPT',$r['id'],'500.001');}),
    'WORKER overcollection rejected');
check($workerCounts()===$before && DB::table('worker_loan_settlements')->count()===$allocBefore,'WORKER overcollection rolls back voucher GL numbering allocation');
DB::transaction(function()use($settlements,$newLoan,$allocatedReceipt){$settlements->record($newLoan['id'],'RECEIPT',$allocatedReceipt['id'],'300.000');});
check(DB::table('worker_loan_settlements')->count()===$allocBefore,'WORKER allocation replay no duplicate deduction');
rejects(fn()=>DB::transaction(fn()=>$settlements->record($newLoan['id'],'RECEIPT',$allocatedReceipt['id'],'300.001')),'WORKER changed allocation replay rejected');
rejects(fn()=>DB::transaction(fn()=>$settlements->record($created['id'],'RECEIPT',$allocatedReceipt['id'],'1.000')),'WORKER receipt cannot settle another worker loan');
rejects(fn()=>DB::transaction(fn()=>$settlements->record($newLoan['id'],'RECEIPT',$receipt['id'],'0.500')),'WORKER existing unassigned other-worker receipt not guessed');
rejects(fn()=>DB::table('worker_loan_settlements')->update(['amount'=>'1.000']),'WORKER allocation evidence immutable');
rejects(fn()=>$settlementMigration->down(),'WORKER populated allocation evidence retained');
$foreignLoan=fixture('worker_loans',['company_id'=>$foreign,'branch_id'=>$foreignBid,'worker_id'=>$wf,'loan_date'=>'2026-10-10','amount'=>'1.000']);
rejects(fn()=>DB::transaction(fn()=>$settlements->record($foreignLoan,'RECEIPT',$allocatedReceipt['id'],'1.000')),'WORKER settlement foreign company rejected');
$branchLoan=fixture('worker_loans',['company_id'=>$cid,'branch_id'=>$otherBranch,'worker_id'=>$wb,'loan_date'=>'2026-10-10','amount'=>'1.000']);
rejects(fn()=>DB::transaction(fn()=>$settlements->record($branchLoan,'RECEIPT',$allocatedReceipt['id'],'1.000')),'WORKER settlement foreign branch rejected');
rejects(fn()=>$settlements->record($newLoan['id'],'RECEIPT',$allocatedReceipt['id'],'1.000'),'WORKER allocation requires atomic outer transaction');
$row=(array)DB::table('worker_loan_settlements')->first();unset($row['id'],$row['source_kind'],$row['source_id']);
rejects(fn()=>DB::table('worker_loan_settlements')->insert($row),'WORKER allocation generated source unique prevents nullable duplication');
$row['loan_id']=$foreignLoan;
rejects(fn()=>DB::table('worker_loan_settlements')->insert($row),'WORKER allocation DB scope rejects forged foreign loan');
$before=$workerCounts();$allocBefore=DB::table('worker_loan_settlements')->count();
DB::unprepared("CREATE TRIGGER worker_settlement_test_fault BEFORE INSERT ON worker_loan_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST allocation failure'");
rejects(fn()=>DB::transaction(function()use($receiptBody,$oldOk,$settlements,$newLoan){$r=$oldOk($receiptBody,'WORKER allocation fault provisional receipt');$settlements->record($newLoan['id'],'RECEIPT',$r['id'],'300.000');}),'WORKER allocation fault injected');
DB::unprepared('DROP TRIGGER worker_settlement_test_fault');
check($workerCounts()===$before && DB::table('worker_loan_settlements')->count()===$allocBefore,'WORKER allocation fault full financial rollback');
$raceBody=['loan'=>$newLoan['id'],'company'=>$cid,'branch'=>$bid,'body'=>$receiptBody];
$raceCommand=['--worker-settlement-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($raceBody))];
$before=$workerCounts();$parallel=$race([$raceCommand,$raceCommand]);
if(count(array_filter($parallel,fn($p)=>$p['ok']))!==1)fwrite(STDERR,'WORKER_SETTLEMENT_RACE '.json_encode($parallel).PHP_EOL);
check(count(array_filter($parallel,fn($p)=>$p['ok']))===1,'WORKER concurrent receipts only one fits remaining balance');
check(DB::table('vouchers')->count()===$before[2]+1 && DB::table('journal_entries')->count()===$before[3]+1 && $remaining()==='200.000','WORKER concurrent settlement no extra voucher journal or overcollection');
$mixedReceipt=$receiptBody;$mixedReceipt['amount']='150.000';$mixedReceipt['foreign_amount']='150.000';
$mixedBody=['loan'=>$newLoan['id'],'company'=>$cid,'branch'=>$bid,'body'=>$mixedReceipt];
$payrollBody=['kind'=>'PAYROLL','loan'=>$newLoan['id'],'amount'=>'150.000'];
$before=$workerCounts();
$parallel=$race([
    ['--worker-settlement-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($mixedBody))],
    ['--worker-settlement-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($payrollBody))]]);
check(count(array_filter($parallel,fn($p)=>$p['ok']))===1,'WORKER receipt payroll race one remaining deduction');
check(DB::table('journal_entries')->count()===$before[3]+1 && $remaining()==='50.000','WORKER receipt payroll race full rollback prevents double settlement');
