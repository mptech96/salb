<?php
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$workerMigration=require __DIR__.'/../../docs/erp-baseline/proposed-migrations/create_worker_operation_requests.php';
$workerMigration->up();
rejects(fn()=>$workerMigration->up(),'WORKER request migration rerun refuses existing evidence schema');
$workerMigration->down();$workerMigration->up();
check(DB::getSchemaBuilder()->hasTable('worker_operation_requests'),'WORKER request empty rollback and reapply');
$requests=app(\App\Services\Payroll\IsolatedWorkerCreationRequest::class);
$payload=$loanBody;
$payload['amount']='3.125';
$before=$workerCounts();
$created=$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_TEST_0001',$payload);
$retry=$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_TEST_0001',$payload);
check($created===$retry && DB::table('worker_loans')->count()===$before[0]+1
    && DB::table('vouchers')->count()===$before[2]+1 && DB::table('journal_entries')->count()===$before[3]+1,
    'WORKER request identical retry same result one real posting');
$payload['amount']='3.126';$before=$workerCounts();
rejects(fn()=>$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_TEST_0001',$payload),'WORKER request changed amount rejected');
check($workerCounts()===$before,'WORKER request mismatch no financial effects');
$payload['amount']='3.125';
$second=$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_TEST_0002',$payload);
check($second['id']!==$created['id'],'WORKER new request identical amount is legitimate distinct loan');
$commissionPayload=['commission_date'=>'2026-10-10','amount'=>'2.500','status'=>'APPROVED'];
$c=$requests->create('CREATE_COMMISSION',$w,'WORKER_COMMISSION_0001',$commissionPayload);
$before=$workerCounts();
check($requests->create('CREATE_COMMISSION',$w,'WORKER_COMMISSION_0001',$commissionPayload)===$c
    && $workerCounts()===$before,'WORKER commission request replay has no extra accrual');
$row=(array)DB::table('worker_operation_requests')->first();unset($row['id']);
rejects(fn()=>DB::table('worker_operation_requests')->insert($row),'WORKER request unique company operation key');
rejects(fn()=>DB::table('worker_operation_requests')->update(['request_hash'=>str_repeat('0',64)]),'WORKER request immutable update');
rejects(fn()=>DB::table('worker_operation_requests')->delete(),'WORKER request immutable delete');
rejects(fn()=>$workerMigration->down(),'WORKER populated request migration rollback blocked');
$before=$workerCounts();$requestCount=DB::table('worker_operation_requests')->count();
DB::unprepared("CREATE TRIGGER worker_request_test_fault BEFORE INSERT ON worker_operation_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TEST request evidence failure'");
rejects(fn()=>$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_FAULT_01',$payload),'WORKER request evidence fault fails entire operation');
DB::unprepared('DROP TRIGGER worker_request_test_fault');
check($workerCounts()===$before && DB::table('worker_operation_requests')->count()===$requestCount,
    'WORKER request fault rolls back loan voucher journal sequence');
$retry=$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_FAULT_01',$payload);
check(isset($retry['journal_entry_id']),'WORKER failed request retry posts once');
$raceBody=['operation'=>'CREATE_LOAN','worker'=>$w,'key'=>'WORKER_RACE_REQUEST_01','body'=>$payload];
$raceCommand=['--worker-request-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($raceBody))];
$before=$workerCounts();
$parallel=$race([$raceCommand,$raceCommand]);
check($parallel[0]['ok'] && $parallel[1]['ok'] && $parallel[0]['journal']===$parallel[1]['journal'],
    'WORKER request concurrent retry same journal');
check(DB::table('worker_loans')->count()===$before[0]+1 && DB::table('vouchers')->count()===$before[2]+1
    && DB::table('journal_entries')->count()===$before[3]+1,'WORKER request concurrent one loan voucher journal');
$pending=$requests->create('CREATE_COMMISSION',$w,'WORKER_PENDING_REQUEST_01',['commission_date'=>'2026-10-10','amount'=>'4.125','status'=>'PENDING']);
$approved=$requests->transition('APPROVE_COMMISSION',$pending['id'],'WORKER_APPROVE_REQUEST_01');$before=$workerCounts();
check($requests->transition('APPROVE_COMMISSION',$pending['id'],'WORKER_APPROVE_REQUEST_01')===$approved && $workerCounts()===$before,'WORKER approval same request returns original accrual');
$payment=$requests->transition('PAY_COMMISSION',$pending['id'],'WORKER_PAYMENT_REQUEST_01');$before=$workerCounts();
check($requests->transition('PAY_COMMISSION',$pending['id'],'WORKER_PAYMENT_REQUEST_01')===$payment && $workerCounts()===$before,'WORKER payment same request returns original settlement');
rejects(fn()=>$requests->transition('PAY_COMMISSION',$pending['id'],'WORKER_PAYMENT_REQUEST_01',['payment_method'=>'BANK','financial_account_id'=>$bankFA]),'WORKER payment changed financial input rejected');
rejects(fn()=>$requests->create('CREATE_LOAN',$wf,'WORKER_FOREIGN_REQUEST_01',$payload),'WORKER request foreign worker rejected');
rejects(fn()=>$requests->create('CREATE_LOAN',$w,'WORKER_ACTOR_REQUEST_01',[...$payload,'created_by'=>$wUser]),'WORKER request client actor rejected');
DB::table('subscriptions')->where('id',$sub)->update(['status'=>'SUSPENDED']);
rejects(fn()=>$requests->create('CREATE_LOAN',$w,'WORKER_REQUEST_TEST_0001',$payload),'WORKER request READ_ONLY cannot replay financial action');
rejects(fn()=>DB::transaction(fn()=>app(\App\Services\Payroll\IsolatedWorkerLoanSettlement::class)->record($created['id'],'RECEIPT',1,'1.000')),'WORKER allocation READ_ONLY rejected before mutation');
DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);
$pending=$requests->create('CREATE_COMMISSION',$w,'WORKER_TRANSITION_RACE_01',['commission_date'=>'2026-10-10','amount'=>'5.250','status'=>'PENDING']);
foreach(['APPROVE_COMMISSION','PAY_COMMISSION']as$operation){
    $body=['operation'=>$operation,'commission'=>$pending['id'],'key'=>'WORKER_TRANSITION_'.$operation,'body'=>[]];
    $cmd=['--worker-request-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($body))];
    $before=$workerCounts();$parallel=$race([$cmd,$cmd]);
    check($parallel[0]['ok']&&$parallel[1]['ok']&&$parallel[0]['journal']===$parallel[1]['journal'],'WORKER concurrent '.$operation.' same result');
    check(DB::table('journal_entries')->count()===$before[3]+1 && DB::table('vouchers')->count()===$before[2]+($operation==='PAY_COMMISSION'?1:0),'WORKER concurrent '.$operation.' one financial effect');
}
