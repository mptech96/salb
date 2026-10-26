<?php
// Explicit isolated execution only. Copies schema definitions, NEVER source rows.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Accounting\InvoiceSnapshotPostingService;
use App\Services\Accounting\InvoiceAllocationService;
use App\Services\Accounting\SnapshotReturnPostingService;

$base=DB::connection('mysql')->getConfig(); // resolved URL/connector config, not unparsed env defaults
if(config('database.default')!=='mysql'||!in_array($base['host'],['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Verified local MySQL only.\n");exit(2);}
$schemaSource=getenv('SULB_HISTORICAL_SCHEMA_SOURCE');$testPort=getenv('SULB_HISTORICAL_TEST_PORT');
if(!$schemaSource||!preg_match('/^sulb_hist_schema_[0-9]{8}_[a-f0-9]{8}$/D',$schemaSource)
    ||$base['database']!==$schemaSource||!ctype_digit((string)$testPort)||(int)$testPort<1||(int)$testPort>65535||(int)$base['port']!==(int)$testPort)
    {fwrite(STDERR,"Explicit independent schema-only source and verified test port required; main database forbidden.\n");exit(2);}
// Never inherit the source connection name/URL/read replicas into a clone.
// Eloquent relations use getName(); keeping "mysql" routes token writes back
// to the source even when the default connection points to the owned database.
unset($base['name'],$base['url'],$base['read'],$base['write']);
if(in_array($argv[1]??'', ['--allocation-worker','--snapshot-worker','--return-worker','--atomic-worker','--legacy-voucher-worker','--worker-http-worker','--worker-request-worker','--worker-settlement-worker'],true)){
    $target=$argv[2]??'';$proof=getenv('SULB_HIST_TEST_WORKER_PROOF');
    if(!$proof||!preg_match('/^sulb_hist_test_[0-9]{8}_[a-f0-9]{8}$/D',$target)||$target===$base['database'])exit(2);
    config(['database.connections.historical_test'=>array_merge($base,['database'=>$target]),'database.default'=>'historical_test']);
    if(!DB::table('companies')->where('id',(int)$argv[3])->where('company_name','HISTORICAL TEST ONLY '.hash('sha256',$proof))->exists())exit(2);
    \Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail((int)$argv[5]));
    fwrite(STDOUT,"READY\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='GO')exit(2);
    try{
        if($argv[1]==='--worker-settlement-worker'){
            request()->attributes->set('tenant_company_id',(int)$argv[3]);
            request()->attributes->set('tenant_branch_id',(int)$argv[4]);
            request()->attributes->set('effective_role_code','COMPANY_OWNER');
            request()->headers->set('X-Company-ID',(string)$argv[3]);
            request()->headers->set('X-Branch-ID',(string)$argv[4]);
            $payload=json_decode(base64_decode($argv[6],true),true,512,JSON_THROW_ON_ERROR);
            $id=DB::transaction(function()use($payload){
                if(($payload['kind']??'RECEIPT')==='PAYROLL'){
                    $support=app(\App\Services\Payroll\WorkerAccountingSupport::class);
                    $company=$support->authorize();app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany($company);
                    $loan=DB::table('worker_loans')->where('company_id',$company)->where('id',$payload['loan'])->lockForUpdate()->first();
                    if(!$loan)throw new RuntimeException('Loan missing.');
                    $worker=$support->worker((int)$loan->worker_id);$branch=$support->branch($worker);
                    $original=DB::table('journal_entry_lines')->where('journal_entry_id',$loan->journal_entry_id)->where('party_type','WORKER')->where('party_id',$worker->id)->where('debit','>',0)->sole();
                    $amount=$payload['amount'];
                    $net=\App\Services\Accounting\HistoricalDecimal::format(1000000-\App\Services\Accounting\HistoricalDecimal::units($amount));
                    $run=DB::table('worker_salary_runs')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'salary_month'=>'2026-05-01','run_number'=>'MIXED-RACE-PROTOTYPE','status'=>'DRAFT','total_amount'=>$net]);
                    $line=DB::table('worker_salary_lines')->insertGetId(['company_id'=>$company,'salary_run_id'=>$run,'worker_id'=>$worker->id,'basic_amount'=>'1000.000','loan_deduction'=>$amount,'net_salary'=>$net]);
                    $jid=app(\App\Services\AccountingService::class)->post(['company_id'=>$company,'branch_id'=>$branch,'source_type'=>'PAYROLL','source_id'=>$run,'entry_date'=>'2026-05-31','currency_code'=>'SAR','exchange_rate'=>1,'created_by'=>auth()->id(),'lines'=>[
                        ['account_id'=>app(\App\Services\Accounting\PostingSupport::class)->setting($company,'SALARY_EXPENSE_ACCOUNT'),'debit'=>'1000.000','credit'=>0],
                        ['account_id'=>$original->account_id,'party_type'=>'WORKER','party_id'=>$worker->id,'debit'=>0,'credit'=>$amount],
                        ['account_id'=>$support->setting($company,'WORKER_PAYABLE_ACCOUNT'),'party_type'=>'WORKER','party_id'=>$worker->id,'debit'=>0,'credit'=>$net]]]);
                    DB::table('worker_salary_runs')->where('id',$run)->update(['status'=>'APPROVED','journal_entry_id'=>$jid]);
                    app(\App\Services\Payroll\IsolatedWorkerLoanSettlement::class)->record($loan->id,'PAYROLL',$line,$amount);
                    return $jid;
                }
                $r=\Illuminate\Http\Request::create('/api/vouchers','POST',$payload['body']);
                $r->setUserResolver(fn()=>auth()->user());
                $r->attributes->set('tenant_company_id',(int)$payload['company']);
                $r->attributes->set('tenant_branch_id',(int)$payload['branch']);
                $r->attributes->set('authenticated_user_id',(int)auth()->id());
                $response=app(\App\Http\Controllers\Api\VoucherController::class)->store($r,app(\App\Services\Accounting\AccountingEngine::class),app(\App\Services\Accounting\AccountingContext::class),app(\App\Services\PartyBranchScopeService::class),app(\App\Services\FinancialAccountService::class));
                if($response->getStatusCode()!==201)throw new RuntimeException($response->getContent());
                $result=$response->getData(true);
                app(\App\Services\Payroll\IsolatedWorkerLoanSettlement::class)->record($payload['loan'],'RECEIPT',$result['id'],$payload['body']['amount']);
                return $result['journal_entry_id'];
            });
        }
        elseif($argv[1]==='--worker-request-worker'){
            request()->attributes->set('tenant_company_id',(int)$argv[3]);
            request()->attributes->set('tenant_branch_id',(int)$argv[4]);
            request()->attributes->set('effective_role_code','COMPANY_OWNER');
            $payload=json_decode(base64_decode($argv[6],true),true,512,JSON_THROW_ON_ERROR);
            $requests=app(\App\Services\Payroll\IsolatedWorkerCreationRequest::class);
            $result=isset($payload['commission'])
                ? $requests->transition($payload['operation'],$payload['commission'],$payload['key'],$payload['body'])
                : $requests->create($payload['operation'],$payload['worker'],$payload['key'],$payload['body']);
            $id=$result['journal_entry_id'];
        }
        elseif(in_array($argv[1],['--legacy-voucher-worker','--worker-http-worker'],true)){
            $payload=json_decode(base64_decode($argv[6],true),true,512,JSON_THROW_ON_ERROR);
            $uri=$argv[1]==='--worker-http-worker'?$payload['uri']:'vouchers';
            if($argv[1]==='--worker-http-worker')$payload=$payload['body'];
            $token=\App\Models\User::findOrFail((int)$argv[5])->createToken('ISOLATED NUMBERING WORKER')->plainTextToken;
            \Illuminate\Support\Facades\Auth::forgetGuards();
            $request=\Illuminate\Http\Request::create('/api/'.$uri,'POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
            $request->headers->set('Authorization','Bearer '.$token);
            $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);$kernel->terminate($request,$response);
            $body=json_decode($response->getContent(),true);
            if($response->getStatusCode()>=300)throw new RuntimeException('Financial HTTP '.$response->getStatusCode().' '.($body['message']??''));
            $id=(int)$body['journal_entry_id'];
        }
        elseif($argv[1]==='--atomic-worker'){
            $payload=json_decode(base64_decode($argv[6],true),true,512,JSON_THROW_ON_ERROR);
            $id=app(\App\Services\Accounting\AtomicInvoiceVoucherService::class)->create((int)$argv[3],(int)$argv[4],(int)$argv[5],$payload['voucher'],$payload['allocations'],$payload['key'])['journal_entry_id'];
        }
        elseif($argv[1]==='--allocation-worker')$id=app(InvoiceAllocationService::class)->post((int)$argv[3],(int)$argv[4],(int)$argv[6],[(int)$argv[7]=>(float)$argv[8]],$argv[9],(int)$argv[5]);
        elseif($argv[1]==='--return-worker')$id=(int)app(SnapshotReturnPostingService::class)->post((int)$argv[3],(int)$argv[4],(int)$argv[6],(int)$argv[5])['return']->journal_entry_id;
        else$id=app(InvoiceSnapshotPostingService::class)->post((int)$argv[3],(int)$argv[4],$argv[7],(int)$argv[6],(int)$argv[5]);
        echo json_encode(['ok'=>true,'journal'=>$id]).PHP_EOL;
    }catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]).PHP_EOL;}
    exit(0);
}
if(($argv[1]??'')!=='--run-isolated')throw new RuntimeException('Requires --run-isolated; no existing DB target accepted.');
$name='sulb_hist_test_'.date('Ymd').'_'.bin2hex(random_bytes(4));
$ddl=[];
// This connection reads metadata only. Never use it for DDL/data writes.
DB::statement('SET SESSION TRANSACTION READ ONLY');
foreach(DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")as$t){
    $table=$t->TABLE_NAME;if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unsafe schema name.');
    if(in_array($table,['invoice_accounting_snapshots','invoice_line_accounting_snapshots','voucher_invoice_allocations','voucher_operation_requests','voucher_correction_audits','worker_operation_requests','worker_loan_settlements']))throw new RuntimeException('Source already contains proposed schema; review first.');
    $row=(array)DB::selectOne('SHOW CREATE TABLE `'.$table.'`');$sql=$row['Create Table'];
    if(str_contains($sql,'`'.$base['database'].'`.'))throw new RuntimeException('Cross-schema reference in source structure.');$ddl[]=$sql;
}
config(['database.connections.historical_admin'=>array_merge($base,['database'=>null])]);
$admin=DB::connection('historical_admin');$admin->statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$cleaned=false;
$cleanup=function()use($admin,$name,$base,&$cleaned){
    if($cleaned)return;
    if(!preg_match('/^sulb_hist_test_[0-9]{8}_[a-f0-9]{8}$/D',$name)||$name===$base['database'])throw new RuntimeException('Cleanup guard failed.');
    DB::disconnect('historical_test');$admin->statement('DROP DATABASE IF EXISTS `'.$name.'`');
    if($admin->selectOne('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?',[$name])->n!=0)throw new RuntimeException('Temporary database cleanup not verified.');
    $cleaned=true;echo 'TEMP_DATABASE_REMOVED '.$name.PHP_EOL;
};
register_shutdown_function(function()use($cleanup,&$cleaned){if(!$cleaned){try{$cleanup();}catch(Throwable){fwrite(STDERR,"ISOLATED_CLEANUP_FAILED; manual owned-database review required.\n");}}});
config(['database.connections.historical_test'=>array_merge($base,['database'=>$name]),'database.default'=>'historical_test']);
$failures=[];$passed=0;
function check(bool $condition,string $label):void{global$passed,$failures;if($condition){$passed++;echo 'PASS '.$label.PHP_EOL;}else{$failures[]=$label;echo 'FAIL '.$label.PHP_EOL;}}
function rejects(callable $fn,string $label):void{try{$fn();check(false,$label);}catch(Throwable){check(true,$label);}}
function fixture(string $table,array $values):int{return(int)DB::table($table)->insertGetId($values);}
try{
    app(\App\Services\Accounting\IsolatedHistoricalAccountingGate::class)::assert();
    if(DB::connection()->getName()!=='historical_test'||DB::connection()->getDatabaseName()!==$name)throw new RuntimeException('Owned connection identity mismatch.');
    DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach($ddl as$sql)DB::unprepared($sql);DB::statement('SET FOREIGN_KEY_CHECKS=1');
    $migration=require __DIR__.'/../../docs/erp-baseline/proposed-migrations/create_historical_invoice_accounting.php';
    $migration->up();check(Schema::hasTable('voucher_invoice_allocations'),'DDL creates all tables');
    rejects(fn()=>$migration->up(),'rerun refuses existing schema without modifying it');
    $migration->down();check(!Schema::hasTable('invoice_accounting_snapshots'),'empty rollback');$migration->up();
    $metadataMigration=require __DIR__.'/../../docs/erp-baseline/proposed-migrations/create_voucher_operation_metadata.php';
    $metadataMigration->up();check(Schema::hasTable('voucher_correction_audits'),'Metadata DDL creates request and audit tables');
    rejects(fn()=>$metadataMigration->up(),'Metadata rerun refuses existing tables');
    $metadataMigration->down();check(!Schema::hasTable('voucher_operation_requests'),'Metadata empty rollback');$metadataMigration->up();
    $numberMigration=require __DIR__.'/../../docs/erp-baseline/proposed-migrations/add_voucher_number_uniqueness.php';
    DB::beginTransaction();
    try {
        $duplicateCompany=fixture('companies',['company_name'=>'NUMBER CONSTRAINT PREFLIGHT ONLY','is_active'=>1]);
        foreach(['REC-LEGACY-DUP','rec-legacy-dup '] as $number)fixture('vouchers',['company_id'=>$duplicateCompany,'voucher_number'=>$number]);
        $beforeDuplicate=DB::table('vouchers')->where('company_id',$duplicateCompany)->orderBy('id')->get()->toJson();
        rejects(fn()=>$numberMigration->up(),'NUMBERING preexisting collation duplicate blocks migration');
        check(DB::table('vouchers')->where('company_id',$duplicateCompany)->orderBy('id')->get()->toJson()===$beforeDuplicate,'NUMBERING duplicate preflight never alters historical numbers');
    } finally { DB::rollBack(); }
    $numberMigration->up();check((int)DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vouchers' AND INDEX_NAME='uq_vouchers_company_number'")->n===2,'NUMBERING database company-number unique index');
    rejects(fn()=>$numberMigration->up(),'NUMBERING repeated proposal refuses existing index');
    $numberMigration->down();$numberMigration->up();check(true,'NUMBERING empty index rollback and reapply');
    if(in_array('--fail-after-schema',$argv,true))throw new RuntimeException('ISOLATED injected failure after schema');
    $proof=bin2hex(random_bytes(16));putenv('SULB_HIST_TEST_WORKER_PROOF='.$proof);
    $cid=fixture('companies',['company_name'=>'HISTORICAL TEST ONLY '.hash('sha256',$proof),'is_active'=>1]);$foreign=fixture('companies',['company_name'=>'FOREIGN TEST ONLY','is_active'=>1]);
    $bid=fixture('branches',['company_id'=>$cid,'branch_name'=>'TEST']);$foreignBid=fixture('branches',['company_id'=>$foreign,'branch_name'=>'FOREIGN']);
    $uid=fixture('users',['company_id'=>$cid,'branch_id'=>$bid,'name'=>'ISOLATED TEST','username'=>'hist-test','password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);
    $role=fixture('roles',['role_code'=>'COMPANY_OWNER','role_name'=>'TEST OWNER','is_active'=>1]);
    fixture('user_roles',['company_id'=>$cid,'user_id'=>$uid,'role_id'=>$role,'is_active'=>1]);
    foreach(['sales.post','purchases.post','vouchers.create','vouchers.delete','returns.post','returns.draft']as$code)fixture('permissions',['permission_code'=>$code,'permission_name'=>$code,'permission_scope'=>'COMPANY']);
    \Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));
    fixture('currencies',['currency_code'=>'SAR','currency_name'=>'TEST SAR','decimal_places'=>3,'is_active'=>1]);
    fixture('company_currencies',['company_id'=>$cid,'currency_code'=>'SAR','is_base'=>1,'is_active'=>1]);
    fixture('company_settings',['company_id'=>$cid,'currency_code'=>'SAR','base_currency_code'=>'SAR','currency_decimal_places'=>3]);
    fixture('financial_years',['company_id'=>$cid,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_closed'=>0]);
    $gl=[];foreach(['cash'=>'ASSET','ar'=>'ASSET','ap'=>'LIABILITY','inventory'=>'ASSET','revenue'=>'REVENUE','cogs'=>'EXPENSE','expense'=>'EXPENSE','sale_return'=>'REVENUE','purchase_return'=>'EXPENSE','new_ar'=>'ASSET','new_ap'=>'LIABILITY','new_inventory'=>'ASSET','new_cogs'=>'EXPENSE']as$key=>$type)$gl[$key]=fixture('accounts',['company_id'=>$cid,'account_code'=>'TEST-'.$key,'account_name'=>$key,'account_type'=>$type,'normal_side'=>in_array($type,['LIABILITY','REVENUE'])?'CREDIT':'DEBIT','is_active'=>1,'is_group'=>0,'allow_posting'=>1,'allow_cost_center'=>0]);
    foreach(['CUSTOMER_ACCOUNT'=>'ar','SUPPLIER_ACCOUNT'=>'ap','CASH_ACCOUNT'=>'cash','INVENTORY_ACCOUNT'=>'inventory','SALES_ACCOUNT'=>'revenue','COGS_ACCOUNT'=>'cogs','GENERAL_EXPENSE_ACCOUNT'=>'expense']as$key=>$account)fixture('accounting_settings',['company_id'=>$cid,'setting_key'=>$key,'account_id'=>$gl[$account]]);
    $customer=fixture('customers',['company_id'=>$cid,'branch_id'=>$bid,'customer_name'=>'TEST','ledger_account_id'=>$gl['ar'],'scope_all_branches'=>1,'is_active'=>1]);
    $supplier=fixture('suppliers',['company_id'=>$cid,'branch_id'=>$bid,'supplier_name'=>'TEST','ledger_account_id'=>$gl['ap'],'scope_all_branches'=>1,'is_active'=>1]);
    $fa=fixture('financial_accounts',['company_id'=>$cid,'branch_id'=>$bid,'account_code'=>'TEST-CASH','account_name'=>'TEST CASH','account_type'=>'CASH','currency_code'=>'SAR','gl_account_id'=>$gl['cash'],'is_active'=>1]);
    DB::table('voucher_types')->insert([['id'=>1,'type_code'=>'RECEIPT','type_name'=>'TEST RECEIPT'],['id'=>2,'type_code'=>'PAYMENT','type_name'=>'TEST PAYMENT']]);
    $item=fixture('items',['company_id'=>$cid,'item_name'=>'TEST SERVICE','item_code'=>'S1','item_type'=>'SERVICE','track_inventory'=>0,'sales_account_id'=>$gl['revenue'],'purchase_expense_account_id'=>$gl['expense'],'sales_return_account_id'=>$gl['sale_return'],'purchase_return_account_id'=>$gl['purchase_return']]);
    $invoice=function(string $kind,int $item,int $qty,float $price,bool $stock=false)use($cid,$bid,$customer,$supplier){$sale=$kind==='SALE';$table=$sale?'sales':'purchase';$total=$qty*$price;
        $id=fixture($table.'_invoices',['company_id'=>$cid,'branch_id'=>$bid,$sale?'customer_id':'supplier_id'=>$sale?$customer:$supplier,'invoice_number'=>'TEST-'.bin2hex(random_bytes(4)),'invoice_date'=>'2026-10-01','currency_code'=>'SAR','exchange_rate'=>1,'total_amount'=>$total,'base_total_amount'=>$total,'base_total_before_vat'=>$total,'total_before_vat'=>$total,'vat_amount'=>0,'base_vat_amount'=>0,'document_status'=>'DRAFT','payment_status'=>'UNPAID']);
        $line=fixture($table.'_invoice_lines',['company_id'=>$cid,$table.'_invoice_id'=>$id,'item_id'=>$item,'qty'=>$qty,'quantity'=>$qty,'unit_price'=>$price,'qty_kg'=>$stock?$qty:0,'item_type_snapshot'=>$stock?'STOCK':'SERVICE','track_inventory_snapshot'=>$stock?1:0,'unit_code'=>$stock?'KG':'UNIT','total_before_vat'=>$total,'base_total_before_vat'=>$total,'total_after_vat'=>$total,'base_total_after_vat'=>$total,'vat_amount'=>0,'base_vat_amount'=>0]);return[$id,$line];};
    $voucher=function(bool $receipt,float $amount)use($cid,$bid,$customer,$supplier,$fa,$uid){return fixture('vouchers',['company_id'=>$cid,'branch_id'=>$bid,'voucher_type_id'=>$receipt?1:2,'reference_type'=>$receipt?'CUSTOMER':'SUPPLIER','reference_id'=>$receipt?$customer:$supplier,'financial_account_id'=>$fa,'voucher_number'=>'TEST-'.bin2hex(random_bytes(4)),'voucher_date'=>'2026-10-02','currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>$amount,'amount'=>$amount,'payment_method'=>'CASH','created_by'=>$uid]);};
    $posting=app(InvoiceSnapshotPostingService::class);$allocation=app(InvoiceAllocationService::class);
    [$sale,$saleLine]=$invoice('SALE',$item,10,10);[$purchase]=$invoice('PURCHASE',$item,10,10);
    $saleJournal=$posting->post($cid,$bid,'SALE',$sale,$uid);$purchaseJournal=$posting->post($cid,$bid,'PURCHASE',$purchase,$uid);
    check($posting->post($cid,$bid,'SALE',$sale,$uid)===$saleJournal,'snapshot retry no duplicate');
    $saleSnapshot=DB::table('invoice_accounting_snapshots')->where('sales_invoice_id',$sale)->first();$purchaseSnapshot=DB::table('invoice_accounting_snapshots')->where('purchase_invoice_id',$purchase)->first();
    check(DB::table('invoice_accounting_snapshots')->count()===2,'exactly two snapshots');
    $history=DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$saleJournal,$purchaseJournal])->orderBy('id')->get()->toJson();
    DB::table('customers')->where('id',$customer)->update(['ledger_account_id'=>$gl['new_ar']]);DB::table('suppliers')->where('id',$supplier)->update(['ledger_account_id'=>$gl['new_ap']]);
    foreach([[$saleSnapshot,true,$gl['ar']],[$purchaseSnapshot,false,$gl['ap']]]as[$s,$receipt,$expected]){
        foreach([40,60]as$amount){$v=$voucher($receipt,$amount);$jid=$allocation->post($cid,$bid,$v,[$s->id=>$amount],'TEST-'.$v,$uid);check($jid===$allocation->post($cid,$bid,$v,[$s->id=>$amount],'TEST-'.$v,$uid),'allocation retry');check((int)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->whereNotNull('party_type')->value('account_id')===$expected,'historical party GL after change');check($allocation->outstanding($s)===($amount===40?60.0:0.0),'partial/full outstanding');}
        $v=$voucher($receipt,1);rejects(fn()=>$allocation->post($cid,$bid,$v,[$s->id=>1],'OVER-'.$v,$uid),'overpayment rejected');
    }
    $v=DB::table('voucher_invoice_allocations')->where('snapshot_id',$saleSnapshot->id)->where('entry_kind','ALLOCATE')->orderBy('id')->first()->voucher_id;
    $rev=$allocation->reverse($cid,$bid,(int)$v,$uid,'2026-10-03');check($rev===$allocation->reverse($cid,$bid,(int)$v,$uid,'2026-10-03'),'reversal retry');check($allocation->outstanding($saleSnapshot)===40.0,'reversal restores outstanding');
    check($history===DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$saleJournal,$purchaseJournal])->orderBy('id')->get()->toJson(),'original journal lines unchanged');
    $copy=(array)$saleSnapshot;unset($copy['id']);rejects(fn()=>DB::table('invoice_accounting_snapshots')->insert($copy),'nullable UNIQUE duplicate sale rejected');
    $foreignCopy=$copy;$foreignCopy['company_id']=$foreign;$foreignCopy['branch_id']=$foreignBid;
    rejects(fn()=>DB::table('invoice_accounting_snapshots')->insert($foreignCopy),'database rejects cross-company source snapshot');
    $copy['sales_invoice_id']=null;$copy['invoice_type']='PURCHASE';rejects(fn()=>DB::table('invoice_accounting_snapshots')->insert($copy),'CHECK rejects malformed invoice kind');
    rejects(fn()=>$migration->down(),'populated rollback refused');
    rejects(fn()=>DB::table('sales_invoices')->where('id',$sale)->delete(),'FK prevents deleting original evidence source');
    rejects(fn()=>$posting->post($foreign,$foreignBid,'SALE',$sale,$uid),'cross company posting rejected');
    $a=DB::table('voucher_invoice_allocations')->where('entry_kind','ALLOCATE')->first();$copy=(array)$a;unset($copy['id']);rejects(fn()=>DB::table('voucher_invoice_allocations')->insert($copy),'duplicate allocation rejected');
    // Execute partial/final stock returns through the real return/lot/JournalService path.
    $stockItem=fixture('items',['company_id'=>$cid,'item_name'=>'TEST STOCK','item_code'=>'ST1','item_type'=>'STOCK','track_inventory'=>1,'inventory_account_id'=>$gl['inventory'],'sales_account_id'=>$gl['revenue'],'cogs_account_id'=>$gl['cogs'],'sales_return_account_id'=>$gl['sale_return'],'purchase_return_account_id'=>$gl['purchase_return']]);
    [$stockPurchase,$stockPurchaseLine]=$invoice('PURCHASE',$stockItem,20,10,true);
    $purchaseStockJournal=$posting->post($cid,$bid,'PURCHASE',$stockPurchase,$uid);
    $lot=fixture('inventory_lots',['company_id'=>$cid,'branch_id'=>$bid,'item_id'=>$stockItem,'lot_number'=>'TEST-LOT','purchase_invoice_id'=>$stockPurchase,'purchase_invoice_line_id'=>$stockPurchaseLine,'source_type'=>'PURCHASE','source_id'=>$stockPurchase,'received_at'=>'2026-10-01','qty_received_kg'=>20,'qty_remaining_kg'=>10,'qty_sold_kg'=>10,'base_cost'=>200,'allocated_cost'=>0,'total_cost'=>200,'unit_cost_per_kg'=>10,'lot_status'=>'OPEN']);
    [$stockSale,$stockSaleLine]=$invoice('SALE',$stockItem,10,20,true);
    fixture('sales_line_lot_sources',['company_id'=>$cid,'branch_id'=>$bid,'sales_invoice_line_id'=>$stockSaleLine,'inventory_lot_id'=>$lot,'qty_kg'=>10,'unit_cost_per_kg'=>10,'total_cost'=>100]);
    $saleStockJournal=$posting->post($cid,$bid,'SALE',$stockSale,$uid);
    $stockHistory=DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$purchaseStockJournal,$saleStockJournal])->orderBy('id')->get()->toJson();
    DB::table('items')->where('id',$stockItem)->update(['inventory_account_id'=>$gl['new_inventory'],'cogs_account_id'=>$gl['new_cogs'],'sales_return_account_id'=>$gl['revenue'],'purchase_return_account_id'=>$gl['expense']]);
    $returns=app(\App\Services\CommercialReturnService::class);$historicalReturns=app(SnapshotReturnPostingService::class);
    foreach([['SALES_RETURN',$stockSale,$stockSaleLine,[4,6]],['PURCHASE_RETURN',$stockPurchase,$stockPurchaseLine,[8,12]]]as[$type,$source,$line,$quantities]){
        foreach($quantities as$qty){
            $rid=$returns->saveDraft($cid,$bid,$uid,['return_type'=>$type,'source_invoice_id'=>$source,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>$qty]]]);
            $result=$historicalReturns->post($cid,$bid,$rid,$uid);$jid=(int)$result['return']->journal_entry_id;
            check($jid===(int)$historicalReturns->post($cid,$bid,$rid,$uid)['return']->journal_entry_id,'historical return retry');
            $jl=DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->get();
            check($jl->contains(fn($l)=>(int)$l->account_id===$gl['inventory'])&&!$jl->contains(fn($l)=>(int)$l->account_id===$gl['new_inventory']),'return uses frozen inventory GL');
            if($type==='SALES_RETURN')check($jl->contains(fn($l)=>(int)$l->account_id===$gl['cogs'])&&$jl->contains(fn($l)=>(int)$l->account_id===$gl['sale_return']),'return uses frozen COGS and explicit return GL');
        }
    }
    check((float)DB::table('inventory_lots')->where('id',$lot)->value('qty_remaining_kg')===0.0,'partial/final returns reconcile original lot quantity');
    check(DB::table('inventory_lot_movements')->count()===4&&DB::table('stock_movements')->count()===4,'return retry does not duplicate physical/lot movements');
    check($stockHistory===DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$purchaseStockJournal,$saleStockJournal])->orderBy('id')->get()->toJson(),'stock source journal lines unchanged');
    check((float)DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$saleStockJournal,DB::table('commercial_returns')->where('source_invoice_id',$stockSale)->where('return_type','SALES_RETURN')->first()->journal_entry_id,DB::table('commercial_returns')->where('source_invoice_id',$stockSale)->where('return_type','SALES_RETURN')->orderByDesc('id')->first()->journal_entry_id])->where('account_id',$gl['cogs'])->sum(DB::raw('debit-credit'))===0.0,'full sale return reverses original COGS');
    // Prove rollback AFTER real journal creation, not just validation refusal.
    [$faultInvoice]=$invoice('SALE',$item,1,10);$journalCount=DB::table('journal_entries')->count();$snapshotCount=DB::table('invoice_accounting_snapshots')->count();
    DB::unprepared("CREATE TRIGGER trg_test_snapshot_fault BEFORE INSERT ON invoice_line_accounting_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ISOLATED injected capture failure'");
    rejects(fn()=>$posting->post($cid,$bid,'SALE',$faultInvoice,$uid),'snapshot failure rolls back');
    DB::unprepared('DROP TRIGGER trg_test_snapshot_fault');
    check(DB::table('journal_entries')->count()===$journalCount&&DB::table('invoice_accounting_snapshots')->count()===$snapshotCount&&DB::table('sales_invoices')->where('id',$faultInvoice)->value('journal_entry_id')===null,'capture and journal rollback atomic');
    // Actual two-process barrier, not a sequential retry described as concurrency.
    $race=function(array $commands){$workers=[];try{
        foreach($commands as$args){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__.'/..');if(!is_resource($process))throw new RuntimeException('Worker launch failed.');stream_set_timeout($pipes[1],20);$workers[]=[$process,$pipes];}
        foreach($workers as[$process,$pipes])if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Worker did not reach barrier.');
        foreach($workers as[$process,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
        $results=[];foreach($workers as[$process,$pipes]){$result=json_decode(trim(stream_get_contents($pipes[1])),true);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_array($result))throw new RuntimeException('Invalid worker result '.$stderr);$results[]=$result;}$workers=[];return$results;
    }finally{foreach($workers as[$process,$pipes]){proc_terminate($process);foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}};
    [$raceInvoice]=$invoice('SALE',$item,10,10);
    $snapRace=$race([['--snapshot-worker',$name,$cid,$bid,$uid,$raceInvoice,'SALE'],['--snapshot-worker',$name,$cid,$bid,$uid,$raceInvoice,'SALE']]);
    check($snapRace[0]['ok']&&$snapRace[1]['ok']&&$snapRace[0]['journal']===$snapRace[1]['journal'],'concurrent snapshot creates one journal');
    $raceSnapshot=DB::table('invoice_accounting_snapshots')->where('sales_invoice_id',$raceInvoice)->first();$v1=$voucher(true,75);$v2=$voucher(true,75);
    $payRace=$race([['--allocation-worker',$name,$cid,$bid,$uid,$v1,$raceSnapshot->id,75,'RACE-'.$v1],['--allocation-worker',$name,$cid,$bid,$uid,$v2,$raceSnapshot->id,75,'RACE-'.$v2]]);
    check(count(array_filter($payRace,fn($r)=>$r['ok']))===1&&$allocation->outstanding($raceSnapshot)===25.0,'concurrent allocation prevents oversettlement');
    // Distinct source GL plus shared grouped GL in one invoice.
    $newRevenue=fixture('accounts',['company_id'=>$cid,'account_code'=>'TEST-REVENUE2','account_name'=>'TEST REVENUE2','account_type'=>'REVENUE','normal_side'=>'CREDIT','is_active'=>1,'is_group'=>0,'allow_posting'=>1]);
    $item2=fixture('items',['company_id'=>$cid,'item_name'=>'TEST SERVICE2','item_code'=>'S2','item_type'=>'SERVICE','track_inventory'=>0,'sales_account_id'=>$newRevenue,'purchase_expense_account_id'=>$gl['expense'],'sales_return_account_id'=>$gl['sale_return'],'purchase_return_account_id'=>$gl['purchase_return']]);
    [$multi,$firstLine]=$invoice('SALE',$item,10,10);
    foreach([$item2,$item]as$lineItem)fixture('sales_invoice_lines',['company_id'=>$cid,'sales_invoice_id'=>$multi,'item_id'=>$lineItem,'qty'=>5,'quantity'=>5,'unit_price'=>10,'qty_kg'=>0,'item_type_snapshot'=>'SERVICE','track_inventory_snapshot'=>0,'unit_code'=>'UNIT','total_before_vat'=>50,'base_total_before_vat'=>50,'total_after_vat'=>50,'base_total_after_vat'=>50,'vat_amount'=>0,'base_vat_amount'=>0]);
    DB::table('sales_invoices')->where('id',$multi)->update(['total_amount'=>200,'base_total_amount'=>200,'base_total_before_vat'=>200,'total_before_vat'=>200]);
    $multiJournal=$posting->post($cid,$bid,'SALE',$multi,$uid);$multiSnapshot=DB::table('invoice_accounting_snapshots')->where('sales_invoice_id',$multi)->first();
    $contributions=DB::table('invoice_line_accounting_snapshots')->where('snapshot_id',$multiSnapshot->id)->where('accounting_role','REVENUE')->get();
    check($contributions->count()===3&&$contributions->pluck('journal_entry_line_id')->unique()->count()===2,'line contributions retain distinct/shared grouped GL');
    [$additional]=$invoice('SALE',$item,5,10);$posting->post($cid,$bid,'SALE',$additional,$uid);$additionalSnapshot=DB::table('invoice_accounting_snapshots')->where('sales_invoice_id',$additional)->first();
    $v=$voucher(true,250);$jid=$allocation->post($cid,$bid,$v,[$multiSnapshot->id=>200,$additionalSnapshot->id=>50],'MULTI-'.$v,$uid);
    check($allocation->outstanding($multiSnapshot)===0.0&&$allocation->outstanding($additionalSnapshot)===0.0,'one voucher pays multiple compatible invoices');
    rejects(fn()=>$allocation->post($cid,$bid,$v,[$multiSnapshot->id=>199,$additionalSnapshot->id=>51],'MULTI-'.$v,$uid),'same key changed payload rejected');
    // A preexisting posted invoice is NOT automatically considered unpaid/captured.
    [$legacy]=$invoice('SALE',$item,1,10);app(\App\Services\Accounting\EnterpriseAccountingPostingService::class)->postSale($cid,$legacy,$uid);
    rejects(fn()=>$posting->post($cid,$bid,'SALE',$legacy,$uid),'legacy journal cannot be rebuilt from current mappings');
    // Block missing return configuration without preventing draft save/view.
    DB::table('items')->where('id',$item)->update(['sales_return_account_id'=>null]);[$noPolicy,$noPolicyLine]=$invoice('SALE',$item,1,10);$posting->post($cid,$bid,'SALE',$noPolicy,$uid);
    $draft=$returns->saveDraft($cid,$bid,$uid,['return_type'=>'SALES_RETURN','source_invoice_id'=>$noPolicy,'return_date'=>'2026-10-04','lines'=>[['source_invoice_line_id'=>$noPolicyLine,'quantity'=>1]]]);$before=DB::table('journal_entries')->count();
    rejects(fn()=>$historicalReturns->post($cid,$bid,$draft,$uid),'missing explicit return account blocks posting');
    check($returns->details($cid,$draft,$bid)['return']->document_status==='DRAFT'&&DB::table('journal_entries')->count()===$before,'missing policy preserves draft/view with zero journal');
    DB::table('items')->where('id',$item)->update(['sales_return_account_id'=>$gl['sale_return']]);
    rejects(fn()=>$historicalReturns->post($cid,$bid,$draft,$uid),'later setup cannot rewrite missing historical policy');
    // SQL evidence immutability and basic FK / signedness inspection.
    rejects(fn()=>DB::table('invoice_accounting_snapshots')->where('id',$saleSnapshot->id)->update(['base_total'=>999]),'snapshot mutation denied');
    rejects(fn()=>DB::table('voucher_invoice_allocations')->where('id',$a->id)->delete(),'allocation deletion denied');
    $columns=DB::select("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='voucher_invoice_allocations'");$types=[];foreach($columns as$c)$types[$c->COLUMN_NAME]=$c->COLUMN_TYPE;
    check(!str_contains($types['voucher_id'],'unsigned')&&str_contains($types['snapshot_id'],'unsigned'),'actual signed voucher / unsigned snapshot types');
    require __DIR__.'/historical_accounting_lifecycle_cases.php';
    require __DIR__.'/historical_accounting_gap_cases.php';
    require __DIR__.'/historical_accounting_http_cases.php';
    require __DIR__.'/historical_accounting_full_http_cases.php';
    require __DIR__.'/historical_accounting_numbering_cases.php';
    require __DIR__.'/worker_accounting_isolated_cases.php';
    $reports=app(\App\Domain\Accounting\Services\AccountingReportService::class);$trial=$reports->trialBalance($cid,$bid,['from_date'=>'2026-01-01','to_date'=>'2026-12-31']);
    check(abs($trial['totals']['period_debit']-$trial['totals']['period_credit'])<0.0001,'real trial balance reconciles');
    // New numbering acceptance includes another branch: compare identical company scopes.
    $ledger=$reports->ledger($cid,null,$gl['cash'],['from_date'=>'2026-01-01','to_date'=>'2026-12-31']);
    $cashNet=(float)DB::table('journal_entry_lines')->where('company_id',$cid)->where('account_id',$gl['cash'])->sum(DB::raw('debit-credit'));$ledgerSigned=$ledger['closing_side']==='CREDIT'?-$ledger['closing_balance']:$ledger['closing_balance'];check(abs($ledgerSigned-$cashNet)<0.0001,'real cash GL agrees with settlement/reversal entries');
    $imbalanced=DB::table('journal_entry_lines')->selectRaw('journal_entry_id,SUM(debit)-SUM(credit) AS difference')->groupBy('journal_entry_id')->havingRaw('ABS(SUM(debit)-SUM(credit))>0.0001')->count();check($imbalanced===0,'all real journals balanced');
    echo 'SUMMARY '.json_encode(['database'=>$name,'passed'=>$passed,'failures'=>$failures]).PHP_EOL;
}catch(Throwable $e){
    $failures[]='Unhandled isolated integration failure: '.$e->getMessage();
    fwrite(STDERR,'ISOLATED_FAILURE '.$e->getMessage().PHP_EOL);
}finally{
    // Exact generated name only; never any pre-existing DB. Preserve all source data.
    $cleanup();
}
exit($failures?1:0);
