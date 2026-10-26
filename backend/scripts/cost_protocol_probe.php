<?php
require __DIR__.'/../vendor/autoload.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'||getenv('DB_PORT')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:''))exit(2);
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
use Illuminate\Support\Facades\DB;
[$mode,$company,$shipment,$cost]=[$argv[1],(int)$argv[2],(int)$argv[3],(int)($argv[4]??0)];
DB::statement('SET SESSION innodb_lock_wait_timeout=2');echo 'CONNECTION '.DB::selectOne('SELECT CONNECTION_ID() n',[],false)->n.PHP_EOL;flush();
DB::connection()->beforeExecuting(function($sql){if(str_starts_with($sql,'insert into `shipment_costs`')){echo "BEFORE_INSERT\n";flush();}});
$start=microtime(true);
try{
 if($mode==='wait-update')DB::transaction(function()use($cost){
  DB::table('shipment_costs')->where('id',$cost)->lockForUpdate()->first();DB::table('shipment_costs')->where('id',$cost)->update(['amount'=>21,'foreign_amount'=>21]);echo "OWNS_COST\n";flush();
  $root=(int)($GLOBALS['argv'][5]??0);$observer=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName().';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  echo 'L2_WATCH '.json_encode(['root'=>$root,'writer'=>DB::selectOne('SELECT CONNECTION_ID() n')->n,'observer'=>$observer->query('SELECT CONNECTION_ID()')->fetchColumn()]).PHP_EOL;flush();$end=microtime(true)+7;$seen=false;
  // MariaDB10.4.32 trx0i_s.cc requires >100ms since the LAST cache read.
  // A 10ms busy poll can perpetually suppress refresh. Release only on an edge,
  // never because this sampling interval elapsed.
  do{$wait=$observer->query('SELECT r.trx_mysql_thread_id waiter,b.trx_mysql_thread_id holder,l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id='.$root)->fetchAll(PDO::FETCH_ASSOC);$seen=(bool)$wait;if(!$seen)usleep(150000);}while(!$seen&&microtime(true)<$end);
  if($seen){
   $before=[];foreach(['inventory_lots','journal_entries','vouchers','stock_movements']as$table){$before[$table]=(int)$observer->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();if($before[$table]!==0)throw new RuntimeException('Financial write before wait release');}
   echo 'L2_WAIT_EDGE '.json_encode(['edge'=>$wait,'before_commit'=>$before]).PHP_EOL;echo "L2_WRITER_COMMIT_NEXT\n";flush();
  }
  if(!$seen){echo 'L2_WAIT_FAILURE '.json_encode(['process'=>DB::select('SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?',[$root]),'transactions'=>DB::select('SELECT trx_id,trx_state,trx_mysql_thread_id,trx_query FROM information_schema.INNODB_TRX'),'waits'=>DB::select('SELECT * FROM information_schema.INNODB_LOCK_WAITS')]).PHP_EOL;flush();throw new RuntimeException('Root never waited');}echo "ROOT_WAIT_SEEN\n";flush();
 });
 elseif($mode==='metadata-hot'){
  $key=DB::connection()->getDatabaseName().'-metadata-hot';$end=microtime(true)+9;$ready=false;
  while(DB::selectOne('SELECT IS_USED_LOCK(?) n',[$key])->n!==null&&microtime(true)<$end){
   DB::select('SELECT trx_id FROM information_schema.INNODB_TRX');
   if(!$ready){echo "HOT_METADATA_PRIMED\n";flush();$ready=true;}usleep(10000);
  }
  if(DB::selectOne('SELECT IS_USED_LOCK(?) n',[$key])->n!==null)throw new RuntimeException('Hot cache barrier not released');
 }
 elseif($mode==='deadlock')DB::transaction(function()use($shipment,$cost){
  DB::table('shipment_costs')->where('id',$cost)->lockForUpdate()->first();
  for($i=0;$i<150;$i++)DB::table('protocol_weight')->insert(['n'=>$i]);
  echo "OWNS_COST\n";flush();$key=DB::connection()->getDatabaseName().'-protocol-deadlock';
  if(!DB::selectOne('SELECT GET_LOCK(?,8) ok',[$key])->ok)throw new RuntimeException('Barrier timeout');DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);
  echo "REQUEST_H\n";flush();DB::table('shipments')->where('id',$shipment)->lockForUpdate()->first();
 });
 elseif($mode==='service')app(App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>$company,'branch_id'=>$company,'shipment_id'=>$shipment,'expense_type_id'=>$company,'expense_date'=>'2026-10-14','amount'=>7,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'UNPAID','capitalizable'=>true,'created_by'=>$company]);
 elseif($mode==='raw')DB::transaction(fn()=>DB::table('shipment_costs')->insert(['company_id'=>$company,'branch_id'=>$company,'shipment_id'=>$shipment,'expense_type_id'=>$company,'expense_date'=>'2026-10-14','amount'=>7,'foreign_amount'=>7,'currency_code'=>'SAR','exchange_rate'=>1,'cost_status'=>'DRAFT','capitalizable'=>1,'payment_status'=>'UNPAID']));
 else throw new RuntimeException('Unknown test operation');
 echo 'RESULT '.json_encode(['committed'=>true,'ms'=>(microtime(true)-$start)*1000,'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()]).PHP_EOL;
}catch(Throwable$e){echo 'RESULT '.json_encode(['committed'=>false,'code'=>(int)($e->errorInfo[1]??0),'error'=>$e->getMessage(),'ms'=>(microtime(true)-$start)*1000,'level'=>DB::transactionLevel(),'pdo'=>DB::connection()->getPdo()->inTransaction()]).PHP_EOL;exit(1);}
