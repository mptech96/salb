<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\UomOperationalLockFixture as F;
use Tests\Support\LotSequencePreparationProof as P;
class LotCrossYearConcurrencyTest extends LotSequencePreparationTest
{
 public function test_different_years_finish_independently_using_real_purchase_service():void{
  DB::table('financial_years')->insert(['company_id'=>1,'year_name'=>'2025','start_date'=>'2025-01-01','end_date'=>'2025-12-31']);P::prepare(1,2025,true);$samples=[];$durations=[];
  for($i=0;$i<5;$i++){
   $aid=F::purchase(1,1);$bid=F::purchase(1,2);DB::table('purchase_invoices')->where('id',$bid)->update(['invoice_date'=>'2025-10-14']);$key=DB::connection()->getDatabaseName().'-barrier';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);
   $a=new Process([PHP_BINARY,base_path('scripts/uom_operational_lock_worker.php'),'purchase','1',(string)$aid,'legacy','before-lot'],base_path());$a->setTimeout(25);$b=null;$start=microtime(true);$a->start();
   try{$end=microtime(true)+10;while(!str_contains($a->getOutput(),'BEFORE_LOT')&&$a->isRunning()&&microtime(true)<$end)usleep(10000);self::assertStringContainsString('BEFORE_LOT',$a->getOutput());
    $b=new Process([PHP_BINARY,base_path('scripts/uom_operational_lock_worker.php'),'purchase','1',(string)$bid,'legacy',''],base_path());$b->setTimeout(15);$bstart=microtime(true);$b->run();$durations[]=(microtime(true)-$bstart)*1000;self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertTrue($a->isRunning(),'Unrelated year should finish before releasing first year');
   }finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b&&$b->isRunning())$b->stop(0);}
   self::assertSame(0,$a->getExitCode(),$a->getOutput());preg_match_all('/LOT_WAIT_MS ([0-9.]+)/',$a->getOutput().$b->getOutput(),$m);foreach($m[1] as $v)$samples[]=(float)$v;
   self::assertSame(($i+1)*2,DB::table('journal_entries')->count());self::assertEquals(($i+1)*20,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertEquals(($i+1)*200,DB::table('inventory_lots')->sum('total_cost'));
  }
  sort($samples);sort($durations);$pct=fn($a,$p)=>$a[max(0,(int)ceil(count($a)*$p)-1)];fwrite(STDOUT,'CROSS_YEAR_METRICS '.json_encode(['pairs'=>5,'scope_query_samples'=>count($samples),'scope_query_ms'=>['p50'=>$pct($samples,.5),'p95'=>$pct($samples,.95),'p99'=>$pct($samples,.99)],'independent_worker_ms'=>['p50'=>$pct($durations,.5),'p95'=>$pct($durations,.95)],'deadlocks'=>0,'timeouts'=>0,'collisions'=>0])."\n");
 }
}
