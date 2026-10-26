<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Same former cycle schedule; now asserts shipment-first serialization. */
class BatchTwoExternalLockBoundaryTest extends \Tests\TestCase
{
 protected function setUp():void{
  parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture']as$f)require_once __DIR__.'/../../Support/'.$f.'.php';\Tests\Support\UomStageAProof::guard();
  DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES')as$r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
  $m=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');$m->up();
 }
 private function stage(Process $p,string $stage):void{
  $end=microtime(true)+12;while($p->isRunning()&&!str_contains($p->getOutput(),$stage)&&microtime(true)<$end)usleep(10000);
  self::assertStringContainsString($stage,$p->getOutput(),$p->getErrorOutput());
 }
 public function test_real_purchase_void_and_cost_service_serialize_without_old_cycle():void{
  for($round=1;$round<=3;$round++){
   $s=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
   $key=DB::connection()->getDatabaseName().'-void-gate';self::assertEquals(1,DB::selectOne('SELECT GET_LOCK(?,1) AS ok',[$key])->ok);
   $a=new Process([PHP_BINARY,base_path('scripts/batch_two_void_cost_probe.php'),'void',(string)$s['invoice']],base_path());$a->setTimeout(25);$b=null;$wait=[];$started=microtime(true);
   try{
    $a->start();$this->stage($a,'VOID_OWNS_COST_BEFORE_YEAR');preg_match('/CONNECTION (\d+)/',$a->getOutput(),$holder);
    $b=new Process([PHP_BINARY,base_path('scripts/batch_two_void_cost_probe.php'),'cost',(string)$s['shipment']],base_path());$b->setTimeout(25);$b->start();$this->stage($b,'COST_REQUEST_SHIPMENT');
    $end=microtime(true)+3;
    // Leave MariaDB's shared trx-I_S cache idle >100ms between samples.
    do{$wait=DB::select('SELECT r.trx_query,l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE b.trx_mysql_thread_id=?',[(int)$holder[1]]);if(!$wait)usleep(150000);}while(!$wait&&microtime(true)<$end);
    self::assertNotEmpty($wait,'Cost must wait on the shipment owned by VOID before barrier release');self::assertStringContainsString('shipments',json_encode($wait));
   }finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a->isRunning())$a->wait();if($b&&$b->isRunning())$b->wait();}
   $output=$a->getOutput().$b->getOutput();self::assertStringNotContainsString('1213',$output);self::assertStringNotContainsString('1205',$output);self::assertSame(0,$a->getExitCode(),$output);
   self::assertStringContainsString('L2_FINAL {"level":0,"pdo":false}',$a->getOutput());self::assertStringContainsString('L2_FINAL {"level":0,"pdo":false}',$b->getOutput());
   // VOID removes the receipt lot; the subsequent cost may legitimately reject
   // capitalization on that now-unreceived shipment, but must roll back its GL.
   self::assertSame($round*4,DB::table('journal_entries')->count());
   $invoice=DB::table('purchase_invoices')->where('id',$s['invoice'])->first();self::assertContains($invoice->document_status,['POSTED','VOID']);self::assertNotNull($invoice->journal_entry_id);
   foreach(DB::table('journal_entries')->pluck('id')as$j)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit')));
   self::assertSame(1,DB::table('vouchers')->where('reference_type','SHIPMENT_COST')->where('reference_id',$s['cost'])->count());self::assertSame(1,DB::table('inventory_lots')->where('purchase_invoice_id',$s['invoice'])->count());
   fwrite(STDOUT,'BATCH_TWO_EXTERNAL_CYCLE '.json_encode(['round'=>$round,'elapsed_ms'=>(microtime(true)-$started)*1000,'wait'=>$wait,'void'=>$a->getOutput(),'cost'=>$b->getOutput(),'invoice_status'=>$invoice->document_status])."\n");
  }
 }
}
