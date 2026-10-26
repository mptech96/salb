<?php
// Included only by the guarded disposable-database runner; no standalone writes.
if (!isset($posting,$allocation,$cid,$bid,$uid)) throw new RuntimeException('Isolated runner required.');
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$lifecycle=app(\App\Services\EnterpriseInvoiceService::class);
$realDraft=function(string $kind,array $lines,array $extra=[])use($lifecycle,$cid,$bid,$uid,$customer,$supplier){
    return $lifecycle->saveDraft($kind,array_merge(['invoice_date'=>'2026-10-01','invoice_number'=>'CYCLE-'.bin2hex(random_bytes(5)),$kind==='SALE'?'customer_id':'supplier_id'=>$kind==='SALE'?$customer:$supplier,'currency_code'=>'SAR','exchange_rate'=>1,'items'=>$lines],$extra),$cid,$bid,$uid);
};
$snapshotFor=fn(string $kind,int $id)=>DB::table('invoice_accounting_snapshots')->where($kind==='SALE'?'sales_invoice_id':'purchase_invoice_id',$id)->first();
foreach(['VAT_INPUT_ACCOUNT'=>'ASSET','VAT_OUTPUT_ACCOUNT'=>'LIABILITY']as$key=>$type){$id=fixture('accounts',['company_id'=>$cid,'account_code'=>'CYCLE-'.$key,'account_name'=>$key,'account_type'=>$type,'normal_side'=>$type==='ASSET'?'DEBIT':'CREDIT','is_active'=>1,'is_group'=>0,'allow_posting'=>1]);fixture('accounting_settings',['company_id'=>$cid,'setting_key'=>$key,'account_id'=>$id]);$gl[$key]=$id;}
$cycleItem=fixture('items',['company_id'=>$cid,'item_code'=>'CYCLE-STOCK','item_name'=>'ISOLATED CYCLE STOCK','item_type'=>'STOCK','track_inventory'=>1,'is_active'=>1,'inventory_account_id'=>$gl['inventory'],'sales_account_id'=>$gl['revenue'],'cogs_account_id'=>$gl['cogs'],'sales_return_account_id'=>$gl['sale_return'],'purchase_return_account_id'=>$gl['purchase_return']]);
DB::table('customers')->where('id',$customer)->update(['ledger_account_id'=>$gl['ar']]);
DB::table('suppliers')->where('id',$supplier)->update(['ledger_account_id'=>$gl['ap']]);
$p=$realDraft('PURCHASE',[['item_id'=>$cycleItem,'qty_kg'=>20,'price_unit'=>'KG','unit_price'=>10,'vat_percent'=>15]]);
$pj=$posting->post($cid,$bid,'PURCHASE',$p,$uid,true);
$lotId=(int)DB::table('inventory_lots')->where('purchase_invoice_id',$p)->value('id');
check($lotId>0&&(float)DB::table('inventory_lots')->where('id',$lotId)->value('qty_remaining_kg')===20.0,'lifecycle purchase creates real inbound lot');
$s=$realDraft('SALE',[['item_id'=>$cycleItem,'qty_kg'=>10,'price_unit'=>'KG','unit_price'=>20,'discount_amount'=>20,'vat_percent'=>15]],['discount_amount'=>10]);
$sj=$posting->post($cid,$bid,'SALE',$s,$uid,true);
$sourceLine=DB::table('sales_invoice_lines')->where('sales_invoice_id',$s)->first();
check(DB::table('sales_line_lot_sources')->where('sales_invoice_line_id',$sourceLine->id)->exists()&&(float)DB::table('inventory_lots')->where('id',$lotId)->value('qty_remaining_kg')===10.0,'lifecycle sale consumes actual FIFO');
check((float)DB::table('sales_invoices')->where('id',$s)->value('base_total_amount')===195.5&&(float)$sourceLine->base_vat_amount===25.5,'real line/header discount and VAT reconcile');
check($snapshotFor('SALE',$s)->journal_entry_id==$sj&&$snapshotFor('PURCHASE',$p)->journal_entry_id==$pj,'snapshots linked to full lifecycle journals');
$original=DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$pj,$sj])->orderBy('id')->get()->toJson();
DB::table('items')->where('id',$cycleItem)->update(['inventory_account_id'=>$gl['new_inventory'],'cogs_account_id'=>$gl['new_cogs'],'sales_account_id'=>$gl['expense']]);
// Changing current VAT GL must not change the inverse historical VAT.
DB::table('accounting_settings')->where('company_id',$cid)->whereIn('setting_key',['VAT_INPUT_ACCOUNT','VAT_OUTPUT_ACCOUNT'])->update(['account_id'=>$gl['expense']]);
foreach([['SALES_RETURN',$s,$sourceLine->id,[4,6]],['PURCHASE_RETURN',$p,DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$p)->value('id'),[8,12]]]as[$type,$id,$line,$quantities]){
    foreach($quantities as$q){$r=$returns->saveDraft($cid,$bid,$uid,['return_type'=>$type,'source_invoice_id'=>$id,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>$q]]]);$result=$historicalReturns->post($cid,$bid,$r,$uid);$j=(int)$result['return']->journal_entry_id;
        check(DB::table('journal_entry_lines')->where('journal_entry_id',$j)->where('account_id',$gl[$type==='SALES_RETURN'?'VAT_OUTPUT_ACCOUNT':'VAT_INPUT_ACCOUNT'])->exists(),'lifecycle return preserves original VAT GL');
        check((int)$historicalReturns->post($cid,$bid,$r,$uid)['return']->journal_entry_id===$j,'lifecycle return retry no duplicate');}
}
check((float)DB::table('inventory_lots')->where('id',$lotId)->value('qty_remaining_kg')===0.0,'full lifecycle returns restore then remove actual lot');
check($original===DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$pj,$sj])->orderBy('id')->get()->toJson(),'lifecycle original journals unchanged');
// Separate settled scenarios: no implicit refunds or unsupported credit policy.
foreach(['SALE','PURCHASE']as$kind){
    DB::table($kind==='SALE'?'customers':'suppliers')->where('id',$kind==='SALE'?$customer:$supplier)->update(['ledger_account_id'=>$gl[$kind==='SALE'?'ar':'ap']]);
    $id=$realDraft($kind,[['item_id'=>$item,'quantity'=>10,'unit_price'=>100,'vat_percent'=>0]]);$j=$posting->post($cid,$bid,$kind,$id,$uid,true);$snap=$snapshotFor($kind,$id);
    DB::table($kind==='SALE'?'customers':'suppliers')->where('id',$kind==='SALE'?$customer:$supplier)->update(['ledger_account_id'=>$gl[$kind==='SALE'?'new_ar':'new_ap']]);
    foreach([400,600]as$amount){$v=$voucher($kind==='SALE',$amount);$vj=$allocation->post($cid,$bid,$v,[$snap->id=>$amount],'CYCLE-'.$v,$uid);check((int)DB::table('journal_entry_lines')->where('journal_entry_id',$vj)->whereNotNull('party_type')->value('account_id')===(int)$snap->party_account_id,'real lifecycle settlement original party GL');}
    check($allocation->outstanding($snap)===0.0,'real lifecycle fully settled');
    $line=DB::table($kind==='SALE'?'sales_invoice_lines':'purchase_invoice_lines')->where($kind==='SALE'?'sales_invoice_id':'purchase_invoice_id',$id)->value('id');
    $r=$returns->saveDraft($cid,$bid,$uid,['return_type'=>$kind==='SALE'?'SALES_RETURN':'PURCHASE_RETURN','source_invoice_id'=>$id,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>3]]]);$count=DB::table('journal_entries')->count();
    rejects(fn()=>$historicalReturns->post($cid,$bid,$r,$uid),'settled 1000 invoice / 300 return fails closed pending credit policy');
    check(DB::table('journal_entries')->count()===$count&&$allocation->outstanding($snap)===0.0&&DB::table($kind==='SALE'?'sales_invoices':'purchase_invoices')->where('id',$id)->value('payment_status')==='PAID','settled return produces no cash/journal/unpaid state');
}
// Inactive historical account must not receive a broad bypass.
$id=$realDraft('SALE',[['item_id'=>$item,'quantity'=>1,'unit_price'=>50,'vat_percent'=>0]]);$posting->post($cid,$bid,'SALE',$id,$uid,true);$snap=$snapshotFor('SALE',$id);DB::table('accounts')->where('id',$snap->party_account_id)->update(['is_active'=>0]);$v=$voucher(true,50);$count=DB::table('journal_entries')->count();
rejects(fn()=>$allocation->post($cid,$bid,$v,[$snap->id=>50],'INACTIVE-'.$v,$uid),'inactive historical settlement account rejected');
check(DB::table('journal_entries')->count()===$count,'inactive refusal creates no journal');DB::table('accounts')->where('id',$snap->party_account_id)->update(['is_active'=>1]);
// Foreign currency with unchanged rate is supported; changed-rate settlement is not guessed.
fixture('currencies',['currency_code'=>'USD','currency_name'=>'TEST USD','decimal_places'=>3,'is_active'=>1]);
fixture('company_currencies',['company_id'=>$cid,'currency_code'=>'USD','is_base'=>0,'is_active'=>1]);
$fxFa=fixture('financial_accounts',['company_id'=>$cid,'branch_id'=>$bid,'account_code'=>'CYCLE-USD','account_name'=>'TEST USD','account_type'=>'CASH','currency_code'=>'USD','gl_account_id'=>$gl['cash'],'is_active'=>1]);
$fx=$realDraft('SALE',[['item_id'=>$item,'quantity'=>2,'unit_price'=>50,'vat_percent'=>0]],['currency_code'=>'USD','exchange_rate'=>3.75]);$posting->post($cid,$bid,'SALE',$fx,$uid,true);$fs=$snapshotFor('SALE',$fx);
check((float)$fs->foreign_total===100.0&&(float)$fs->base_total===375.0&&(float)$fs->exchange_rate===3.75,'foreign invoice captures actual foreign/base/rate');
$fv=$voucher(true,375);DB::table('vouchers')->where('id',$fv)->update(['financial_account_id'=>$fxFa,'currency_code'=>'USD','exchange_rate'=>3.8,'foreign_amount'=>100,'amount'=>380]);$count=DB::table('journal_entries')->count();
rejects(fn()=>$allocation->post($cid,$bid,$fv,[$fs->id=>380],'FX-'.$fv,$uid),'changed FX settlement blocked without realization policy');
check(DB::table('journal_entries')->count()===$count,'FX refusal has zero journal effect');
DB::table('vouchers')->where('id',$fv)->update(['exchange_rate'=>3.75,'amount'=>375]);$allocation->post($cid,$bid,$fv,[$fs->id=>375],'FX-'.$fv,$uid);check($allocation->outstanding($fs)===0.0,'same FX foreign settlement succeeds');
$fxr=$realDraft('SALE',[['item_id'=>$item,'quantity'=>2,'unit_price'=>50,'vat_percent'=>0]],['currency_code'=>'USD','exchange_rate'=>3.75]);$posting->post($cid,$bid,'SALE',$fxr,$uid,true);$fl=DB::table('sales_invoice_lines')->where('sales_invoice_id',$fxr)->value('id');
$r=$returns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$fxr,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$fl,'quantity'=>2]]]);$historicalReturns->post($cid,$bid,$r,$uid);
check((float)DB::table('commercial_returns')->where('id',$r)->value('base_total_amount')===375.0,'foreign return preserves source currency basis');
// Real physical mutation followed by failed capture must rollback lot, movements and journal.
$costDraft=$realDraft('PURCHASE',[['item_id'=>$cycleItem,'qty_kg'=>5,'price_unit'=>'KG','unit_price'=>10,'vat_percent'=>0]],['transport_cost'=>5]);
$counts=[DB::table('inventory_lots')->count(),DB::table('stock_movements')->count(),DB::table('journal_entries')->count()];
rejects(fn()=>$posting->post($cid,$bid,'PURCHASE',$costDraft,$uid,true),'landed cost capture blocked pending policy');
check($counts===[DB::table('inventory_lots')->count(),DB::table('stock_movements')->count(),DB::table('journal_entries')->count()]&&DB::table('purchase_invoices')->where('id',$costDraft)->value('document_status')==='DRAFT','real lifecycle landed-cost refusal rolls back physical and accounting writes');
// Wrong branch/company/party are rejected in backend services, not only UI.
rejects(fn()=>$posting->post($cid,$foreignBid,'SALE',$fx,$uid,true),'full lifecycle foreign branch rejected');
rejects(fn()=>$allocation->post($foreign,$foreignBid,$fv,[$fs->id=>1],'FOREIGN-SCOPE',$uid),'allocation foreign company rejected');
$wrong=$voucher(false,100);rejects(fn()=>$allocation->post($cid,$bid,$wrong,[$fs->id=>100],'WRONG-PARTY',$uid),'incompatible supplier voucher / customer invoice rejected');
// Multi-line taxed invoice through the real draft service, not hand-built headers.
DB::table('accounting_settings')->where('company_id',$cid)->where('setting_key','VAT_OUTPUT_ACCOUNT')->update(['account_id'=>$gl['VAT_OUTPUT_ACCOUNT']]);
$other=fixture('items',['company_id'=>$cid,'item_code'=>'CYCLE-OTHER','item_name'=>'ISOLATED SECOND SERVICE','item_type'=>'SERVICE','track_inventory'=>0,'is_active'=>1,'sales_account_id'=>$gl['expense'],'sales_return_account_id'=>$gl['sale_return']]);
$multi=$realDraft('SALE',[['item_id'=>$item,'quantity'=>2,'unit_price'=>100,'discount_amount'=>20,'vat_percent'=>15],['item_id'=>$other,'quantity'=>2,'unit_price'=>50,'vat_percent'=>15]],['discount_amount'=>28]);$posting->post($cid,$bid,'SALE',$multi,$uid,true);$ms=$snapshotFor('SALE',$multi);
check((float)$ms->base_total===289.8&&DB::table('invoice_line_accounting_snapshots')->where('snapshot_id',$ms->id)->where('accounting_role','REVENUE')->distinct()->count('account_id')===2,'real multi-line discounts and distinct GL captured');
$ml=DB::table('sales_invoice_lines')->where('sales_invoice_id',$multi)->orderBy('id')->get();
DB::table('items')->whereIn('id',[$item,$other])->update(['sales_account_id'=>$gl['new_cogs']]);
foreach([[['source_invoice_line_id'=>$ml[0]->id,'quantity'=>1]],[['source_invoice_line_id'=>$ml[0]->id,'quantity'=>1],['source_invoice_line_id'=>$ml[1]->id,'quantity'=>2]]]as$rl){$r=$returns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$multi,'return_date'=>'2026-10-04','lines'=>$rl]);$historicalReturns->post($cid,$bid,$r,$uid);}
check($allocation->outstanding($ms)===0.0&&(float)DB::table('commercial_returns')->where('source_invoice_id',$multi)->where('return_type','SALES_RETURN')->where('document_status','POSTED')->sum('base_total_amount')===289.8,'real multi-line partial/full taxed returns reconcile frozen discounted total');
rejects(fn()=>$returns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$multi,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$ml[0]->id,'quantity'=>1]]]),'real multi-line over-return quantity rejected');
