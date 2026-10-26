<?php
// Executed only inside the owned disposable database runner.
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$auth=\Illuminate\Support\Facades\Auth::class;
$reader=fixture('users',['company_id'=>$cid,'branch_id'=>$bid,'username'=>'TEST-NO-PERMISSION','name'=>'TEST ONLY','is_active'=>1,'password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);
$readerRole=fixture('roles',['role_code'=>'TEST_READER','role_name'=>'TEST READER','is_active'=>1]);fixture('user_roles',['company_id'=>$cid,'user_id'=>$reader,'role_id'=>$readerRole,'is_active'=>1]);
$deniedDraft=$realDraft('SALE',[['item_id'=>$item,'quantity'=>1,'unit_price'=>10,'vat_percent'=>0]]);
$beforeCounts=[DB::table('journal_entries')->count(),DB::table('invoice_accounting_snapshots')->count(),DB::table('voucher_invoice_allocations')->count()];
$auth::setUser(\App\Models\User::findOrFail($reader));
rejects(fn()=>$posting->post($cid,$bid,'SALE',$deniedDraft,$reader,true),'unauthorized actor cannot invoke snapshot service');
rejects(fn()=>$posting->post($cid,$bid,'SALE',$deniedDraft,$uid,true),'UID impersonation rejected');
rejects(fn()=>$allocation->post($cid,$bid,0,[1=>1],'DENIED',$reader),'unauthorized actor cannot invoke allocation service');
rejects(fn()=>$allocation->reverse($cid,$bid,0,$reader,'2026-10-04'),'unauthorized actor cannot invoke reversal service');
rejects(fn()=>$historicalReturns->post($cid,$bid,0,$reader),'unauthorized actor cannot invoke return service');
rejects(fn()=>$allocation->project($fs),'unauthorized actor cannot mutate payment projection');
rejects(fn()=>$lifecycle->post('SALE',$cid,$deniedDraft,$reader,$bid,fn()=>null),'direct lifecycle capture permission bypass rejected');
rejects(fn()=>app(\App\Services\Accounting\EnterpriseAccountingPostingService::class)->postSale($cid,$deniedDraft,$reader,fn()=>null),'direct accounting capture permission bypass rejected');
$auth::setUser(\App\Models\User::findOrFail($uid));request()->attributes->set('is_support_mode',true);request()->attributes->set('support_access_level','READ_ONLY');
rejects(fn()=>$posting->post($cid,$bid,'SALE',$deniedDraft,$uid,true),'READ_ONLY snapshot rejected at service');
rejects(fn()=>$allocation->post($cid,$bid,0,[1=>1],'READONLY',$uid),'READ_ONLY allocation rejected at service');
rejects(fn()=>$historicalReturns->post($cid,$bid,0,$uid),'READ_ONLY return rejected at service');request()->attributes->remove('is_support_mode');request()->attributes->remove('support_access_level');
$secondBranch=fixture('branches',['company_id'=>$cid,'branch_name'=>'TEST OTHER BRANCH']);
rejects(fn()=>$posting->post($cid,$secondBranch,'SALE',$deniedDraft,$uid,true),'branch-limited actor cannot change branch argument');
rejects(fn()=>$posting->post($foreign,$foreignBid,'SALE',$deniedDraft,$uid,true),'actor cannot change company argument');
check($beforeCounts===[DB::table('journal_entries')->count(),DB::table('invoice_accounting_snapshots')->count(),DB::table('voucher_invoice_allocations')->count()],'all permission denials perform zero accounting writes');
$platformRole=fixture('roles',['role_code'=>'SUPER_ADMIN','role_name'=>'TEST PLATFORM','is_active'=>1]);
$platform=fixture('users',['company_id'=>$cid,'branch_id'=>$bid,'username'=>'TEST-PLATFORM','name'=>'TEST PLATFORM','is_active'=>1,'password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);fixture('user_roles',['company_id'=>$cid,'user_id'=>$platform,'role_id'=>$platformRole,'is_active'=>1]);$auth::setUser(\App\Models\User::findOrFail($platform));
rejects(fn()=>$posting->post($cid,$bid,'SALE',$deniedDraft,$platform,true),'platform cannot bypass support restriction through tenant service');$auth::forgetGuards();
rejects(fn()=>$posting->post($cid,$bid,'SALE',$deniedDraft,$uid,true),'unauthenticated direct service call denied');$auth::setUser(\App\Models\User::findOrFail($uid));
// An ordinary role with one existing permission is allowed, but cannot allocate.
$permission=DB::table('permissions')->where('permission_code','sales.post')->value('id');fixture('role_permissions',['role_id'=>$readerRole,'permission_id'=>$permission,'company_id'=>$cid,'is_active'=>1]);
$auth::setUser(\App\Models\User::findOrFail($reader));check($posting->post($cid,$bid,'SALE',$deniedDraft,$reader,true)>0,'explicit sales.post role can invoke snapshot service');
rejects(fn()=>$allocation->post($cid,$bid,0,[1=>1],'NO-VOUCHER-PERM',$reader),'sales permission does not grant voucher permission');$auth::setUser(\App\Models\User::findOrFail($uid));
// Decimal amounts remain exact in SQL SUM, with no silent precision loss.
$decimalId=$realDraft('SALE',[['item_id'=>$item,'quantity'=>3,'unit_price'=>'3.333333','vat_percent'=>15]]);$posting->post($cid,$bid,'SALE',$decimalId,$uid,true);$ds=$snapshotFor('SALE',$decimalId);
foreach(['0.001','0.002','11.497']as$amount){$v=$voucher(true,(float)$amount);$allocation->post($cid,$bid,$v,[$ds->id=>$amount],'DECIMAL-'.$v,$uid);}
check(DB::table('voucher_invoice_allocations')->where('snapshot_id',$ds->id)->sum('base_amount')==='11.500'&&$allocation->outstanding($ds)===0.0,'SQL DECIMAL tiny payments reconcile exactly');
$bad=$voucher(true,0.001);rejects(fn()=>$allocation->post($cid,$bid,$bad,[$ds->id=>'0.0005'],'PRECISION-'.$bad,$uid),'sub-ledger precision payment rejected without silent rounding');
// Actual parallel return retries, then overlapping quantities; drafts are initially
// valid. Competitor is inserted after first draft without modifying posted evidence.
$raceId=$realDraft('SALE',[['item_id'=>$item,'quantity'=>10,'unit_price'=>1,'vat_percent'=>0]]);$posting->post($cid,$bid,'SALE',$raceId,$uid,true);$rl=DB::table('sales_invoice_lines')->where('sales_invoice_id',$raceId)->value('id');
$rr=$returns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$raceId,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$rl,'quantity'=>6]]]);
$parallel=$race([['--return-worker',$name,$cid,$bid,$uid,$rr],['--return-worker',$name,$cid,$bid,$uid,$rr]]);
check($parallel[0]['ok']&&$parallel[1]['ok']&&$parallel[0]['journal']===$parallel[1]['journal'],'parallel same return posts exactly one journal');
$remaining=$returns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$raceId,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$rl,'quantity'=>4]]]);
$header=(array)DB::table('commercial_returns')->where('id',$remaining)->first();unset($header['id']);$header['return_number']='TEST-RACE-COMPETITOR';$competitor=fixture('commercial_returns',$header);
$line=(array)DB::table('commercial_return_lines')->where('return_id',$remaining)->first();unset($line['id']);$line['return_id']=$competitor;fixture('commercial_return_lines',$line);
$parallel=$race([['--return-worker',$name,$cid,$bid,$uid,$remaining],['--return-worker',$name,$cid,$bid,$uid,$competitor]]);
check(count(array_filter($parallel,fn($r)=>$r['ok']))===1,'parallel returns cannot exceed original quantity');
check((float)DB::table('commercial_returns')->where('source_invoice_id',$raceId)->where('return_type','SALES_RETURN')->where('document_status','POSTED')->sum('base_total_amount')===10.0,'return race has no excess amount or duplicate journal');
// Inject failure after real voucher journal creation: no allocation or voucher state survives.
$faultId=$realDraft('SALE',[['item_id'=>$item,'quantity'=>1,'unit_price'=>50,'vat_percent'=>0]]);$posting->post($cid,$bid,'SALE',$faultId,$uid,true);$faultSnap=$snapshotFor('SALE',$faultId);$faultVoucher=$voucher(true,50);$before=DB::table('journal_entries')->count();
DB::unprepared("CREATE TRIGGER trg_test_alloc_fault BEFORE INSERT ON voucher_invoice_allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED allocation fault'");
rejects(fn()=>$allocation->post($cid,$bid,$faultVoucher,[$faultSnap->id=>'50.000'],'FAULT-'.$faultVoucher,$uid),'allocation insert failure rolls back real journal');DB::unprepared('DROP TRIGGER trg_test_alloc_fault');
check(DB::table('journal_entries')->count()===$before&&DB::table('vouchers')->where('id',$faultVoucher)->value('journal_entry_id')===null&&$allocation->outstanding($faultSnap)===50.0,'allocation fault rollback preserves voucher and outstanding');
// Third partial return receives the exact residual in an inspectable DRAFT, not
// a hidden correction at posting or a change to historical invoice/journal.
$roundId=$realDraft('SALE',[['item_id'=>$item,'quantity'=>3,'unit_price'=>'4.000000','discount_amount'=>'1.000','vat_percent'=>15]],['discount_amount'=>'1.000']);$posting->post($cid,$bid,'SALE',$roundId,$uid,true);$roundSnap=$snapshotFor('SALE',$roundId);$roundLine=DB::table('sales_invoice_lines')->where('sales_invoice_id',$roundId)->value('id');
$roundJournal=DB::table('journal_entry_lines')->where('journal_entry_id',$roundSnap->journal_entry_id)->orderBy('id')->get()->toJson();
$roundIds=[];foreach([1,2,3]as$n){$r=$historicalReturns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$roundId,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$roundLine,'quantity'=>1]]]);$roundIds[]=$r;$line=DB::table('commercial_return_lines')->where('return_id',$r)->first();check($line->base_total_before_vat===($n===3?'3.334':'3.333'),'precise partial return draft exposes correct residual '.$n);$historicalReturns->post($cid,$bid,$r,$uid);}
check(DB::table('commercial_returns')->whereIn('id',$roundIds)->sum('base_total_amount')==='11.500'&&$allocation->outstanding($roundSnap)===0.0,'three rounded returns fully reconcile source net and VAT');
check($roundJournal===DB::table('journal_entry_lines')->where('journal_entry_id',$roundSnap->journal_entry_id)->orderBy('id')->get()->toJson(),'rounding repair leaves historical journal unchanged');
// Precise SQL ledger comparison: source debit equals sum of inverse credits.
$returnJournals=DB::table('commercial_returns')->whereIn('id',$roundIds)->pluck('journal_entry_id');
check(DB::table('journal_entry_lines')->whereIn('journal_entry_id',$returnJournals)->where('account_id',$roundSnap->party_account_id)->sum('credit')==='11.500','DECIMAL return party credits equal original receivable exactly');
// Rollback includes real FIFO restoration when failure is injected after GL.
$restoreItem=fixture('items',['company_id'=>$cid,'item_code'=>'FAULT-STOCK','item_name'=>'TEST RETURN ROLLBACK','item_type'=>'STOCK','track_inventory'=>1,'is_active'=>1,'inventory_account_id'=>$gl['inventory'],'sales_account_id'=>$gl['revenue'],'cogs_account_id'=>$gl['cogs'],'sales_return_account_id'=>$gl['sale_return'],'purchase_return_account_id'=>$gl['purchase_return']]);
$pi=$realDraft('PURCHASE',[['item_id'=>$restoreItem,'qty_kg'=>10,'price_unit'=>'KG','unit_price'=>5,'vat_percent'=>0]]);$posting->post($cid,$bid,'PURCHASE',$pi,$uid,true);
$si=$realDraft('SALE',[['item_id'=>$restoreItem,'qty_kg'=>3,'price_unit'=>'KG','unit_price'=>9,'vat_percent'=>0]]);$posting->post($cid,$bid,'SALE',$si,$uid,true);$sl=DB::table('sales_invoice_lines')->where('sales_invoice_id',$si)->value('id');
$r=$historicalReturns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$si,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$sl,'quantity'=>1]]]);$lot=DB::table('inventory_lots')->where('purchase_invoice_id',$pi)->first();$before=[DB::table('journal_entries')->count(),DB::table('stock_movements')->count(),DB::table('inventory_lot_movements')->count()];
DB::unprepared("CREATE TRIGGER trg_test_return_fault BEFORE UPDATE ON commercial_returns FOR EACH ROW BEGIN IF NEW.id={$r} AND NEW.document_status='POSTED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED return fault'; END IF; END");
rejects(fn()=>$historicalReturns->post($cid,$bid,$r,$uid),'return failure after physical restoration and journal rolls back');DB::unprepared('DROP TRIGGER trg_test_return_fault');
check($before===[DB::table('journal_entries')->count(),DB::table('stock_movements')->count(),DB::table('inventory_lot_movements')->count()]&&DB::table('inventory_lots')->where('id',$lot->id)->value('qty_remaining_kg')===$lot->qty_remaining_kg&&DB::table('commercial_returns')->where('id',$r)->value('document_status')==='DRAFT','return failure preserves FIFO lot and all movement/journal counts');
// Six-decimal quantities and tax below one cent: last residual can be zero VAT.
foreach([[1,'10.000000',['0.333333','0.333333','0.333334'],'11.500'],[3,'0.003333',[1,1,1],'0.012']]as[$quantity,$price,$parts,$expected]){
    $id=$realDraft('SALE',[['item_id'=>$item,'quantity'=>$quantity,'unit_price'=>$price,'vat_percent'=>15]]);$posting->post($cid,$bid,'SALE',$id,$uid,true);$s=$snapshotFor('SALE',$id);$line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$id)->value('id');$ids=[];
    foreach($parts as$part){$r=$historicalReturns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$id,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>$part]]]);$ids[]=$r;$historicalReturns->post($cid,$bid,$r,$uid);}
    check(DB::table('commercial_returns')->whereIn('id',$ids)->sum('base_total_amount')===$expected&&$allocation->outstandingDecimal($s)==='0.000','fractional-quantity/tiny-tax returns reconcile exact DECIMAL '.$expected);
    $basis=DB::table('invoice_line_accounting_snapshots')->where('snapshot_id',$s->id)->first();
    check(DB::table('commercial_returns')->whereIn('id',$ids)->sum('base_total_before_vat')===$basis->base_net_basis&&DB::table('commercial_returns')->whereIn('id',$ids)->sum('base_vat_amount')===$basis->base_tax_basis,'net and VAT independently reconcile without hidden rounding journal');
}
