<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Readiness proofs only; inherits relevant actual index/HTTP concurrency regression. */
class InventoryBatchTwoSafetyGateTest extends StockMovementSourceIndexProofTest
{
 private function fresh():\PDO{
  return new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName().';charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
 }
 public function test_commit_ack_loss_requires_fresh_authoritative_reconciliation():void{
  foreach(['pass','drop-before','drop-after']as$mode){
   $s=F::purchase();$beforeJ=DB::table('journal_entries')->count();$beforeV=DB::table('vouchers')->count();$beforeL=DB::table('inventory_lots')->count();
   $proxy=new Process([PHP_BINARY,base_path('scripts/isolated_commit_packet_proxy.php'),$mode],base_path());$proxy->setTimeout(35);$proxy->start();$this->await($proxy,'PROXY_PORT');preg_match('/PROXY_PORT (\d+)/',$proxy->getOutput(),$m);
   $worker=new Process([PHP_BINARY,base_path('scripts/isolated_commit_post_worker.php'),(string)$s['invoice'],$m[1]],base_path());$worker->setTimeout(30);
   try{$worker->run();$proxy->wait();}finally{if($worker->isRunning())$worker->stop();if($proxy->isRunning())$proxy->stop();}
   self::assertSame(0,$proxy->getExitCode(),$proxy->getErrorOutput());
   self::assertStringContainsString($mode==='pass'?'COMPLETED':'UNKNOWN_TO_CALLER',$worker->getOutput());
   self::assertSame($mode==='pass'?0:1,$worker->getExitCode(),$worker->getOutput());
   self::assertStringContainsString(match($mode){'pass'=>'COMMIT_OK_DELIVERED','drop-before'=>'COMMIT_NOT_FORWARDED','drop-after'=>'SERVER_COMMIT_OK_RESPONSE_DROPPED'},$proxy->getOutput());
   // NEW connection, trusted persisted company/branch/source identity. No blind re-post.
   $fresh=$this->fresh();$fresh->beginTransaction();$q=$fresh->prepare('SELECT document_status,journal_entry_id FROM purchase_invoices WHERE company_id=1 AND branch_id=1 AND id=? FOR UPDATE');$q->execute([$s['invoice']]);$invoice=$q->fetch(\PDO::FETCH_ASSOC);
   if($mode==='drop-before'){
    self::assertSame('DRAFT',$invoice['document_status']);self::assertNull($invoice['journal_entry_id']);
    self::assertSame($beforeJ,DB::table('journal_entries')->count());self::assertSame($beforeV,DB::table('vouchers')->count());self::assertSame($beforeL,DB::table('inventory_lots')->count());
    $cost=$fresh->query('SELECT cost_status,journal_entry_id,voucher_id FROM shipment_costs WHERE id='.(int)$s['cost'])->fetch(\PDO::FETCH_ASSOC);self::assertSame('DRAFT',$cost['cost_status']);self::assertNull($cost['journal_entry_id']);self::assertNull($cost['voucher_id']);
    $fresh->commit();$this->postInvoice($s['invoice']); // Only after independent confirmed no-effect evidence.
   }else{
    self::assertSame('POSTED',$invoice['document_status']);self::assertNotNull($invoice['journal_entry_id']);
    $j=$fresh->prepare('SELECT status FROM journal_entries WHERE company_id=1 AND source_type="PURCHASE" AND source_id=? AND id=?');$j->execute([$s['invoice'],$invoice['journal_entry_id']]);self::assertSame('POSTED',$j->fetchColumn());
    self::assertEquals(120,$fresh->query('SELECT total_cost FROM inventory_lots WHERE company_id=1 AND purchase_invoice_id='.(int)$s['invoice'])->fetchColumn());
    $cost=$fresh->query('SELECT c.cost_status,c.distributed,c.journal_entry_id,c.voucher_id,v.journal_entry_id AS voucher_journal FROM shipment_costs c JOIN vouchers v ON v.id=c.voucher_id AND v.company_id=c.company_id WHERE c.id='.(int)$s['cost'])->fetch(\PDO::FETCH_ASSOC);
    self::assertSame('POSTED',$cost['cost_status']);self::assertEquals(1,$cost['distributed']);self::assertSame($cost['journal_entry_id'],$cost['voucher_journal']);
    // Proposed resolver returns existing result; additionally exercise current repeated post.
    $fresh->commit();$this->postInvoice($s['invoice']);
   }
   $this->financial($s);self::assertSame($beforeJ+2,DB::table('journal_entries')->count());self::assertSame($beforeV+1,DB::table('vouchers')->count());self::assertSame($beforeL+1,DB::table('inventory_lots')->count());
   // Two concurrent deliveries use the same persisted operation identity.
   $a=$this->worker('purchase',$s['invoice']);$b=$this->worker('purchase',$s['invoice']);$a->wait();$b->wait();self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());
   self::assertSame($beforeJ+2,DB::table('journal_entries')->count());self::assertSame($beforeV+1,DB::table('vouchers')->count());self::assertSame($beforeL+1,DB::table('inventory_lots')->count());
   fwrite(STDOUT,'COMMIT_BOUNDARY '.json_encode(['mode'=>$mode,'proxy'=>$proxy->getOutput(),'caller'=>$worker->getOutput(),'fresh_invoice'=>$invoice,'journals_added'=>2,'pay_added'=>1,'lots_added'=>1])."\n");
  }
 }
 public function test_candidate_build_on_existing_rows_and_equivalent_index_contract():void{
  $migration=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');$migration->down();
  foreach([1,2]as$company){$rows=array_fill(0,1000,['company_id'=>$company,'branch_id'=>$company,'item_id'=>$company===1?1:3,'movement_type'=>'IN','source_type'=>'INDEX_PROOF','source_id'=>919,'movement_date'=>'2026-10-14','qty'=>1,'qty_kg'=>1,'total_cost'=>2]);DB::table('stock_movements')->insert($rows);}
  DB::table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'movement_type'=>'IN','source_type'=>'INDEX_PROOF','source_id'=>920,'movement_date'=>'2026-10-14','qty'=>1,'qty_kg'=>1,'total_cost'=>2]);
  $before=hash('sha256',DB::table('stock_movements')->orderBy('id')->get()->toJson());$start=microtime(true);$migration->up();$ms=(microtime(true)-$start)*1000;$migration->up();
  self::assertSame($before,hash('sha256',DB::table('stock_movements')->orderBy('id')->get()->toJson()));
  $widePlan=DB::select('EXPLAIN UPDATE stock_movements SET journal_entry_id=77 WHERE company_id=1 AND source_type="INDEX_PROOF" AND source_id=919');
  // A secondary index does not force its use for a 50%-of-table UPDATE.
  self::assertStringContainsString('idx_stock_company_source',$widePlan[0]->possible_keys??'');
  $plan=DB::select('EXPLAIN UPDATE stock_movements SET journal_entry_id=77 WHERE company_id=1 AND source_type="INDEX_PROOF" AND source_id=920');self::assertSame('idx_stock_company_source',$plan[0]->key);
  self::assertSame(1,DB::table('stock_movements')->where('company_id',1)->where('source_type','INDEX_PROOF')->where('source_id',920)->update(['journal_entry_id'=>78]));
  self::assertSame(1000,DB::table('stock_movements')->where('company_id',1)->where('source_type','INDEX_PROOF')->where('source_id',919)->update(['journal_entry_id'=>77]));self::assertSame(0,DB::table('stock_movements')->where('company_id',2)->whereNotNull('journal_entry_id')->count());
  $migration->down();DB::statement('CREATE INDEX equivalent_stock_source ON stock_movements(company_id,source_type,source_id,id)');$migration->up();self::assertCount(0,DB::select('SHOW INDEX FROM stock_movements WHERE Key_name="idx_stock_company_source"'));$migration->down();self::assertCount(4,DB::select('SHOW INDEX FROM stock_movements WHERE Key_name="equivalent_stock_source"'));
  DB::statement('DROP INDEX equivalent_stock_source ON stock_movements');DB::statement('CREATE INDEX idx_stock_company_source ON stock_movements(source_id)');
  try{$migration->up();self::fail('Conflicting index accepted');}catch(\RuntimeException $e){self::assertStringContainsString('Conflicting stock index',$e->getMessage());}
  fwrite(STDOUT,'EXISTING_DATA_INDEX '.json_encode(['rows'=>2001,'build_ms'=>$ms,'selective_plan'=>$plan,'wide_plan'=>$widePlan,'financial_rows_changed_by_ddl'=>0])."\n");
 }
}
