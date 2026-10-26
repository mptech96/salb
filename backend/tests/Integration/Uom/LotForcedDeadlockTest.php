<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\UomOperationalLockFixture as F;
class LotForcedDeadlockTest extends LotSequencePreparationTest
{
 public function test_1213_victim_is_real_complete_purchase_transaction_then_full_retry():void{
  $id=F::purchase(1,1);DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();
  // Deliberately emulate an out-of-protocol writer. Its heavier undo log makes
  // the real purchase worker the expected InnoDB victim. No business service mock.
  for($n=0;$n<50;$n++)DB::table('items')->insert(['company_id'=>1,'item_code'=>'DEADLOCK-'.$n,'item_name'=>'Disposable lock weight']);
  $p=new Process([PHP_BINARY,base_path('scripts/uom_operational_lock_worker.php'),'purchase','1',(string)$id,'legacy',''],base_path());$p->setTimeout(20);$p->start();
  try{$end=microtime(true)+10;while(!str_contains($p->getOutput(),'select * from `items`')&&$p->isRunning()&&microtime(true)<$end)usleep(10000);self::assertStringContainsString('select * from `items`',$p->getOutput());
   DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->lockForUpdate()->first();
  }finally{if(DB::transactionLevel()>0)DB::rollBack();}
  $p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1213',$p->getOutput());self::assertStringNotContainsString('COMMITTED',$p->getOutput());
  self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$id)->value('document_status'));
  $status=DB::selectOne('SHOW ENGINE INNODB STATUS')->Status;self::assertStringContainsString('LATEST DETECTED DEADLOCK',$status);self::assertStringContainsString('sulb_document_sequences',$status);
  app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1);app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1);self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(1,DB::table('inventory_lots')->count());self::assertEquals(0,DB::table('journal_entry_lines')->sum(DB::raw('debit-credit')));
 }
}
