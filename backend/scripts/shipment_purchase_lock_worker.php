<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
if(DB::table('companies')->where('id',1)->value('company_name')!=='UOM lock only')exit(2);
[$kind,$id,$prototype,$phase]=[$argv[1],(int)$argv[2],($argv[3]??'')==='prototype',$argv[4]??''];
DB::statement('SET SESSION innodb_lock_wait_timeout=8');$fired=false;
require __DIR__.'/../tests/Support/ShipmentPostingControllerProbe.php';
app()->bind(App\Http\Controllers\Api\PurchaseInvoiceController::class,fn()=>new Tests\Support\ShipmentPostingControllerProbe($phase));
DB::listen(function($q)use($phase){
 if(str_contains(strtolower($q->sql),'invoice_shipment_links')&&DB::connection()->transactionLevel()===0&&$phase==='after-classification'){
  fwrite(STDOUT,"SCOPE_CLASSIFIED\n");fflush(STDOUT);$key=DB::connection()->getDatabaseName().'-ship-gate';if(!DB::selectOne('SELECT GET_LOCK(?,15) AS ok',[$key])->ok)throw new RuntimeException('BARRIER_TIMEOUT');DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);
 }
 if(str_contains(strtolower($q->sql),'from `companies`')&&str_contains(strtolower($q->sql),'for update'))fwrite(STDOUT,'COMPANY_LOCK_MS '.$q->time."\n");
});
DB::connection()->beforeExecuting(function($sql)use($phase,&$fired){$s=strtolower($sql);
 if(str_contains($s,'insert into `audit_logs`'))fwrite(STDOUT,'AUDIT_WRITE_STATE '.json_encode(['level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction v')->v])."\n");
 if(!$fired&&in_array($phase,['inject-1205','inject-1213','swallow-1205'],true)&&str_contains($s,'update `purchase_invoices`')&&str_contains($s,'document_status')){
  $fired=true;$code=$phase==='inject-1213'?1213:1205;
  DB::unprepared($code===1213?"SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213,MESSAGE_TEXT='Deadlock found when trying to get lock'":"SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO=1205,MESSAGE_TEXT='Lock wait timeout exceeded; try restarting transaction'");
 }
 if($phase==='fault-before-stock-link'&&str_contains($s,'update `stock_movements`')&&str_contains($s,'journal_entry_id'))throw new RuntimeException('FAULT_AFTER_POSTED_JOURNAL_BEFORE_STOCK_LINK');
 if($phase==='fault-audit'&&str_contains($s,'insert into `audit_logs`'))throw new RuntimeException('FAULT_AUDIT_WRITE');
 if(str_contains($s,'for update')){fwrite(STDOUT,'LOCK_SQL '.substr($s,0,120)."\n");fflush(STDOUT);}
 if($phase==='fault-after-lot'&&str_contains($s,'insert into `inventory_lot_movements`'))throw new RuntimeException('FAULT_AFTER_LOT');
 if($phase==='fault-after-journal'&&str_contains($s,'update `purchase_invoices`')&&str_contains($s,'document_status'))throw new RuntimeException('FAULT_AFTER_JOURNAL');
 if($phase==='fault-after-voucher'&&str_contains($s,'update `shipment_costs`')&&str_contains($s,'cost_status'))throw new RuntimeException('FAULT_AFTER_VOUCHER');
 if(!$fired&&$phase==='before-year'&&str_contains($s,'from `financial_years`')&&str_contains($s,'for update')){
  $fired=true;fwrite(STDOUT,"BEFORE_YEAR\n");fflush(STDOUT);$key=DB::connection()->getDatabaseName().'-ship-gate';if(!DB::selectOne('SELECT GET_LOCK(?,15) AS ok',[$key])->ok)throw new RuntimeException('BARRIER_TIMEOUT');DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);
 }
});
$supportToken=$argv[5]??'';
app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function(Throwable $e){
 if($e instanceof Illuminate\Database\QueryException && in_array((int)($e->errorInfo[1]??0),[1205,1213],true))fwrite(STDOUT,'DB_FAILURE '.(int)$e->errorInfo[1]."\n");
});
$work=function()use($kind,$id,$supportToken,$phase){
 if($kind==='support') {
  $request=Illuminate\Http\Request::create('/api/purchase-invoices/'.$id.'/post','POST',$phase==='nonposting-422'?['transaction_owner'=>'forged']:[]);
  $request->headers->set('Authorization','Bearer '.$supportToken);
  $request->headers->set('Accept','application/json');
  $response=app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
  fwrite(STDOUT,'HTTP_END_STATE '.json_encode(['status'=>$response->getStatusCode(),'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction(),'server'=>(int)DB::selectOne('SELECT @@in_transaction v')->v])."\n");
  fwrite(STDOUT,'HTTP_PENDING_TRANSACTIONS '.app('db.transactions')->getPendingTransactions()->count()."\n");
  if($response->getStatusCode()!==200)throw new RuntimeException('HTTP '.$response->getStatusCode().' '.$response->getContent());
  return $response;
 }
 if($kind==='voucher')return DB::transaction(function(){
  $no=app(App\Services\SulbDocumentSequenceService::class)->reserveVoucher(1,'PAYMENT','2026-10-14');
  $v=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>2,'voucher_number'=>$no,'voucher_date'=>'2026-10-14','reference_type'=>'SUPPLIER','reference_id'=>1,'amount'=>40,'financial_account_id'=>1,'cash_account_id'=>14,'payment_method'=>'BANK','currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>40,'created_by'=>1]);
  $r=app(App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>1,'voucher_id'=>$v,'created_by'=>1]);if(!$r->success)throw new RuntimeException($r->message);return $r;
 });
 return match($kind){'purchase'=>app(App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1),'cost'=>app(App\Services\ShipmentCostService::class)->postPendingForShipment(1,$id,1),'opening'=>app(App\Services\OpeningBalanceService::class)->post(1,$id,1,true,1),default=>throw new RuntimeException('UNKNOWN_OPERATION')};
};
// The old prototype option is retained as a test CLI alias only. Both exercise
// the actual repaired application service; no outer wrapper supplies its lock.
try{$work();fwrite(STDOUT,"COMMITTED\n");}catch(Throwable $e){fwrite(STDOUT,'REJECTED '.$e->getMessage()."\n");exit(1);}
