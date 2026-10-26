<?php
// Owned MariaDB process only. Actual legacy controller/engine plus atomic service.
use Illuminate\Support\Facades\DB;
use App\Services\Accounting\AtomicInvoiceVoucherService;
use App\Services\SulbDocumentSequenceService;
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$owner=\App\Models\User::findOrFail($uid)->createToken('ISOLATED NUMBERING HTTP')->plainTextToken;
DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);
DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','accounting')->update(['is_enabled'=>1]);
\Illuminate\Support\Facades\Route::post('api/vouchers',[\App\Http\Controllers\Api\VoucherController::class,'store'])->middleware($chain);
$oldOk=function($body,string $label)use($http,$owner){$r=$http($owner,'vouchers',$body);check($r->getStatusCode()===201,$label);if($r->getStatusCode()!==201)throw new RuntimeException($label.' HTTP '.$r->getStatusCode().' '.$r->getContent());return json_decode($r->getContent(),true);};
$numberCounts=fn()=>[DB::table('vouchers')->count(),DB::table('journal_entries')->count(),DB::table('voucher_operation_requests')->count()];
$sequenceState=fn()=>DB::table('sulb_document_sequences')->where('company_id',$cid)->orderBy('id')->get()->toJson();
$makeOld=function($type)use($bid,$customer,$supplier,$fa){return ['branch_id'=>$bid,'voucher_type_id'=>$type==='RECEIPT'?1:2,'voucher_date'=>'2026-10-10','reference_type'=>$type==='RECEIPT'?'CUSTOMER':'SUPPLIER','reference_id'=>$type==='RECEIPT'?$customer:$supplier,'amount'=>'1.000','financial_account_id'=>$fa,'payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>'1.000'];};
foreach(['RECEIPT'=>'SALE','PAYMENT'=>'PURCHASE']as$type=>$kind){
    $bindOwner();$source=$realDraft($kind,[['item_id'=>$item,'quantity'=>10,'unit_price'=>10,'vat_percent'=>0]]);$posting->post($cid,$bid,$kind,$source,$uid);$s=$snapshotFor($kind,$source);
    $body=$makeOld($type);$old=$oldOk($body,'NUMBERING real legacy '.$type.' HTTP posting');
    $input=$body;unset($input['branch_id']);
    $bindOwner();$atomic=app(AtomicInvoiceVoucherService::class);$key='NUMBER-'.$type;$new=$atomic->create($cid,$bid,$uid,$input,[$s->id=>'1.000'],$key);
    check($old['id']!==$new['id']&&$old['journal_entry_id']!==$new['journal_entry_id'],'NUMBERING separate old atomic '.$type.' results');
    $seq=$sequenceState();$before=$numberCounts();check($atomic->create($cid,$bid,$uid,$input,[$s->id=>'1.000'],$key)===$new&&$numberCounts()===$before&&$sequenceState()===$seq,'NUMBERING completed retry reserves no number '.$type);
    $changed=$input;$changed['amount']='2.000';$changed['foreign_amount']='2.000';rejects(fn()=>$atomic->create($cid,$bid,$uid,$changed,[$s->id=>'2.000'],$key),'NUMBERING changed request rejected '.$type);
    check($numberCounts()===$before&&$sequenceState()===$seq,'NUMBERING changed request leaves counters unchanged '.$type);
    $oldCommand=['--legacy-voucher-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($body))];
    $newCommand=['--atomic-worker',$name,$cid,$bid,$uid,base64_encode(json_encode(['voucher'=>$input,'allocations'=>[$s->id=>'1.000'],'key'=>'MIXED-'.$type]))];
    $before=$numberCounts();$mixed=$race([$oldCommand,$newCommand]);
    check($mixed[0]['ok']&&$mixed[1]['ok']&&$mixed[0]['journal']!==$mixed[1]['journal']&&$numberCounts()===[$before[0]+2,$before[1]+2,$before[2]+1],'NUMBERING concurrent real legacy atomic '.$type.' unique results');
    $numbers=DB::table('vouchers')->whereIn('journal_entry_id',[$mixed[0]['journal'],$mixed[1]['journal']])->pluck('voucher_number');check($numbers->count()===2&&$numbers->unique()->count()===2,'NUMBERING mixed '.$type.' no duplicate number');
    $bad=$body;$bad['reference_type']='ACCOUNT';$bad['reference_id']=999999999;
    $before=$numberCounts();$seq=$sequenceState();$rejected=$http($owner,'vouchers',$bad);
    check($rejected->getStatusCode()>=400&&$numberCounts()===$before&&$sequenceState()===$seq,'NUMBERING legacy posting failure rolls back reservation '.$type);
    $oldOk($body,'NUMBERING legacy retry after failure '.$type);
    $bindOwner();$before=$numberCounts();$seq=$sequenceState();
    rejects(fn()=>$atomic->create($cid,$bid,$uid,$input,[$s->id=>'2.000'],'FAULT-'.$type),'NUMBERING atomic failure after reservation '.$type);
    check($numberCounts()===$before&&$sequenceState()===$seq,'NUMBERING atomic rollback no orphan or consumed number '.$type);
    $retry=$atomic->create($cid,$bid,$uid,$input,[$s->id=>'1.000'],'FAULT-'.$type);
    check(DB::table('vouchers')->where('id',$retry['id'])->value('journal_entry_id')===$retry['journal_entry_id'],'NUMBERING atomic retry after rollback '.$type);
}
// Manual numbers participate in the same company lock. Exactly one competing request succeeds.
$manual=$makeOld('RECEIPT');$manual['voucher_number']='REC-MANUAL-NUMBER-RACE';
$command=['--legacy-voucher-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($manual))];$before=$numberCounts();$r=$race([$command,$command]);
check(count(array_filter($r,fn($x)=>$x['ok']))===1&&$numberCounts()===[$before[0]+1,$before[1]+1,$before[2]],'NUMBERING manual competing requests one voucher one journal');
check(DB::table('vouchers')->where('company_id',$cid)->where('voucher_number',$manual['voucher_number'])->count()===1,'NUMBERING manual unique company scope');
$manual['voucher_number']=strtolower($manual['voucher_number']).' ';$before=$numberCounts();check($http($owner,'vouchers',$manual)->getStatusCode()===422&&$numberCounts()===$before,'NUMBERING manual matches database case trailing-space collation');
// Database constraint, not just the application existence check.
$row=(array)DB::table('vouchers')->where('company_id',$cid)->whereNotNull('journal_entry_id')->first();unset($row['id']);
rejects(fn()=>DB::table('vouchers')->insert($row),'NUMBERING database rejects direct duplicate insert');
rejects(fn()=>DB::table('vouchers')->where('company_id',$cid)->orderByDesc('id')->limit(1)->update(['voucher_number'=>$row['voucher_number']]),'NUMBERING database rejects direct duplicate update');
rejects(fn()=>$numberMigration->down(),'NUMBERING populated constraint rollback refused');
rejects(fn()=>app(SulbDocumentSequenceService::class)->reserveVoucher($cid,'RECEIPT','2026-10-10'),'NUMBERING reservation without financial transaction denied');
// Two branches share the company/year series, not a branch-specific visible number.
$otherBranch=fixture('branches',['company_id'=>$cid,'branch_name'=>'NUMBER SECOND BRANCH','is_active'=>1]);
$wideUser=fixture('users',['company_id'=>$cid,'branch_id'=>null,'username'=>'NUMBER_WIDE','name'=>'NUMBER WIDE TEST','is_active'=>1,'password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);
fixture('user_roles',['company_id'=>$cid,'user_id'=>$wideUser,'role_id'=>$role,'is_active'=>1]);
$otherFa=fixture('financial_accounts',['company_id'=>$cid,'branch_id'=>$otherBranch,'account_code'=>'NUMBER-SECOND','account_name'=>'TEST SECOND CASH','account_type'=>'CASH','currency_code'=>'SAR','gl_account_id'=>$gl['cash'],'is_active'=>1]);
$body=$makeOld('RECEIPT');$body['branch_id']=$otherBranch;$body['financial_account_id']=$otherFa;
\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($wideUser));$source=$realDraft('SALE',[['item_id'=>$item,'quantity'=>10,'unit_price'=>10,'vat_percent'=>0]]);DB::table('sales_invoices')->where('id',$source)->update(['branch_id'=>$otherBranch]);$posting->post($cid,$otherBranch,'SALE',$source,$wideUser);$s=$snapshotFor('SALE',$source);
$input=$body;unset($input['branch_id']);
$mixed=$race([['--legacy-voucher-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($makeOld('RECEIPT')))],['--atomic-worker',$name,$cid,$otherBranch,$wideUser,base64_encode(json_encode(['voucher'=>$input,'allocations'=>[$s->id=>'1.000'],'key'=>'BRANCH-MIXED']))]]);
check($mixed[0]['ok']&&$mixed[1]['ok']&&DB::table('vouchers')->whereIn('journal_entry_id',[$mixed[0]['journal'],$mixed[1]['journal']])->pluck('voucher_number')->unique()->count()===2,'NUMBERING concurrent branches share company series');
check(!DB::table('sulb_document_sequences')->where('company_id',$cid)->whereIn('document_type',['RECEIPT_VOUCHER','PAYMENT_VOUCHER'])->where('branch_id','<>',0)->exists(),'NUMBERING all new voucher reservations use company-wide key');
// Preserve old branch sequence rows; legacy next() callers are redirected, not renumbered.
DB::table('sulb_document_sequences')->insert(['company_id'=>$cid,'branch_id'=>$otherBranch,'document_type'=>'RECEIPT_VOUCHER','document_year'=>2026,'next_number'=>1]);
$seq=$sequenceState();DB::beginTransaction();
try{$number=app(SulbDocumentSequenceService::class)->next($cid,$otherBranch,'RECEIPT_VOUCHER','2026-10-10','REC');}
finally{DB::rollBack();}
check($sequenceState()===$seq,'NUMBERING next compatibility rollback preserves counters');
check(DB::table('sulb_document_sequences')->where('company_id',$cid)->where('branch_id',$otherBranch)->where('document_type','RECEIPT_VOUCHER')->value('next_number')===1,'NUMBERING old branch sequence untouched and next redirected');
// Independent company uses the same manual number safely (not a globally unique number).
$second=fixture('companies',['company_name'=>'HISTORICAL TEST ONLY '.hash('sha256',$proof),'is_active'=>1]);$secondBranch=fixture('branches',['company_id'=>$second,'branch_name'=>'NUMBER SECOND COMPANY','is_active'=>1]);
$secondUser=fixture('users',['company_id'=>$second,'branch_id'=>$secondBranch,'username'=>'NUMBER_SECOND_OWNER','name'=>'NUMBER SECOND OWNER','is_active'=>1,'password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);fixture('user_roles',['company_id'=>$second,'user_id'=>$secondUser,'role_id'=>$role,'is_active'=>1]);
fixture('company_currencies',['company_id'=>$second,'currency_code'=>'SAR','is_base'=>1,'is_active'=>1]);fixture('company_settings',['company_id'=>$second,'currency_code'=>'SAR','base_currency_code'=>'SAR','currency_decimal_places'=>3]);fixture('financial_years',['company_id'=>$second,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_closed'=>0]);
$secondGl=fixture('accounts',['company_id'=>$second,'account_code'=>'NUMBER-CASH','account_name'=>'TEST CASH','account_type'=>'ASSET','normal_side'=>'DEBIT','is_active'=>1,'is_group'=>0,'allow_posting'=>1]);$secondFa=fixture('financial_accounts',['company_id'=>$second,'branch_id'=>$secondBranch,'account_code'=>'NUMBER-CASH','account_name'=>'TEST CASH','account_type'=>'CASH','currency_code'=>'SAR','gl_account_id'=>$secondGl,'is_active'=>1]);
fixture('subscriptions',['company_id'=>$second,'plan_id'=>$plan,'start_date'=>now()->subDay()->toDateString(),'end_date'=>now()->addMonth()->toDateString(),'status'=>'ACTIVE']);
$one=$makeOld('RECEIPT');$one['voucher_number']='REC-CROSS-COMPANY';$two=$one;$two['branch_id']=$secondBranch;$two['financial_account_id']=$secondFa;$two['reference_type']='ACCOUNT';$two['reference_id']=$secondGl;
$r=$race([['--legacy-voucher-worker',$name,$cid,$bid,$uid,base64_encode(json_encode($one))],['--legacy-voucher-worker',$name,$second,$secondBranch,$secondUser,base64_encode(json_encode($two))]]);
check($r[0]['ok']&&$r[1]['ok']&&DB::table('vouchers')->where('voucher_number','REC-CROSS-COMPANY')->count()===2,'NUMBERING different companies can share a number safely');
check(!DB::table('vouchers')->whereNotNull('company_id')->whereNotNull('voucher_number')->select('company_id','voucher_number')->groupBy('company_id','voucher_number')->havingRaw('COUNT(*)>1')->exists(),'NUMBERING no duplicate company numbers after all races');
// All vouchers generated in this block have one real balanced journal; old raw fixtures are excluded.
$generated=DB::table('vouchers')->where('voucher_date','2026-10-10')->where('voucher_number','like','REC-%')->orWhere(function($q){$q->where('voucher_date','2026-10-10')->where('voucher_number','like','PAY-%');})->get();
check($generated->every(fn($v)=>$v->journal_entry_id!==null),'NUMBERING successful generated vouchers have no orphan');
foreach($generated as$v)check(DB::table('journal_entry_lines')->where('journal_entry_id',$v->journal_entry_id)->sum('debit')===DB::table('journal_entry_lines')->where('journal_entry_id',$v->journal_entry_id)->sum('credit'),'NUMBERING generated journal balanced');
$bindOwner();
