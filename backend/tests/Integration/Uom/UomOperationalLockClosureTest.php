<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\UomOperationalLockFixture as F;
class UomOperationalLockClosureTest extends \Tests\TestCase
{
 private array $scopeSamples=[];private array $pairDurations=[];
 protected function tearDown():void{
  if($this->scopeSamples){sort($this->scopeSamples);$pct=fn($p)=>$this->scopeSamples[max(0,(int)ceil(count($this->scopeSamples)*$p)-1)];fwrite(STDOUT,'LOT_PAIR_METRICS '.json_encode(['pairs'=>count($this->pairDurations),'query_samples'=>count($this->scopeSamples),'scope_query_ms'=>['p50'=>$pct(.5),'p95'=>$pct(.95),'p99'=>$pct(.99)],'closed_loop_posts_per_second'=>count($this->pairDurations)*2/array_sum($this->pairDurations)])."\n");}
  parent::tearDown();
 }
 protected function setUp():void{
  parent::setUp();require_once __DIR__.'/../../Support/UomStageAProof.php';require_once __DIR__.'/../../Support/UomOperationalLockFixture.php';\Tests\Support\UomStageAProof::guard();
  DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $r){$t=array_values((array)$r)[0];DB::unprepared('DROP TABLE `'.$t.'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
 }
 private function spawn(string $kind,int $company,int $id,string $phase=''):Process{
  $p=new Process([PHP_BINARY,base_path('scripts/uom_operational_lock_worker.php'),$kind,(string)$company,(string)$id,'legacy',$phase],base_path());$p->setTimeout(25);$p->start();return $p;
 }
 private function await(Process $p,string $text):void{
  $end=microtime(true)+10;while(!str_contains($p->getOutput(),$text)&&$p->isRunning()&&microtime(true)<$end)usleep(10000);self::assertStringContainsString($text,$p->getOutput(),$p->getErrorOutput());
 }
 private function barrier(bool $hold):void{
  $key=DB::connection()->getDatabaseName().'-barrier';self::assertEquals(1,DB::selectOne($hold?'SELECT GET_LOCK(?,1) AS ok':'SELECT RELEASE_LOCK(?) AS ok',[$key])->ok);
 }
 private function postDocument(string $kind,int $company,int $id):void{
  if($kind==='purchase')app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',$company,$id,$company,$company);
  else app(\App\Services\OpeningBalanceService::class)->post($company,$id,$company,true,$company);
 }
 private function balanced():void{
  foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')));
 }
 private function pair(string $ka,string $kb,int $itemA=1,int $itemB=1,int $companyB=1):void{
  $aId=F::{$ka}(1,$itemA);$bId=F::{$kb}($companyB,$itemB);$count=DB::table('journal_entries')->count();$this->barrier(true);$started=microtime(true);$a=$this->spawn($ka,1,$aId,'before-lot');$b=null;
  try{$this->await($a,'BEFORE_LOT');$b=$this->spawn($kb,$companyB,$bId);$this->await($b,'`sulb_document_sequences`');
   if($companyB===1){self::assertStringNotContainsString('COMMITTED',$b->getOutput());self::assertSame($count,DB::table('journal_entries')->count());}
   else{$b->wait();self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertTrue($a->isRunning());}
  }finally{$this->barrier(false);$a->wait();if($b)$b->wait();}
  self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());
  $this->pairDurations[]=microtime(true)-$started;preg_match_all('/LOT_WAIT_MS ([0-9.]+)/',$a->getOutput().$b->getOutput(),$timings);foreach($timings[1] as $time)$this->scopeSamples[]=(float)$time;
  self::assertSame($count+2,DB::table('journal_entries')->count());self::assertSame($count+2,DB::table('inventory_lots')->count());
  self::assertEquals(($count+2)*10,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertEquals(($count+2)*100,DB::table('inventory_lots')->sum('total_cost'));
  self::assertSame($count+2,DB::table('stock_movements')->count());self::assertSame($count+2,DB::table('inventory_lot_movements')->count());
  self::assertSame($count+2,DB::table('inventory_lots')->select('company_id','lot_number')->distinct()->get()->count());$this->balanced();
  $this->postDocument($ka,1,$aId);$this->postDocument($kb,$companyB,$bId);self::assertSame($count+2,DB::table('journal_entries')->count());
 }
 public function test_purchase_opening_both_orders_same_item_legacy():void{
  for($i=0;$i<5;$i++){$this->pair('purchase','opening');$this->pair('opening','purchase');}
 }
 public function test_purchase_opening_both_orders_same_item_with_uom_guard():void{
  config(['uom.schema_install_approved'=>true]);$m=require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php');$m->up();
  for($i=0;$i<5;$i++){$this->pair('purchase','opening');$this->pair('opening','purchase');}
 }
 public function test_purchases_openings_different_items_and_companies():void{
  for($i=0;$i<5;$i++){$this->pair('purchase','purchase');$this->pair('opening','opening');$this->pair('purchase','opening',1,2);$this->pair('opening','purchase',1,3,2);}
 }
 public function test_faults_after_lot_and_journal_rollback_reservation_and_retry():void{
  foreach([['purchase','fault-after-lot'],['purchase','fault-after-journal'],['opening','fault-after-lot'],['opening','fault-before-lot']] as [$kind,$phase]){
   $id=F::{$kind}(1,1);$before=DB::table('journal_entries')->count();$sequence=DB::table('sulb_document_sequences')->where('document_type','INVENTORY_LOT')->value('next_number');$p=$this->spawn($kind,1,$id,$phase);$p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('INJECTED_',$p->getOutput());
   self::assertSame($before,DB::table('journal_entries')->count());self::assertSame($before,DB::table('inventory_lots')->count());self::assertEquals($sequence,DB::table('sulb_document_sequences')->where('document_type','INVENTORY_LOT')->value('next_number'));
   $this->postDocument($kind,1,$id);$this->postDocument($kind,1,$id);self::assertSame($before+1,DB::table('journal_entries')->count());$this->balanced();
  }
 }
 public function test_timeout_and_cancel_waiting_transaction_leave_no_partial_result():void{
  $pid=F::purchase(1,1);DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();
  try{$p=$this->spawn('purchase',1,$pid);$this->await($p,'select * from `items`');$p->wait();self::assertStringContainsString('1205',$p->getOutput());}finally{DB::rollBack();}
  self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertEquals(1,DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->value('next_number'));$this->postDocument('purchase',1,$pid);
  $oid=F::opening(1,1);DB::beginTransaction();\App\Services\InventoryPostingLocks::reserveLotScope(1,'2026-10-14');
  try{$p=$this->spawn('opening',1,$oid);$this->await($p,'`sulb_document_sequences`');$p->stop(0);}finally{DB::rollBack();}
  self::assertSame(1,DB::table('journal_entries')->count());self::assertSame('DRAFT',DB::table('opening_balance_batches')->where('id',$oid)->value('status'));$this->postDocument('opening',1,$oid);$this->balanced();
 }
 public function test_historical_numbers_and_fifo_are_unchanged():void{
  $lot=app(\App\Services\InventoryLotService::class);DB::transaction(fn()=>$lot->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>100,'base_cost'=>200,'received_at'=>'2026-01-01','lot_number'=>'LOT-2026-000002']));
  // Test-only preflight after loading representative history, before any posting.
  DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->update(['next_number'=>3]);
  $before=(array)DB::table('inventory_lots')->first();$this->postDocument('purchase',1,F::purchase(1,1));self::assertEquals($before,(array)DB::table('inventory_lots')->where('id',$before['id'])->first());self::assertSame('LOT-2026-000003',DB::table('inventory_lots')->orderByDesc('id')->value('lot_number'));
  $result=DB::transaction(fn()=>$lot->consumeFifo(1,1,1,105,'SALE',555));self::assertEquals(250,$result['total_cost']);self::assertEquals([100,5],array_column($result['allocations'],'qty_kg'));self::assertEquals(5,DB::table('inventory_lots')->sum('qty_remaining_kg'));
 }
 public function test_mode_assignment_races_real_first_posting_without_bypassing_guard():void{
  config(['uom.schema_install_approved'=>true]);$m=require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php');$m->up();
  $pid=F::purchase(1,1);$this->barrier(true);$a=$this->spawn('purchase',1,$pid,'before-lot');$b=null;
  try{$this->await($a,'BEFORE_LOT');$b=$this->spawn('mode',1,1);$this->await($b,'MODE_INSERT');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}finally{$this->barrier(false);$a->wait();if($b)$b->wait();}
  self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(1,$b->getExitCode());self::assertStringContainsString('UOM_NEW_ITEM_REQUIRED',$b->getOutput());self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(0,DB::table('uom_item_modes')->where('item_id',1)->count());$this->balanced();
  DB::table('uom_item_modes')->insert(['company_id'=>1,'item_id'=>2,'mode'=>'BASE_ONLY']);
  try{DB::transaction(fn()=>F::purchase(1,2));self::fail('BASE_ONLY operational document must remain closed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('UOM_OPERATIONAL_WRITES_NOT_APPROVED',$e->getMessage());}
  self::assertSame(1,DB::table('purchase_invoices')->count());self::assertSame(1,DB::table('inventory_lots')->count());
 }
 public function test_unprepared_and_corrupt_sequence_fail_closed_without_partial_stock():void{
  DB::table('sulb_document_sequences')->where('document_type','INVENTORY_LOT')->where('company_id',1)->delete();$pid=F::purchase(1,1);
  try{$this->postDocument('purchase',1,$pid);self::fail('Unprepared sequence accepted');}catch(\RuntimeException $e){self::assertStringContainsString('تهيئة',$e->getMessage());}
  self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());
  DB::table('sulb_document_sequences')->insert(['company_id'=>1,'branch_id'=>0,'document_type'=>'INVENTORY_LOT','document_year'=>2026,'next_number'=>1]);$this->postDocument('purchase',1,$pid);
  DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->update(['next_number'=>1]);$other=F::purchase(1,1);
  try{$this->postDocument('purchase',1,$other);self::fail('Corrupt sequence accepted');}catch(\Illuminate\Database\QueryException $e){self::assertEquals(1062,$e->errorInfo[1]);}
  self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(1,DB::table('inventory_lots')->count());self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$other)->value('document_status'));
 }
}
