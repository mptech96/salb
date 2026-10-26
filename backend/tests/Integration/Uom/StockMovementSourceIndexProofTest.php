<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Proposed index ONLY on runner-owned temporary schema. Application SQL unchanged. */
class StockMovementSourceIndexProofTest extends TrustedSupportShipmentOwnershipTest
{
 protected function await(Process $p,string $stage):void{
  // Observer budget only; workers retain their existing DB timeout and barriers.
  $end=microtime(true)+20;while(!str_contains($p->getOutput(),$stage)&&$p->isRunning()&&microtime(true)<$end)usleep(10000);
  self::assertStringContainsString($stage,$p->getOutput(),$p->getErrorOutput());
 }
 protected function setUp():void{
  parent::setUp(); // Parent explicitly installs the candidate in isolated schema only.
 }
 public function test_original_real_service_failure_without_index_is_reproduced():void{
  DB::statement('DROP INDEX idx_stock_company_source ON stock_movements');
  try{parent::test_two_company_support_posts_do_not_share_company_lock();self::fail('Original failure disappeared');}
  catch(\PHPUnit\Framework\AssertionFailedError $e){self::assertStringContainsString('1205',$e->getMessage());self::assertStringContainsString('update `stock_movements`',$e->getMessage());fwrite(STDOUT,"BASELINE_REAL_SERVICE_1205_REPRODUCED\n");}
  finally{DB::statement('CREATE INDEX idx_stock_company_source ON stock_movements(company_id,source_type,source_id)');}
  fwrite(STDOUT,'BASELINE_TIMEOUT_FINANCIAL_STATE '.json_encode([
   'invoices'=>DB::table('purchase_invoices')->where('company_id',2)->get(['id','document_status','journal_entry_id']),
   'journals'=>DB::table('journal_entries')->where('company_id',2)->get(['id','source_type','source_id','status']),
   'lots'=>DB::table('inventory_lots')->where('company_id',2)->get(['id','qty_remaining_kg','total_cost']),
   'stock'=>DB::table('stock_movements')->where('company_id',2)->get(['id','journal_entry_id','qty_kg']),
   'vouchers'=>DB::table('vouchers')->where('company_id',2)->count(),
   'audit'=>DB::table('audit_logs')->where('company_id',2)->get(['action_type','result'])
  ])."\n");
  self::assertSame(0,DB::table('journal_entries')->where('company_id',2)->count());
  self::assertSame(0,DB::table('vouchers')->where('company_id',2)->count());
  self::assertSame(0,DB::table('inventory_lots')->where('company_id',2)->count());
  $invoice=(int)DB::table('purchase_invoices')->where('company_id',2)->value('id');
  self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$invoice)->value('document_status'));
  self::assertEquals(1,DB::table('sulb_document_sequences')->where('company_id',2)->where('document_type','INVENTORY_LOT')->value('next_number'));
  $service=app(\App\Services\EnterpriseInvoiceService::class);$service->post('PURCHASE',2,$invoice,99,2);$service->post('PURCHASE',2,$invoice,99,2);
  self::assertSame(2,DB::table('journal_entries')->where('company_id',2)->count());self::assertSame(1,DB::table('vouchers')->where('company_id',2)->count());$this->balanced();
 }
 public function test_indexed_real_two_company_round_two():void{parent::test_two_company_support_posts_do_not_share_company_lock();}
 public function test_indexed_real_two_company_round_three():void{parent::test_two_company_support_posts_do_not_share_company_lock();}
 public function test_index_does_not_change_legacy_fifo_or_numbering():void{
  $s=\Tests\Support\ShipmentPurchaseLockFixture::purchase();$this->postInvoice($s['invoice']);$this->financial($s);
  self::assertSame('LOT-2026-000001',DB::table('inventory_lots')->value('lot_number'));self::assertSame('PAY-2026-000001',DB::table('vouchers')->value('voucher_number'));
  $cost=DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,1,5,'SALE',909));
  self::assertEquals(60,$cost['total_cost']);self::assertEquals(5,DB::table('inventory_lots')->value('qty_remaining_kg'));self::assertEquals(12,DB::table('inventory_lots')->value('unit_cost_per_kg'));
 }
 public function test_different_company_independent_cost_is_not_blocked():void{
  $s=\Tests\Support\ShipmentPurchaseLockFixture::purchase();
  foreach([24=>'ASSET',25=>'EXPENSE'] as $id=>$type)DB::table('accounts')->insert(['id'=>$id,'company_id'=>2,'account_code'=>'C-'.$id,'account_name'=>'Synthetic','account_type'=>$type,'normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0]);
  DB::table('accounting_settings')->insert(['company_id'=>2,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>22]);
  DB::table('financial_accounts')->insert(['id'=>2,'company_id'=>2,'branch_id'=>2,'account_code'=>'C-BANK','account_name'=>'Synthetic','account_type'=>'BANK','gl_account_id'=>24,'currency_code'=>'SAR']);
  DB::table('expense_types')->insert(['id'=>2,'company_id'=>2,'type_name'=>'Synthetic','type_code'=>'C-FREIGHT','account_id'=>25,'usage_type'=>'SHIPMENT','affects_cost'=>1]);
  $sid=DB::table('shipments')->insertGetId(['company_id'=>2,'branch_id'=>2,'supplier_id'=>2,'shipment_number'=>'COST2','shipment_date'=>'2026-10-14','commercial_status'=>'READY','accepted_weight_kg'=>10,'currency_code'=>'SAR']);
  DB::table('shipment_items')->insert(['company_id'=>2,'shipment_id'=>$sid,'item_id'=>3,'qty_kg'=>10,'accepted_qty_kg'=>10,'base_cost'=>100,'remaining_qty_kg'=>10]);
  app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>2,'branch_id'=>2,'shipment_id'=>$sid,'expense_type_id'=>2,'expense_date'=>'2026-10-14','amount'=>20,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>2,'capitalizable'=>false,'created_by'=>2]);
  $key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$this->worker('purchase',$s['invoice'],false,'before-year');$b=null;
  try{$this->await($a,'BEFORE_YEAR');$b=new Process([PHP_BINARY,base_path('scripts/stock_scope_lock_probe.php'),'cost2',(string)$sid],base_path());$b->setTimeout(25);$b->start();$this->await($b,'COST_COMMIT');self::assertStringNotContainsString('COMMITTED',$a->getOutput());}
  finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
  self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());$this->financial($s);
  self::assertEquals(20,DB::table('journal_entry_lines')->where('company_id',2)->where('account_id',25)->sum('debit'));
  self::assertEquals(20,DB::table('journal_entry_lines')->where('company_id',2)->where('account_id',24)->sum('credit'));
 }
 public function test_same_company_same_and_different_items_keep_original_serialization():void{
  foreach([1,2] as $item){
   $aSource=\Tests\Support\ShipmentPurchaseLockFixture::purchase();$bSource=\Tests\Support\ShipmentPurchaseLockFixture::purchase();
   DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$bSource['invoice'])->update(['item_id'=>$item]);DB::table('shipment_items')->where('id',$bSource['line'])->update(['item_id'=>$item]);
   $key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$this->worker('purchase',$aSource['invoice'],false,'before-year');$b=null;
   try{$this->await($a,'BEFORE_YEAR');$b=$this->worker('purchase',$bSource['invoice']);$this->await($b,'from `companies`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}
   finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
   self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());$this->financial($aSource);$this->financial($bSource);
  }
 }
 public function test_proposed_migration_up_repeat_down_on_owned_schema_only():void{
  $migration=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');
  $migration->down();$migration->up();$migration->up();
  $rows=DB::select('SHOW INDEX FROM stock_movements WHERE Key_name="idx_stock_company_source"');self::assertCount(3,$rows);
  self::assertSame(['company_id','source_type','source_id'],array_map(fn($r)=>$r->Column_name,$rows));
 }
 public function test_actual_where_plan_and_row_lock_before_after_three_rounds():void{
  DB::statement('DROP INDEX idx_stock_company_source ON stock_movements');
  $sql='UPDATE stock_movements SET journal_entry_id=123,updated_at="2026-10-14 00:00:00" WHERE company_id=2 AND source_type="PURCHASE" AND source_id=202';
  fwrite(STDOUT,'STOCK_SCHEMA '.json_encode(DB::select('SHOW CREATE TABLE stock_movements'))."\n");
  fwrite(STDOUT,'STOCK_INDEXES '.json_encode(DB::select('SHOW INDEX FROM stock_movements'))."\n");
  $triggers=DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE="stock_movements"');
  self::assertSame([],$triggers);
  fwrite(STDOUT,'STOCK_ISOLATION '.json_encode(DB::select('SELECT @@tx_isolation AS isolation_level'))."\n");
  $first=DB::table('stock_movements')->insertGetId(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>101,'movement_date'=>'2026-10-14','qty'=>10,'qty_kg'=>10,'total_cost'=>100]);
  $second=DB::table('stock_movements')->insertGetId(['company_id'=>2,'branch_id'=>2,'item_id'=>3,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>202,'movement_date'=>'2026-10-14','qty'=>10,'qty_kg'=>10,'total_cost'=>100]);
  foreach([false,true] as $indexed){
   if($indexed)DB::statement('CREATE INDEX idx_stock_company_source ON stock_movements(company_id,source_type,source_id)');
   $plan=DB::select('EXPLAIN '.$sql);fwrite(STDOUT,'STOCK_PLAN '.json_encode(['indexed'=>$indexed,'plan'=>$plan])."\n");
   self::assertSame($indexed?'idx_stock_company_source':'PRIMARY',$plan[0]->key);
   for($round=1;$round<=3;$round++){
    DB::table('stock_movements')->where('id',$second)->update(['journal_entry_id'=>null]);
    DB::beginTransaction();DB::table('stock_movements')->where('id',$first)->lockForUpdate()->first();
    $holder=(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $p=new Process([PHP_BINARY,base_path('scripts/stock_scope_lock_probe.php')],base_path());$p->setTimeout(12);$p->start();
    try{
     $this->await($p,'QUERY_STARTED');
     if(!$indexed){
      $wait=[];$end=microtime(true)+1.5;
      do{$wait=DB::select('SELECT r.trx_mysql_thread_id AS waiter,b.trx_mysql_thread_id AS holder,r.trx_query,l.lock_table,l.lock_index,l.lock_type,l.lock_mode,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE b.trx_mysql_thread_id=?',[$holder]);if(!$wait)usleep(10000);}while(!$wait&&microtime(true)<$end);
      self::assertNotEmpty($wait);self::assertSame('PRIMARY',$wait[0]->lock_index);self::assertSame((string)$first,$wait[0]->lock_data);
      fwrite(STDOUT,'STOCK_LOCK_WAIT '.json_encode(['round'=>$round,'company1_locked_row'=>$first,'company2_target_row'=>$second,'evidence'=>$wait])."\n");
      $status=DB::select('SHOW ENGINE INNODB STATUS');self::assertStringContainsString('stock_movements',$status[0]->Status);
      $p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1205',$p->getOutput());
     }else{$this->await($p,'PROBE_COMMIT');$p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('"rows":1',$p->getOutput());}
     fwrite(STDOUT,'STOCK_QUERY_TIMING '.json_encode(['indexed'=>$indexed,'round'=>$round,'worker'=>$p->getOutput()])."\n");
    }finally{DB::rollBack();if($p->isRunning())$p->wait();}
    self::assertNull(DB::table('stock_movements')->where('id',$first)->value('journal_entry_id'));
    self::assertEquals($indexed?123:null,DB::table('stock_movements')->where('id',$second)->value('journal_entry_id'));
   }
  }
  // Write overhead microbenchmark, synthetic stock rows only, rollback every batch.
  foreach([false,true] as $indexed){
   DB::statement('DROP INDEX idx_stock_company_source ON stock_movements');if($indexed)DB::statement('CREATE INDEX idx_stock_company_source ON stock_movements(company_id,source_type,source_id)');
   $samples=[];
   for($i=0;$i<5;$i++){
    $rows=array_fill(0,500,['company_id'=>1,'branch_id'=>1,'item_id'=>1,'movement_type'=>'IN','source_type'=>'BENCH','source_id'=>303,'movement_date'=>'2026-10-14','qty'=>1,'qty_kg'=>1,'total_cost'=>2]);
    DB::beginTransaction();$start=microtime(true);DB::table('stock_movements')->insert($rows);$insert=(microtime(true)-$start)*1000;
    $start=microtime(true);$n=DB::table('stock_movements')->where('company_id',1)->where('source_type','BENCH')->where('source_id',303)->update(['journal_entry_id'=>123]);$update=(microtime(true)-$start)*1000;self::assertSame(500,$n);DB::rollBack();$samples[]=compact('insert','update');
   }
   fwrite(STDOUT,'STOCK_WRITE_BENCH '.json_encode(['indexed'=>$indexed,'batch_rows'=>500,'samples'=>$samples])."\n");
   if(!$indexed)DB::statement('CREATE INDEX idx_stock_company_source ON stock_movements(company_id,source_type,source_id)');
  }
 }
}
