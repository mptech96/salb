<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;
class ShipmentPurchaseLockGateTest extends \Tests\TestCase
{
 private array $observedWorkers=[];
 protected function tearDown():void{
  $times=[];foreach($this->observedWorkers as $p){preg_match_all('/COMPANY_LOCK_MS ([0-9.]+)/',$p->getOutput(),$m);foreach($m[1] as $time)$times[]=(float)$time;}
  if($times){sort($times);$pct=fn($p)=>$times[max(0,(int)ceil(count($times)*$p)-1)];fwrite(STDOUT,'BATCH_COMPANY_QUERY_METRICS '.json_encode(['samples'=>count($times),'p50_ms'=>$pct(.5),'p95_ms'=>$pct(.95),'p99_ms'=>$pct(.99)])."\n");}parent::tearDown();
 }
 protected function setUp():void{
  parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $f)require_once __DIR__.'/../../Support/'.$f.'.php';\Tests\Support\UomStageAProof::guard();
  DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $r){$t=array_values((array)$r)[0];DB::unprepared('DROP TABLE `'.$t.'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
 }
 protected function worker(string $kind,int $id,bool $prototype=false,string $phase=''):Process{
  $p=new Process([PHP_BINARY,base_path('scripts/shipment_purchase_lock_worker.php'),$kind,(string)$id,$prototype?'prototype':'current',$phase],base_path());$p->setTimeout(25);$p->start();$this->observedWorkers[]=$p;return $p;
 }
 protected function await(Process $p,string $stage):void{
  $end=microtime(true)+10;while(!str_contains($p->getOutput(),$stage)&&$p->isRunning()&&microtime(true)<$end)usleep(10000);self::assertStringContainsString($stage,$p->getOutput(),$p->getErrorOutput());
 }
 protected function postInvoice(int $id):void{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1);}
 protected function balanced():void{foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')));}
 protected function financial(array $s):void{
  $lot=DB::table('inventory_lots')->where('purchase_invoice_id',$s['invoice'])->first();self::assertEquals(10,$lot->qty_remaining_kg);self::assertEquals(120,$lot->total_cost);self::assertEquals(20,$lot->allocated_cost);
  $cost=DB::table('shipment_costs')->where('id',$s['cost'])->first();self::assertSame('POSTED',$cost->cost_status);self::assertEquals(1,$cost->distributed);$v=DB::table('vouchers')->where('id',$cost->voucher_id)->first();self::assertSame($cost->journal_entry_id,$v->journal_entry_id);self::assertStringStartsWith('PAY-2026-',$v->voucher_number);self::assertEquals(20,$v->amount);$this->balanced();
  $journals=[DB::table('purchase_invoices')->where('id',$s['invoice'])->value('journal_entry_id'),$cost->journal_entry_id];
  $lines=fn()=>DB::table('journal_entry_lines')->whereIn('journal_entry_id',$journals);self::assertEquals(120,$lines()->where('account_id',11)->sum('debit'));self::assertEquals(100,$lines()->where('account_id',12)->sum('credit'));self::assertEquals(20,$lines()->where('account_id',14)->sum('credit'));
 }
 public function test_real_linked_purchase_posts_inventory_freight_and_existing_pay_voucher():void{
  $s=F::purchase();$this->postInvoice($s['invoice']);$this->financial($s);self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());$this->postInvoice($s['invoice']);self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('inventory_lots')->count());
 }
 private function conflict(bool $prototype,bool $costPaid=false):void{
  for($i=0;$i<3;$i++){
   $s=F::purchase();$other=F::shipment(false,$costPaid,2);$key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$this->worker('cost',$other['shipment'],false,'before-year');$b=null;
   try{$this->await($a,'BEFORE_YEAR');$b=$this->worker('purchase',$s['invoice'],$prototype);$this->await($b,'from `companies`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
   $output=$a->getOutput().$b->getOutput();
   self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertStringNotContainsString('1213',$output);
   // The full failed source is retried, never an isolated accounting fragment.
   app(\App\Services\ShipmentCostService::class)->postPendingForShipment(1,$other['shipment'],1);$this->postInvoice($s['invoice']);$this->financial($s);self::assertSame(($i+1)*3,DB::table('journal_entries')->count());self::assertSame(($i+1)*($costPaid?2:1),DB::table('vouchers')->count());
  }
 }
 public function test_repaired_services_remove_previous_company_year_cycle():void{$this->conflict(false);}
 public function test_repaired_paid_cost_service_and_pay_voucher_cycle():void{$this->conflict(false,true);}
 public function test_legacy_cli_alias_exercises_same_actual_repaired_service():void{$this->conflict(true);}
 public function test_failures_rollback_whole_linked_purchase_including_voucher():void{
  foreach(['fault-after-lot','fault-after-journal','fault-after-voucher'] as $phase){$s=F::purchase();$n=DB::table('journal_entries')->count();$lots=DB::table('inventory_lots')->count();$v=DB::table('vouchers')->count();$sequences=DB::table('sulb_document_sequences')->orderBy('id')->get()->toJson();$p=$this->worker('purchase',$s['invoice'],true,$phase);$p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('FAULT_',$p->getOutput());self::assertSame($n,DB::table('journal_entries')->count());self::assertSame($lots,DB::table('inventory_lots')->count());self::assertSame($v,DB::table('vouchers')->count());self::assertSame($sequences,DB::table('sulb_document_sequences')->orderBy('id')->get()->toJson());self::assertSame('DRAFT',DB::table('shipment_costs')->where('id',$s['cost'])->value('cost_status'));self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$s['invoice'])->value('document_status'));self::assertSame('READY',DB::table('shipments')->where('id',$s['shipment'])->value('commercial_status'));$this->postInvoice($s['invoice']);$this->postInvoice($s['invoice']);$this->financial($s);}
 }
 public function test_linked_purchase_against_simple_opening_and_other_linked_purchase():void{
  foreach(['simple','opening','linked'] as $kind){for($i=0;$i<3;$i++){
   $s=F::purchase();$otherShipment=$kind==='linked'?F::purchase():null;$id=$kind==='opening'?\Tests\Support\UomOperationalLockFixture::opening(1,2):($otherShipment['invoice']??\Tests\Support\UomOperationalLockFixture::purchase(1,2));
   $key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$this->worker('purchase',$s['invoice'],true,'before-year');$b=null;
   try{$this->await($a,'BEFORE_YEAR');$b=$this->worker($kind==='opening'?'opening':'purchase',$id,$kind==='linked');$this->await($b,$kind==='linked'?'from `companies`':'from `sulb_document_sequences`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
   self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());$this->financial($s);if($otherShipment)$this->financial($otherShipment);$this->balanced();
  }}
 }
 public function test_company_lock_timeout_rolls_back_linked_invoice_then_retry():void{
  $s=F::purchase();DB::beginTransaction();DB::table('companies')->where('id',1)->lockForUpdate()->first();
  try{$p=$this->worker('purchase',$s['invoice']);$this->await($p,'from `companies`');$p->wait();self::assertStringContainsString('1205',$p->getOutput());}finally{DB::rollBack();}
  self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('vouchers')->count());$this->postInvoice($s['invoice']);$this->financial($s);
 }
}
