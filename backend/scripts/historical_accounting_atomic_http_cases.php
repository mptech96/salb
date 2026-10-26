<?php
use App\Services\Accounting\InvoiceAllocationService;
use Illuminate\Support\Facades\DB;
// Only loaded by the owned MariaDB runner. Real Sanctum and tenant middleware.
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$bindOwner=function()use($uid,$app){\Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));};
$counts=fn()=>[DB::table('vouchers')->count(),DB::table('journal_entries')->count(),DB::table('voucher_invoice_allocations')->count()];
foreach(['SALE','PURCHASE']as$kind){
    $bindOwner();$table=$kind==='SALE'?'customers':'suppliers';$party=$kind==='SALE'?$customer:$supplier;$mapping=$kind==='SALE'?['ar','new_ar']:['ap','new_ap'];
    $sources=[];$snaps=[];
    foreach($mapping as$map){DB::table($table)->where('id',$party)->update(['ledger_account_id'=>$gl[$map]]);$bindOwner();$source=$realDraft($kind,[['item_id'=>$item,'quantity'=>10,'unit_price'=>10,'vat_percent'=>0]]);$ok($http($owner,($kind==='SALE'?'sales':'purchase').'-invoices/'.$source.'/post'),'ATOMIC '.$kind.' source post');$sources[]=$source;$snaps[]=$snapshotFor($kind,$source);}
    check($snaps[0]->party_account_id!==$snaps[1]->party_account_id,'ATOMIC distinct historical GL '.$kind);
    DB::table($table)->where('id',$party)->update(['ledger_account_id'=>$gl[$mapping[0]]]);
    $payload=['key'=>'ATOMIC-'.$kind,'invoice_type'=>$kind,'voucher'=>['voucher_type_id'=>$kind==='SALE'?1:2,'voucher_date'=>'2026-10-10','reference_type'=>$kind==='SALE'?'CUSTOMER':'SUPPLIER','reference_id'=>$party,'amount'=>'80.000','financial_account_id'=>$fa,'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>'80.000'],'allocations'=>[['invoice_id'=>$sources[0],'amount'=>'40.000'],['invoice_id'=>$sources[1],'amount'=>'40.000']]];
    // The same external key is valid for different company operations.
    $payload['key']='ATOMIC-COMMON';
    $legacyNext=DB::table('sulb_document_sequences')->where('company_id',$cid)->where('branch_id',0)->where('document_type',$kind==='SALE'?'RECEIPT_VOUCHER':'PAYMENT_VOUCHER')->where('document_year',2026)->value('next_number')??1;
    $legacyId=$voucher($kind==='SALE',1);$legacyNumber=($kind==='SALE'?'REC':'PAY').'-2026-'.str_pad((string)$legacyNext,6,'0',STR_PAD_LEFT);DB::table('vouchers')->where('id',$legacyId)->update(['voucher_number'=>$legacyNumber,'notes'=>'Untouched legacy fixture']);
    $legacyBefore=(array)DB::table('vouchers')->where('id',$legacyId)->first();
    $before=$counts();$r=$ok($http($owner,'vouchers/atomic',$payload),'ATOMIC '.$kind.' create allocate post');$v=$r['id'];$j=$r['journal_entry_id'];
    check(DB::table('vouchers')->where('id',$v)->value('voucher_number')!==$legacyNumber && DB::table('vouchers')->where('company_id',$cid)->where('voucher_number',$legacyNumber)->count()===1,'METADATA numbering skips existing legacy number');
    check((array)DB::table('vouchers')->where('id',$legacyId)->first()===$legacyBefore && !DB::table('voucher_operation_requests')->where('voucher_id',$legacyId)->exists(),'METADATA legacy voucher unchanged without automatic backfill');
    check($counts()===[$before[0]+1,$before[1]+1,$before[2]+2],'ATOMIC exactly one voucher journal two allocations');
    $requestRow=DB::table('voucher_operation_requests')->where('voucher_id',$v)->first();
    check($requestRow && $requestRow->operation===($kind==='SALE'?'CREATE_RECEIPT':'CREATE_PAYMENT') && (int)$requestRow->journal_entry_id===$j,'METADATA permanent scoped request result');
    check(DB::table('vouchers')->where('id',$v)->value('notes')===null,'METADATA notes contain no technical fingerprint');
    check(preg_match('/^(REC|PAY)-2026-[0-9]{6,}$/D',DB::table('vouchers')->where('id',$v)->value('voucher_number'))===1,'METADATA existing sequence numbering');
    rejects(fn()=>DB::table('voucher_operation_requests')->where('id',$requestRow->id)->update(['request_hash'=>str_repeat('a',64)]),'METADATA immutable request');
    rejects(fn()=>DB::table('voucher_operation_requests')->where('id',$requestRow->id)->delete(),'METADATA request deletion denied');
    $duplicate=(array)$requestRow;unset($duplicate['id']);rejects(fn()=>DB::table('voucher_operation_requests')->insert($duplicate),'METADATA duplicate request denied');
    $forged=$duplicate;$forged['company_id']=$foreign;$forged['request_key'].='-forged';rejects(fn()=>DB::table('voucher_operation_requests')->insert($forged),'METADATA cross-company request denied');
    foreach($snaps as$s){check(DB::table('journal_entry_lines')->where('journal_entry_id',$j)->where('account_id',$s->party_account_id)->where($kind==='SALE'?'credit':'debit','40.000')->exists(),'ATOMIC historical per-invoice GL');$bindOwner();check(app(InvoiceAllocationService::class)->outstandingDecimal($s)==='60.000','ATOMIC partial outstanding');}
    $before=$counts();check($ok($http($owner,'vouchers/atomic',$payload),'ATOMIC retry')['id']===$v&&$counts()===$before,'ATOMIC retry no duplicates');
    $changed=$payload;$changed['voucher']['amount']='81.000';check($http($owner,'vouchers/atomic',$changed)->getStatusCode()===422&&$counts()===$before,'ATOMIC changed retry denied');
    foreach(['ACTIVE','TRIAL','SUSPENDED','EXPIRED','CANCELLED']as$status){
        DB::table('subscriptions')->where('id',$sub)->update(['status'=>$status]);$test=$payload;$test['key'].='-'.$status;$test['voucher']['amount']='1.000';$test['voucher']['foreign_amount']='1.000';$test['allocations']=[['invoice_id'=>$sources[0],'amount'=>'1.000']];
        $response=$http($owner,'vouchers/atomic',$test);check($response->getStatusCode()===(in_array($status,['ACTIVE','TRIAL'],true)?200:403),'ATOMIC new write subscription '.$status);
    }
    DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);
    DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','accounting')->update(['is_enabled'=>0]);$before=$counts();check($http($owner,'vouchers/atomic',$payload)->getStatusCode()===403&&$counts()===$before,'ATOMIC accounting disabled denies creation');
    DB::table('subscriptions')->where('id',$sub)->update(['status'=>'SUSPENDED']);$before=$counts();$rev=$ok($http($owner,'vouchers/'.$v.'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED approved correction SUSPENDED']),'ATOMIC corrective SUSPENDED accounting disabled')['journal_entry_id'];check($counts()[1]===$before[1]+1,'ATOMIC corrective exactly one inverse');
    DB::table('subscriptions')->where('id',$sub)->update(['status'=>'EXPIRED']);$before=$counts();check($http($owner,'vouchers/'.$v.'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED repeated correction'])->getStatusCode()===422&&$counts()===$before,'ATOMIC EXPIRED repeated reversal rejected');
    check(DB::table('journal_entries')->where('id',$rev)->value('reversal_of_id')==$j,'ATOMIC reversal linked to actual GL');
    $audit=DB::table('voucher_correction_audits')->where('voucher_id',$v)->first();
    check($audit && (int)$audit->original_journal_id===$j && (int)$audit->inverse_journal_id===$rev && $audit->actor_name===DB::table('users')->where('id',$uid)->value('name') && $audit->reason==='ISOLATED approved correction SUSPENDED' && $audit->occurred_at!==null,'METADATA corrective audit actual actor reason references time');
    rejects(fn()=>DB::table('voucher_correction_audits')->where('id',$audit->id)->delete(),'METADATA audit deletion denied');
    rejects(fn()=>DB::table('voucher_correction_audits')->where('id',$audit->id)->update(['reason'=>'changed reason']),'METADATA audit mutation denied');
    $forgedAudit=(array)$audit;unset($forgedAudit['id']);$forgedAudit['company_id']=$foreign;rejects(fn()=>DB::table('voucher_correction_audits')->insert($forgedAudit),'METADATA foreign correction evidence denied');
    rejects(fn()=>DB::table('vouchers')->where('id',$v)->delete(),'METADATA financial source deletion denied');
    rejects(fn()=>$metadataMigration->down(),'METADATA populated rollback refused');
    foreach(DB::table('journal_entry_lines')->where('journal_entry_id',$j)->get()as$line){check(DB::table('journal_entry_lines')->where('journal_entry_id',$rev)->where('account_id',$line->account_id)->where('debit',$line->credit)->where('credit',$line->debit)->exists(),'ATOMIC exact opposite journal line');}
    foreach($snaps as$s){$bindOwner();$expected=$s->id===$snaps[0]->id?'98.000':'100.000';check(app(InvoiceAllocationService::class)->outstandingDecimal($s)===$expected,'ATOMIC reverse reconciles outstanding including other valid settlements');}
    DB::table('subscriptions')->where('id',$sub)->update(['status'=>'CANCELLED']);check($http($owner,'vouchers/'.$v.'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED correction'])->getStatusCode()===403,'ATOMIC CANCELLED correction remains blocked');
    DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','accounting')->update(['is_enabled'=>1]);
    foreach([$reader,$support['plain_text_token'],$pt]as$token){$before=$counts();check($http($token,'vouchers/atomic',$payload)->getStatusCode()===403,'ATOMIC unauthorized support platform creation denied');check($http($token,'vouchers/'.$v.'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED correction'])->getStatusCode()===403&&$counts()===$before,'ATOMIC unauthorized support platform corrective denied');}
    $bad=$payload;$bad['key'].='-MISMATCH';$bad['voucher']['amount']='81.000';$bad['voucher']['foreign_amount']='81.000';$before=$counts();check($http($owner,'vouchers/atomic',$bad)->getStatusCode()===422&&$counts()===$before,'ATOMIC failure after voucher insertion rolls back all writes');
    // TenantResourceScopeService deliberately returns 404 before the controller.
    $bad=$payload;$bad['key'].='-FOREIGN';$bad['voucher']['financial_account_id']=$foreignFa;$before=$counts();check($http($owner,'vouchers/atomic',$bad)->getStatusCode()===404&&$counts()===$before,'ATOMIC foreign account denied');
    $bad=$payload;$bad['key'].='-FORGED';$bad['voucher']['company_id']=$foreign;check($http($owner,'vouchers/atomic',$bad)->getStatusCode()===422&&$counts()===$before,'ATOMIC nested identity injection denied');
    // Complete both invoices with their real remaining amounts, then reverse full
    // settlement and confirm GL + projection again. No data backfill involved.
    $final=$payload;$final['key'].='-FINAL';$final['voucher']['amount']='198.000';$final['voucher']['foreign_amount']='198.000';$final['allocations'][0]['amount']='98.000';$final['allocations'][1]['amount']='100.000';$f=$ok($http($owner,'vouchers/atomic',$final),'ATOMIC full payment');
    foreach($snaps as$s){$bindOwner();check(app(InvoiceAllocationService::class)->outstandingDecimal($s)==='0.000','ATOMIC fully paid outstanding');}
    $ok($http($owner,'vouchers/'.$f['id'].'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED full payment correction']),'ATOMIC full reversal');
    check($http($owner,'vouchers/'.$f['id'].'/reverse',['date'=>'2026-10-10','reason'=>''])->getStatusCode()===422,'ATOMIC empty corrective reason rejected');
    $bindOwner();
    rejects(fn()=>app(\App\Services\Accounting\AtomicInvoiceVoucherService::class)->create($foreign,$foreignBid,$uid,$payload['voucher'],[$snaps[0]->id=>'1.000'],'DIRECT-FORGED'),'ATOMIC direct service foreign scope denied');
    $parallelInput=$payload['voucher'];$parallelInput['amount']='75.000';$parallelInput['foreign_amount']='75.000';
    $cmd=function($key)use($parallelInput,$snaps,$name,$cid,$bid,$uid){return ['--atomic-worker',$name,$cid,$bid,$uid,base64_encode(json_encode(['voucher'=>$parallelInput,'allocations'=>[$snaps[1]->id=>'75.000'],'key'=>$key]))];};
    $before=$counts();$parallel=$race([$cmd('ATOMIC-RACE-'.$kind),$cmd('ATOMIC-RACE-'.$kind)]);
    check($parallel[0]['ok']&&$parallel[1]['ok']&&$parallel[0]['journal']===$parallel[1]['journal']&&$counts()===[$before[0]+1,$before[1]+1,$before[2]+1],'ATOMIC concurrent retry one entire settlement');
    check(DB::table('voucher_operation_requests')->where('company_id',$cid)->where('request_key','ATOMIC-RACE-'.$kind)->count()===1,'METADATA concurrent retry one durable request');
    $parallel=$race([$cmd('ATOMIC-OVER-RACE-A-'.$kind),$cmd('ATOMIC-OVER-RACE-B-'.$kind)]);
    check(count(array_filter($parallel,fn($r)=>$r['ok']))===0,'ATOMIC concurrent oversettlement both rejected');
    $bindOwner();$fault=$payload;$fault['key']='METADATA-FAULT-'.$kind;$fault['voucher']['amount']='1.000';$fault['voucher']['foreign_amount']='1.000';$fault['voucher']['notes']='User business note only';$fault['allocations']=[['invoice_id'=>$sources[1],'amount'=>'1.000']];
    $before=$counts();$requests=DB::table('voucher_operation_requests')->count();$sequences=DB::table('sulb_document_sequences')->orderBy('id')->get()->toJson();
    DB::unprepared("CREATE TRIGGER metadata_fault BEFORE INSERT ON voucher_operation_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected request fault'");
    try{check($http($owner,'vouchers/atomic',$fault)->getStatusCode()===422 && $counts()===$before && DB::table('voucher_operation_requests')->count()===$requests && DB::table('sulb_document_sequences')->orderBy('id')->get()->toJson()===$sequences,'METADATA request failure rolls back voucher journal allocations and numbering');}finally{DB::unprepared('DROP TRIGGER metadata_fault');}
    $r=$ok($http($owner,'vouchers/atomic',$fault),'METADATA fault retry successful');
    check(DB::table('vouchers')->where('id',$r['id'])->value('notes')==='User business note only','METADATA preserves user notes exactly');
    $before=$counts();$audits=DB::table('voucher_correction_audits')->count();
    DB::unprepared("CREATE TRIGGER metadata_audit_fault BEFORE INSERT ON voucher_correction_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit fault'");
    try{check($http($owner,'vouchers/'.$r['id'].'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED audit rollback test'])->getStatusCode()===422 && $counts()===$before && DB::table('voucher_correction_audits')->count()===$audits && !DB::table('journal_entries')->where('reversal_of_id',$r['journal_entry_id'])->exists(),'METADATA audit failure rolls back inverse and allocation reversal');}finally{DB::unprepared('DROP TRIGGER metadata_audit_fault');}
    $ok($http($owner,'vouchers/'.$r['id'].'/reverse',['date'=>'2026-10-10','reason'=>'ISOLATED audit rollback test']),'METADATA corrective retry after rollback');
}
$bindOwner();
check(DB::table('voucher_operation_requests')->where('company_id',$cid)->where('request_key','ATOMIC-COMMON')->count()===2,'METADATA same key isolated by operation');
