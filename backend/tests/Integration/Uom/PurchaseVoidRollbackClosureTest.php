<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;
class PurchaseVoidRollbackClosureTest extends TrustedSupportShipmentOwnershipTest
{
 private function prepared():array{$s=F::purchase();$this->postInvoice($s['invoice']);return $s;}
 private function snapshot():string{
  $r=[];foreach(['purchase_invoices','shipment_costs','shipments','shipment_items','journal_entries','journal_entry_lines','inventory_lots','inventory_lot_movements','stock_movements','vouchers','sulb_document_sequences']as$t)$r[$t]=DB::table($t)->orderBy('id')->get();return json_encode($r);
 }
 private function voidWorker(string $token,int $invoice,string $phase=''):Process{$p=new Process([PHP_BINARY,base_path('scripts/batch_two_support_void_probe.php'),(string)$invoice,$token,$phase],base_path());$p->setTimeout(20);$p->start();return $p;}
 public function test_original_http_timeout_restores_exact_financial_snapshot_three_rounds():void{
  $token=$this->token('WRITE',['POST:api/purchase-invoices/{id}/void']);
  for($i=0;$i<3;$i++){$s=$this->prepared();$before=$this->snapshot();DB::beginTransaction();DB::table('shipments')->where('id',$s['shipment'])->lockForUpdate()->first();$p=null;
   try{$p=$this->voidWorker($token,$s['invoice']);$p->wait();}finally{DB::rollBack();if($p&&$p->isRunning())$p->stop();}
   self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1205',$p->getOutput());self::assertStringContainsString('"status":422',$p->getOutput());self::assertSame($before,$this->snapshot());self::assertStringContainsString('VOID_AUDIT_STATE {"level":0,"pdo":false,"server":0}',$p->getOutput());
   self::assertStringNotContainsString('Database:',$p->getOutput());self::assertStringNotContainsString('SQLSTATE[',$p->getOutput());
   fwrite(STDOUT,'VOID_REAL_TIMEOUT_ROLLBACK '.json_encode(['round'=>$i+1,'trace'=>$p->getOutput(),'financial_snapshot'=>'identical'])."\n");
  }
 }
 public function test_fault_boundaries_restore_whole_state_and_failed_audit_is_outside_root():void{
  $token=$this->token('WRITE',['POST:api/purchase-invoices/{id}/void']);
  foreach(['cost','stock','lot','journal','1205','1213','swallow','audit']as$phase){$s=$this->prepared();$before=$this->snapshot();$p=$this->voidWorker($token,$s['invoice'],$phase);$p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertSame($before,$this->snapshot(),$phase);
   self::assertStringContainsString($phase==='audit'?'"status":500':'"status":422',$p->getOutput());if($phase!=='audit')self::assertStringContainsString('VOID_AUDIT_STATE {"level":0,"pdo":false,"server":0}',$p->getOutput());
   fwrite(STDOUT,'VOID_FAULT_ROLLBACK '.json_encode(['phase'=>$phase,'trace'=>$p->getOutput(),'financial_snapshot'=>'identical'])."\n");
  }
 }
 public function test_success_retry_repeat_and_actual_http_authorization():void{
  $s=$this->prepared();$url='/api/purchase-invoices/'.$s['invoice'].'/void';$data=['reason'=>'Isolated authorized reversal'];
  foreach([['READ_ONLY',[],1,1,403],['WRITE',[],1,1,403],['WRITE',['POST:api/purchase-invoices/{id}/void'],2,2,404]]as[$a,$cap,$c,$b,$status]){$this->app['auth']->forgetGuards();$this->withToken($this->token($a,$cap,$c,$b))->postJson($url,$data)->assertStatus($status);}
  DB::table('branches')->insert(['id'=>77,'company_id'=>1,'branch_name'=>'Denied branch']);$this->app['auth']->forgetGuards();$this->withToken($this->token('WRITE',['POST:api/purchase-invoices/{id}/void'],1,77))->postJson($url,$data)->assertNotFound();
  $token=$this->token('WRITE',['POST:api/purchase-invoices/{id}/void']);DB::table('subscriptions')->where('company_id',1)->update(['status'=>'EXPIRED','end_date'=>now()->subMonth()->toDateString()]);$this->app['auth']->forgetGuards();$this->withToken($token)->postJson($url,$data)->assertForbidden();DB::table('subscriptions')->where('company_id',1)->update(['status'=>'ACTIVE','end_date'=>now()->addYear()->toDateString()]);
  $before=$this->snapshot();$p=$this->voidWorker($token,$s['invoice'],'1205');$p->wait();self::assertSame($before,$this->snapshot());
  $this->app['auth']->forgetGuards();$this->withToken($token)->postJson($url,$data)->assertOk();self::assertSame('VOID',DB::table('purchase_invoices')->where('id',$s['invoice'])->value('document_status'));self::assertEquals(0,DB::table('inventory_lots')->where('purchase_invoice_id',$s['invoice'])->value('qty_remaining_kg'));self::assertSame(4,DB::table('journal_entries')->count());$this->balanced();
  $after=$this->snapshot();$this->app['auth']->forgetGuards();$this->withToken($token)->postJson($url,$data)->assertUnprocessable();self::assertSame($after,$this->snapshot());
 }
 public function test_owner_purpose_and_unknown_outer_transaction_are_rejected():void{
  $s=$this->prepared();$before=$this->snapshot();DB::beginTransaction();try{app(\App\Services\EnterpriseInvoiceService::class)->void('PURCHASE',1,$s['invoice'],1,'Isolated unknown outer',1);self::fail('Unknown owner accepted');}catch(\RuntimeException $e){self::assertSame('PURCHASE_VOID_OUTERMOST_REQUIRED',$e->getMessage());}finally{DB::rollBack();}self::assertSame($before,$this->snapshot());
  $o=app(\App\Services\ShipmentTransactionOwner::class);try{$o->run(1,$s['invoice'],1,\App\Services\ShipmentPurchasePostingScope::snapshot(1,$s['invoice']),fn()=>$o->scope(1,$s['invoice'],1,'PURCHASE_POST'),'PURCHASE_VOID');self::fail('Purpose mismatch accepted');}catch(\RuntimeException $e){self::assertSame('SHIPMENT_POST_OWNER_SCOPE_MISMATCH',$e->getMessage());}self::assertNull($o->scope(1,$s['invoice'],1,'PURCHASE_VOID'));
  $pdo=DB::connection()->getPdo();$pdo->beginTransaction();try{
   self::assertSame(0,DB::transactionLevel());try{$o->run(1,$s['invoice'],1,[],fn()=>self::fail('Untrusted PDO callback ran'),'PURCHASE_VOID');self::fail('Raw PDO owner accepted');}catch(\RuntimeException $e){self::assertSame('SHIPMENT_POST_OUTERMOST_REQUIRED',$e->getMessage());}self::assertTrue($pdo->inTransaction(),'Do not roll back a transaction this owner never owned');
  }finally{if($pdo->inTransaction())$pdo->rollBack();}self::assertSame($before,$this->snapshot());
 }
 public function test_actual_innodb_deadlock_victim_restores_support_void_and_retries():void{
  $s=$this->prepared();$before=$this->snapshot();$token=$this->token('WRITE',['POST:api/purchase-invoices/{id}/void']);
  DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();
  $rows=[];for($i=0;$i<500;$i++)$rows[]=['company_id'=>1,'item_code'=>'VOID-VICTIM-'.$i,'item_name'=>'Disposable deadlock weight'];DB::table('items')->insert($rows);$p=null;
  try{$p=$this->voidWorker($token,$s['invoice'],'real1213');$this->await($p,'VOID_REQUEST_ITEM_LOCK');DB::table('shipments')->where('id',$s['shipment'])->lockForUpdate()->first();}
  finally{if(DB::transactionLevel()>0)DB::rollBack();if($p&&$p->isRunning())$p->wait();}
  self::assertStringContainsString('1213',$p->getOutput());self::assertStringContainsString('"status":422',$p->getOutput());self::assertSame($before,$this->snapshot());self::assertStringContainsString('VOID_AUDIT_STATE {"level":0,"pdo":false,"server":0}',$p->getOutput());
  fwrite(STDOUT,'VOID_REAL_DEADLOCK_ROLLBACK '.$p->getOutput()."\n");
  $this->app['auth']->forgetGuards();$this->withToken($token)->postJson('/api/purchase-invoices/'.$s['invoice'].'/void',['reason'=>'Isolated full retry after real deadlock'])->assertOk();$this->balanced();
 }
}
